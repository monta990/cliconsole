<?php

declare(strict_types=1);

namespace GlpiPlugin\Cliconsole;

use RuntimeException;

final class AuditLogger
{
    public static function prepare(string $logFile): void
    {
        $logDir = dirname($logFile);
        if (!is_dir($logDir) || !is_writable($logDir)) {
            throw new RuntimeException('Unable to securely initialize the CLI Console audit log.');
        }

        self::ensureSecureFile($logFile);
        self::ensureSecureFile($logDir . DIRECTORY_SEPARATOR . 'cliconsole.lock');
    }

    public static function write(string $logFile, array $event): void
    {
        self::prepare($logFile);

        $lockFile = dirname($logFile) . DIRECTORY_SEPARATOR . 'cliconsole.lock';
        $lock = fopen($lockFile, 'c+');
        if ($lock === false) {
            throw new RuntimeException('Unable to securely initialize the CLI Console audit log.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to securely initialize the CLI Console audit log.');
            }

            clearstatcache(true, $logFile);
            if ((int) (filesize($logFile) ?: 0) >= RuntimeLimits::AUDIT_MAX_SIZE) {
                $rotated = $logFile . '.1';
                if (is_link($rotated) || is_file($rotated)) {
                    if (!unlink($rotated)) {
                        throw new RuntimeException('Unable to securely rotate the CLI Console audit log.');
                    }
                }

                if (!rename($logFile, $rotated)) {
                    throw new RuntimeException('Unable to securely rotate the CLI Console audit log.');
                }
                chmod($rotated, 0600);
                self::assertSecureFile($rotated);
                self::createSecureFile($logFile);
            } else {
                self::assertSecureFile($logFile);
            }

            $record = [
                'timestamp' => gmdate('c'),
                ...$event,
            ];

            $line = json_encode(
                $record,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;

            $stream = fopen($logFile, 'ab');
            if ($stream === false) {
                throw new RuntimeException('Unable to write to the CLI Console audit log.');
            }

            try {
                if (fwrite($stream, $line) !== strlen($line) || !fflush($stream)) {
                    throw new RuntimeException('Unable to write to the CLI Console audit log.');
                }
            } finally {
                fclose($stream);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function ensureSecureFile(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Unable to securely initialize the CLI Console audit log.');
        }

        if (!file_exists($path)) {
            self::createSecureFile($path);
            return;
        }

        if (!is_file($path)) {
            throw new RuntimeException('Unable to securely initialize the CLI Console audit log.');
        }

        if (!chmod($path, 0600)) {
            throw new RuntimeException('Unable to securely initialize the CLI Console audit log.');
        }
        self::assertSecureFile($path);
    }

    private static function createSecureFile(string $path): void
    {
        $oldUmask = umask(0077);
        try {
            $stream = fopen($path, 'ab');
        } finally {
            umask($oldUmask);
        }

        if ($stream === false) {
            throw new RuntimeException('Unable to securely initialize the CLI Console audit log.');
        }
        fclose($stream);

        if (!chmod($path, 0600)) {
            throw new RuntimeException('Unable to securely initialize the CLI Console audit log.');
        }
        self::assertSecureFile($path);
    }

    private static function assertSecureFile(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Unable to securely initialize the CLI Console audit log.');
        }

        $permissions = fileperms($path);
        if ($permissions === false || ($permissions & 0777) !== 0600) {
            throw new RuntimeException('Unable to securely initialize the CLI Console audit log.');
        }
    }
}
