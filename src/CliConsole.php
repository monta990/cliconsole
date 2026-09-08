<?php

declare(strict_types=1);

namespace GlpiPlugin\Cliconsole;

use CommonDBTM;
use Profile as GlpiProfile;
use RuntimeException;


final class CliConsole extends CommonDBTM
{
    private static ?bool $isSuperAdmin = null;

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
        if (self::$isSuperAdmin !== null) {
            return self::$isSuperAdmin;
        }

        $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        self::$isSuperAdmin = $profileId > 0
            && in_array($profileId, GlpiProfile::getSuperAdminProfilesId(), true);

        return self::$isSuperAdmin;
    }

    public static function getCurrentUserId(): int
    {
        return (int) ($_SESSION['glpiID'] ?? $_SESSION['glpiid'] ?? 0);
    }

    public static function assertSessionOwner(string $sessionDir): array
    {
        $metadataFile = $sessionDir . DIRECTORY_SEPARATOR . 'metadata.json';
        if (!is_file($metadataFile)) {
            throw new RuntimeException(__('Terminal session metadata is unavailable.', 'cliconsole'));
        }

        $metadata = json_decode((string) file_get_contents($metadataFile), true);
        if (!is_array($metadata)) {
            throw new RuntimeException(__('Terminal session metadata is invalid.', 'cliconsole'));
        }

        $ownerId = (int) ($metadata['user_id'] ?? 0);
        if ($ownerId <= 0 || $ownerId !== self::getCurrentUserId()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                __('This terminal session belongs to another user.', 'cliconsole')
            );
        }

        return $metadata;
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
                if ($heartbeat <= 0 || $heartbeat < ($now - RuntimeLimits::STALE_RUNNING_SESSION)) {
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

                if ($activity > 0 && $activity < ($now - RuntimeLimits::READY_SESSION_RETENTION)) {
                    self::removeSession($dir);
                }
                continue;
            }

            $activity = max(
                (int) (@filemtime($dir) ?: 0),
                (int) (@filemtime($dir . '/status.json') ?: 0),
                $heartbeat
            );

            if ($activity > 0 && $activity < ($now - RuntimeLimits::SESSION_RETENTION)) {
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
        if ($commandLine === '' || strlen($commandLine) > RuntimeLimits::MAX_COMMAND_LENGTH) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(
                __('The command is empty or exceeds the maximum allowed length.', 'cliconsole')
            );
        }

        if (preg_match('~[;&|`$<>\\\\]|\x00~', $commandLine)) {
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

        if (count($tokens) > RuntimeLimits::MAX_ARGUMENTS) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(
                __('Too many command arguments were supplied.', 'cliconsole')
            );
        }

        foreach ($tokens as $token) {
            if (str_contains($token, "\0") || strlen($token) > RuntimeLimits::MAX_ARGUMENT_LENGTH) {
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

        if ($size > RuntimeLimits::MAX_OUTPUT_SIZE) {
            // The worker enforces the hard cap; never serve more than the safe limit.
            $size = RuntimeLimits::MAX_OUTPUT_SIZE;
        }

        if ($offset < 0 || $offset > $size) {
            $offset = 0;
        }

        if ($offset === $size) {
            return ['', $size];
        }

        $length = min(RuntimeLimits::MAX_OUTPUT_CHUNK, $size - $offset);
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

        if ($phpBinary === '' || str_contains($phpBinary, "\0") || !str_starts_with($phpBinary, '/')) {
            throw new RuntimeException(
                __('Enter an absolute path to the PHP CLI executable.', 'cliconsole')
            );
        }

        $realPhp = realpath($phpBinary);
        if ($realPhp === false || !is_file($realPhp) || !is_executable($realPhp)) {
            throw new RuntimeException(
                __('The configured PHP CLI binary does not exist or is not executable.', 'cliconsole')
            );
        }

        self::validatePhpCliBinary($realPhp);

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

    public static function validatePhpCliBinary(string $phpBinary): void
    {
        PhpCliValidator::assertValid($phpBinary, GLPI_ROOT);
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
        AuditLogger::prepare($path);
        return $path;
    }

    public static function writeAudit(array $event): void
    {
        AuditLogger::write(self::getAuditLogPath(), $event);
    }

    public static function redactCommand(array $arguments): string
    {
        $redactNext = false;
        $safe = [];
        $commandName = isset($arguments[0]) ? strtolower((string) $arguments[0]) : '';

        foreach ($arguments as $index => $argument) {
            $value = (string) $argument;

            if ($redactNext) {
                $safe[] = '[REDACTED]';
                $redactNext = false;
                continue;
            }

            // glpi:config:set (and its config:set alias) accepts a sensitive
            // configuration key and its value as positional arguments.
            // Those values do not have a leading dash, so option-based
            // redaction alone cannot protect them.
            if (
                ($commandName === 'glpi:config:set' || $commandName === 'config:set')
                && $index === 2
                && isset($arguments[1])
                && self::isSensitiveName((string) $arguments[1])
            ) {
                $safe[] = '[REDACTED]';
                continue;
            }

            if ($value === '-p') {
                $safe[] = $value;
                $redactNext = true;
                continue;
            }

            if (str_starts_with($value, '-p') && !str_starts_with($value, '--') && strlen($value) > 2) {
                $safe[] = '-p[REDACTED]';
                continue;
            }

            if (preg_match('/^(--?)([^=]+)(?:=(.*))?$/', $value, $match) === 1) {
                $optionName = strtolower($match[2]);
                if (self::isSensitiveName($optionName)) {
                    $safe[] = isset($match[3])
                        ? $match[1] . $match[2] . '=[REDACTED]'
                        : $value;
                    $redactNext = !isset($match[3]);
                    continue;
                }
            }

            $safe[] = $value;
        }

        return implode(' ', $safe);
    }

    private static function isSensitiveName(string $name): bool
    {
        $name = strtolower($name);

        return str_contains($name, 'pass')
            || str_contains($name, 'secret')
            || str_contains($name, 'token')
            || str_contains($name, 'key')
            || str_contains($name, 'credential');
    }
}
