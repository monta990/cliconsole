<?php

declare(strict_types=1);

namespace GlpiPlugin\Cliconsole;

use RuntimeException;

final class PhpCliValidator
{
    public static function assertValid(string $phpBinary, string $workingDirectory): void
    {
        $process = proc_open(
            [$phpBinary, '-r', 'echo PHP_SAPI . "\\n" . PHP_BINARY;'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            throw new RuntimeException('The configured PHP CLI executable is not a valid PHP CLI interpreter.');
        }

        foreach ([1, 2] as $stream) {
            if (isset($pipes[$stream]) && is_resource($pipes[$stream])) {
                stream_set_blocking($pipes[$stream], false);
            }
        }

        $stdout = '';
        $startedAt = microtime(true);
        $running = true;

        while ((microtime(true) - $startedAt) < 3.0) {
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $data = stream_get_contents($pipes[1]);
                if ($data !== false && $data !== '') {
                    $stdout .= substr($data, 0, max(0, 4096 - strlen($stdout)));
                }
            }

            $status = proc_get_status($process);
            $running = (bool) $status['running'];
            if (!$running) {
                break;
            }
            usleep(50000);
        }

        foreach ([1, 2] as $stream) {
            if (isset($pipes[$stream]) && is_resource($pipes[$stream])) {
                if ($stream === 1) {
                    $data = stream_get_contents($pipes[$stream]);
                    if ($data !== false) {
                        $stdout .= substr($data, 0, max(0, 4096 - strlen($stdout)));
                    }
                }
                fclose($pipes[$stream]);
            }
        }

        if ($running) {
            @proc_terminate($process);
        }

        $exitCode = proc_close($process);
        $parts = preg_split('/\R/', trim($stdout), 2);
        $reportedBinary = isset($parts[1]) ? realpath(trim($parts[1])) : false;

        if (
            $exitCode !== 0
            || ($parts[0] ?? '') !== 'cli'
            || $reportedBinary === false
            || $reportedBinary !== realpath($phpBinary)
        ) {
            throw new RuntimeException('The configured PHP CLI executable is not a valid PHP CLI interpreter.');
        }
    }
}
