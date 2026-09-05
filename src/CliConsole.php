<?php

declare(strict_types=1);

namespace GlpiPlugin\Cliconsole;

use CommonDBTM;
use Profile as GlpiProfile;
use RuntimeException;

final class CliConsole extends CommonDBTM
{
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
            if ((@filemtime($dir) ?: $now) < ($now - 3600)) {
                self::removeSession($dir);
            }
        }
    }

    public static function removeSession(string $sessionDir): void
    {
        foreach (glob($sessionDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($sessionDir);
    }


    public static function parseCommand(string $commandLine): array
    {
        if ($commandLine === '' || preg_match('/[;&|`$<>\\\\]/', $commandLine)) {
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

        foreach ($tokens as $token) {
            if (str_contains($token, "\0")) {
                throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(
                    __('An invalid argument was supplied.', 'cliconsole')
                );
            }
        }

        return $tokens;
    }

    public static function readTailFromOffset(string $file, int $offset): array
    {
        if (!is_file($file)) {
            return ['', 0];
        }

        clearstatcache(true, $file);

        $size = filesize($file);
        if ($size === false) {
            return ['', 0];
        }

        if ($offset < 0 || $offset > $size) {
            $offset = 0;
        }

        if ($offset === $size) {
            return ['', $size];
        }

        $fp = fopen($file, 'rb');
        if ($fp === false) {
            return ['', $offset];
        }

        if (fseek($fp, $offset) !== 0) {
            fclose($fp);
            return ['', $offset];
        }

        $data = stream_get_contents($fp);
        fclose($fp);

        return [$data === false ? '' : $data, $size];
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
}
