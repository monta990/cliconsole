<?php

declare(strict_types=1);

namespace GlpiPlugin\Cliconsole;

use CommonDBTM;
use Profile as GlpiProfile;
use RuntimeException;

final class CliConsole extends CommonDBTM
{
    public const MAX_COMMAND_LENGTH = 4096;
    public const MAX_ARGUMENTS = 64;
    public const MAX_ARGUMENT_LENGTH = 1024;
    public const MAX_INPUT_LENGTH = 8192;
    public const MAX_QUEUE_SIZE = 65536;
    public const MAX_OUTPUT_SIZE = 5 * 1024 * 1024;
    public const MAX_OUTPUT_CHUNK = 256 * 1024;
    public const MAX_EXECUTION_TIME = 900;
    public const MAX_ACTIVE_SESSIONS = 3;
    public const SESSION_RETENTION = 21600; // 6 hours for completed sessions.
    public const READY_SESSION_RETENTION = 900; // 15 minutes for unstarted sessions.
    public const STALE_RUNNING_SESSION = 1200; // 20 minutes; worker max is 15 minutes.
    public const AUDIT_MAX_SIZE = 5 * 1024 * 1024;

    public static function getTypeName($nb = 0): string
    {
        return __('CLI Console', 'cliconsole');
    }

    public static function getMenuName($nb = 0): string
    {
        return self::getTypeName($nb);
    }

    public static function getIcon(): string
    {
        return 'ti ti-terminal-2';
    }

    public static function isSuperAdmin(): bool
    {
        $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);

        return $profileId > 0
            && in_array($profileId, GlpiProfile::getSuperAdminProfilesId(), true);
    }

    public static function getMenuContent(): array
    {
        global $CFG_GLPI;

        if (!self::isSuperAdmin()) {
            return [];
        }

        $base = rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole';

        return [
            'title' => self::getMenuName(),
            'icon'  => self::getIcon(),
            'page'  => $base . '/Console',
            'links' => [
                'search' => $base . '/Console',
                'config' => $base . '/config',
            ],
        ];
    }

    public static function getSessionBaseDir(): string
    {
        $base = defined('GLPI_VAR_DIR')
            ? GLPI_VAR_DIR . '/cliconsole'
            : GLPI_FILES_DIR . '/_plugins/cliconsole';

        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException(__('Unable to create terminal session storage.', 'cliconsole'));
        }

        @chmod($base, 0700);

        return $base;
    }

    public static function getSessionDir(string $sessionId): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $sessionId)) {
            throw new RuntimeException(__('Invalid terminal session.', 'cliconsole'));
        }

        $base = realpath(self::getSessionBaseDir());
        $dir = realpath(self::getSessionBaseDir() . DIRECTORY_SEPARATOR . $sessionId);

        if ($base === false || $dir === false || !str_starts_with($dir, $base . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(__('Terminal session not found.', 'cliconsole'));
        }

        return $dir;
    }

    public static function cleanupOldSessions(string $baseDir): void
    {
        $now = time();

        foreach (glob($baseDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!preg_match('/\A[a-f0-9]{64}\z/', basename($dir))) {
                continue;
            }

            $status = self::readStatus($dir . '/status.json');
            $state = (string) ($status['state'] ?? 'unknown');
            $heartbeat = is_file($dir . '/heartbeat') ? (int) (@filemtime($dir . '/heartbeat') ?: 0) : 0;
            $workerActive = false;
            $workerLockPath = $dir . '/worker.lock';
            if (is_file($workerLockPath)) {
                $workerLock = @fopen($workerLockPath, 'c+');
                if (is_resource($workerLock)) {
                    $workerActive = !@flock($workerLock, LOCK_EX | LOCK_NB);
                    if (!$workerActive) {
                        @flock($workerLock, LOCK_UN);
                    }
                    fclose($workerLock);
                }
            }

            if ($workerActive) {
                // A running worker owns this lock. Never delete its session files.
                continue;
            }

            if (in_array($state, ['starting', 'running'], true)) {
                // Only clean a non-locked running session after its heartbeat has
                // become stale. This covers workers that crashed unexpectedly.
                if ($heartbeat <= 0 || $heartbeat < ($now - self::STALE_RUNNING_SESSION)) {
                    self::removeSession($dir);
                }
                continue;
            }

            if ($state === 'ready') {
                $activity = max(
                    (int) (@filemtime($dir) ?: 0),
                    (int) (@filemtime($dir . '/status.json') ?: 0),
                    $heartbeat
                );

                if ($activity > 0 && $activity < ($now - self::READY_SESSION_RETENTION)) {
                    self::removeSession($dir);
                }
                continue;
            }

            $activity = max(
                (int) (@filemtime($dir) ?: 0),
                (int) (@filemtime($dir . '/status.json') ?: 0),
                $heartbeat
            );

            if ($activity > 0 && $activity < ($now - self::SESSION_RETENTION)) {
                self::removeSession($dir);
            }
        }
    }

    public static function acquireSessionAllocationLock(string $baseDir)
    {
        $lockFile = $baseDir . DIRECTORY_SEPARATOR . 'sessions.lock';
        $lock = @fopen($lockFile, 'c');
        if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException(
                __('Unable to acquire the terminal session lock.', 'cliconsole')
            );
        }

        @chmod($lockFile, 0600);
        return $lock;
    }

    public static function releaseSessionAllocationLock($lock): void
    {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Count session slots currently reserved by the plugin.
     *
     * A slot remains reserved while a session is ready, starting, or running.
     * This makes the concurrency limit apply to the whole session lifecycle,
     * not only to workers that have already started.
     */
    public static function countReservedSessions(string $baseDir): int
    {
        $count = 0;

        foreach (glob($baseDir . '/*/status.json') ?: [] as $statusFile) {
            $status = self::readStatus($statusFile);
            if (in_array(($status['state'] ?? ''), ['ready', 'starting', 'running'], true)) {
                $count++;
            }
        }

        return $count;
    }

    public static function removeSession(string $sessionDir): void
    {
        foreach (glob($sessionDir . '/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            }
        }
        @rmdir($sessionDir);
    }

    public static function parseCommand(string $commandLine): array
    {
        if ($commandLine === '' || strlen($commandLine) > self::MAX_COMMAND_LENGTH) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(
                __('The command is empty or exceeds the maximum allowed length.', 'cliconsole')
            );
        }

        if (preg_match('/[;&|`$<>\\\x00]/', $commandLine)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(
                __('Shell operators are not allowed. Enter only a GLPI console command and its arguments.', 'cliconsole')
            );
        }

        $tokens = str_getcsv($commandLine, ' ', '"', '\\');
        $tokens = array_values(array_filter(array_map('trim', $tokens), static fn ($value) => $value !== ''));

        if ($tokens === []) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(
                __('No command was provided.', 'cliconsole')
            );
        }

        if (count($tokens) > self::MAX_ARGUMENTS) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(
                __('Too many command arguments were supplied.', 'cliconsole')
            );
        }

        foreach ($tokens as $token) {
            if (str_contains($token, "\0") || strlen($token) > self::MAX_ARGUMENT_LENGTH) {
                throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(
                    __('An invalid or oversized argument was supplied.', 'cliconsole')
                );
            }
        }

        return $tokens;
    }

    /**
     * Accept both GLPI command spellings when a command exists with or without the
     * `glpi:` prefix. Exact command names always take precedence.
     */
    public static function normalizeCommand(array $arguments, string $phpBinary, string $console): array
    {
        if ($arguments === []) {
            return $arguments;
        }

        $commandName = (string) $arguments[0];
        if ($commandName === '') {
            return $arguments;
        }

        // Discover the commands from the same GLPI installation instead of
        // relying on a hard-coded list. This keeps custom plugin commands safe.
        $process = @proc_open(
            [$phpBinary, $console, 'list', '--raw'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            GLPI_ROOT,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            return $arguments;
        }

        foreach ([1, 2] as $stream) {
            if (isset($pipes[$stream]) && is_resource($pipes[$stream])) {
                stream_set_blocking($pipes[$stream], false);
            }
        }

        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);

        while ((microtime(true) - $startedAt) < 10) {
            foreach ([1, 2] as $stream) {
                if (!isset($pipes[$stream]) || !is_resource($pipes[$stream])) {
                    continue;
                }
                $data = stream_get_contents($pipes[$stream]);
                if ($data !== false && $data !== '') {
                    if ($stream === 1) {
                        $stdout .= substr($data, 0, max(0, 262144 - strlen($stdout)));
                    } else {
                        $stderr .= substr($data, 0, max(0, 32768 - strlen($stderr)));
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(50000);
        }

        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $data = stream_get_contents($pipes[1]);
            if ($data !== false) {
                $stdout .= substr($data, 0, max(0, 262144 - strlen($stdout)));
            }
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        if (isset($status) && $status['running']) {
            @proc_terminate($process);
        }
        @proc_close($process);

        $available = [];
        foreach (preg_split('/\R/', $stdout, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $name = preg_split('/\s+/', $line, 2)[0] ?? '';
            if ($name !== '') {
                $available[$name] = true;
            }
        }

        // Prefer an exact command name when it exists. If the exact name does
        // not exist, transparently accept the alternate GLPI spelling: with or
        // without the `glpi:` prefix. This works in both directions and avoids
        // hard-coding a list of commands.
        if (isset($available[$commandName])) {
            return $arguments;
        }

        if (str_starts_with($commandName, 'glpi:')) {
            $unprefixed = substr($commandName, 5);
            if ($unprefixed !== '' && isset($available[$unprefixed])) {
                $arguments[0] = $unprefixed;
            }
        } else {
            $prefixed = 'glpi:' . $commandName;
            if (isset($available[$prefixed])) {
                $arguments[0] = $prefixed;
            }
        }

        return $arguments;
    }

    public static function readTailFromOffset(string $file, int $offset): array
    {
        if (!is_file($file)) {
            return ['', 0];
        }

        clearstatcache(true, $file);
        $size = filesize($file);
        if ($size === false) {
            return ['', $offset];
        }

        if ($size > self::MAX_OUTPUT_SIZE) {
            // The worker enforces the hard cap; never serve more than the safe limit.
            $size = self::MAX_OUTPUT_SIZE;
        }

        if ($offset < 0 || $offset > $size) {
            $offset = 0;
        }

        if ($offset === $size) {
            return ['', $size];
        }

        $length = min(self::MAX_OUTPUT_CHUNK, $size - $offset);
        $fp = fopen($file, 'rb');
        if ($fp === false) {
            return ['', $offset];
        }

        if (fseek($fp, $offset) !== 0) {
            fclose($fp);
            return ['', $offset];
        }

        $data = stream_get_contents($fp, $length);
        fclose($fp);

        if ($data === false) {
            return ['', $offset];
        }

        return [$data, $offset + strlen($data)];
    }

    public static function readStatus(string $file): array
    {
        if (!is_file($file)) {
            return [
                'state' => 'unknown',
                'exit_code' => null,
            ];
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data)
            ? $data
            : [
                'state' => 'unknown',
                'exit_code' => null,
            ];
    }

    public static function resolveExecutables(): array
    {
        $config = \Config::getConfigurationValues('plugin:cliconsole');
        $phpBinary = trim((string) ($config['php_binary'] ?? ''));

        $realPhp = realpath($phpBinary);
        if ($realPhp === false || !is_file($realPhp) || !is_executable($realPhp)) {
            throw new RuntimeException(
                __('The configured PHP CLI binary does not exist or is not executable.', 'cliconsole')
            );
        }

        $glpiRoot = realpath(GLPI_ROOT);
        $console = realpath(GLPI_ROOT . '/bin/console');

        if ($glpiRoot === false || $console === false) {
            throw new RuntimeException(__('GLPI bin/console could not be found.', 'cliconsole'));
        }

        $binDir = $glpiRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR;
        if (!str_starts_with($console, $binDir)) {
            throw new RuntimeException(__('The detected bin/console is outside the GLPI root.', 'cliconsole'));
        }

        return [$realPhp, $console];
    }

    public static function getAuditLogPath(): string
    {
        $logDir = defined('GLPI_LOG_DIR')
            ? GLPI_LOG_DIR
            : (defined('GLPI_VAR_DIR') ? GLPI_VAR_DIR . '/_log' : GLPI_FILES_DIR . '/_log');

        if (!is_dir($logDir) && !mkdir($logDir, 0700, true) && !is_dir($logDir)) {
            throw new RuntimeException(__('Unable to create the GLPI log directory.', 'cliconsole'));
        }

        $path = rtrim($logDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cliconsole.log';

        if (!file_exists($path)) {
            @touch($path);
        }
        @chmod($path, 0600);

        return $path;
    }

    public static function writeAudit(array $event): void
    {
        $logFile = self::getAuditLogPath();
        $lockFile = dirname($logFile) . DIRECTORY_SEPARATOR . 'cliconsole.lock';
        $lock = @fopen($lockFile, 'c');
        if (!is_resource($lock)) {
            return;
        }

        if (!@flock($lock, LOCK_EX)) {
            fclose($lock);
            return;
        }

        @chmod($lockFile, 0600);
        clearstatcache(true, $logFile);
        if ((int) (@filesize($logFile) ?: 0) >= self::AUDIT_MAX_SIZE) {
            @rename($logFile, $logFile . '.1');
            @touch($logFile);
        }

        $record = [
            'timestamp' => gmdate('c'),
            ...$event,
        ];

        @file_put_contents(
            $logFile,
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        @chmod($logFile, 0600);
        @flock($lock, LOCK_UN);
        fclose($lock);
    }

    public static function redactCommand(array $arguments): string
    {
        $redactNext = false;
        $safe = [];
        $sensitive = ['password', 'secret', 'token', 'api-key', 'apikey'];

        foreach ($arguments as $argument) {
            $value = (string) $argument;
            $lower = strtolower($value);

            if ($redactNext) {
                $safe[] = '[REDACTED]';
                $redactNext = false;
                continue;
            }

            $matched = false;
            foreach ($sensitive as $name) {
                if ($lower === '--' . $name || $lower === '-' . $name) {
                    $safe[] = $value;
                    $redactNext = true;
                    $matched = true;
                    break;
                }

                if (str_starts_with($lower, '--' . $name . '=')) {
                    $safe[] = substr($value, 0, strpos($value, '=') + 1) . '[REDACTED]';
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $safe[] = $value;
            }
        }

        return implode(' ', $safe);
    }
}
