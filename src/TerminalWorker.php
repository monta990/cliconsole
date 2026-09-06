<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

if ($argc !== 8) {
    fwrite(STDERR, "Invalid worker arguments.\n");
    exit(2);
}

[, $sessionId, $sessionDir, $commandJson, $phpBinary, $console, $glpiRootArg, $auditFileArg] = $argv;

if (!preg_match('/\A[a-f0-9]{64}\z/', $sessionId)) exit(3);

$realDir = realpath($sessionDir);
if ($realDir === false || !is_dir($realDir)) exit(4);

$command = json_decode($commandJson, true);
if (!is_array($command) || $command === []) exit(5);

$php = realpath($phpBinary);
$binConsole = realpath($console);
$glpiRoot = realpath($glpiRootArg);

if ($php === false || !is_file($php) || !is_executable($php)) exit(6);
if ($glpiRoot === false || $binConsole === false || !is_file($binConsole)) exit(7);

$binDir = $glpiRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR;
if (!str_starts_with($binConsole, $binDir)) exit(8);

$stdoutFile = $realDir . '/stdout.log';
$stderrFile = $realDir . '/stderr.log';
$queueFile  = $realDir . '/input.queue';
$statusFile = $realDir . '/status.json';
$metadataFile = $realDir . '/metadata.json';
$heartbeatFile = $realDir . '/heartbeat';
$lockFile = $realDir . '/worker.lock';
$auditFile = $auditFileArg;
$auditLockFile = $auditFileArg !== '' ? dirname($auditFileArg) . '/cliconsole.lock' : '';

// The controller creates the marker atomically. The worker keeps an exclusive
// filesystem lock for its complete lifetime so the session cannot be reused.
$workerLock = @fopen($lockFile, 'c+');
if (!is_resource($workerLock) || !@flock($workerLock, LOCK_EX | LOCK_NB)) {
    if (is_resource($workerLock)) {
        fclose($workerLock);
    }
    exit(9);
}
@chmod($lockFile, 0600);

$startedAt = microtime(true);
$terminated = false;
$timeout = false;
$resourceLimit = false;
$stdinClosed = false;

$writeStatus = static function (string $state, ?int $exitCode = null) use ($statusFile, $startedAt): void {
    @file_put_contents(
        $statusFile,
        json_encode([
            'state' => $state,
            'exit_code' => $exitCode,
            'started_at' => (int) $startedAt,
            'finished_at' => in_array($state, ['finished', 'error', 'terminated', 'timeout', 'resource_limit'], true) ? time() : null,
        ], JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
};

$heartbeat = static function () use ($heartbeatFile): void {
    @touch($heartbeatFile);
    @chmod($heartbeatFile, 0600);
};

$appendCapped = static function (string $file, string $data): bool {
    if ($data === '') {
        return true;
    }

    clearstatcache(true, $file);
    $size = (int) (@filesize($file) ?: 0);
    $remaining = 5242880 - $size;
    if ($remaining <= 0) {
        return false;
    }

    if (strlen($data) > $remaining) {
        $data = substr($data, 0, $remaining);
    }

    @file_put_contents($file, $data, FILE_APPEND | LOCK_EX);
    return strlen($data) === $remaining ? false : true;
};

$writeAudit = static function (array $event) use ($auditFile, $auditLockFile): void {
    if ($auditFile === '' || $auditLockFile === '') {
        return;
    }

    $lock = @fopen($auditLockFile, 'c');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
        return;
    }

    clearstatcache(true, $auditFile);
    if ((int) (@filesize($auditFile) ?: 0) >= 5242880) {
        @rename($auditFile, $auditFile . '.1');
        @touch($auditFile);
        @chmod($auditFile . '.1', 0600);
    }

    $record = [
        'timestamp' => gmdate('c'),
        ...$event,
    ];

    @file_put_contents(
        $auditFile,
        json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    @chmod($auditFile, 0600);
    @flock($lock, LOCK_UN);
    fclose($lock);
};

$metadata = [];
if (is_file($metadataFile)) {
    $metadata = json_decode((string) @file_get_contents($metadataFile), true);
    if (!is_array($metadata)) {
        $metadata = [];
    }
}

$writeStatus('starting');
$heartbeat();

$process = proc_open(
    [$php, $binConsole, ...$command],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $glpiRoot,
    null,
    ['bypass_shell' => true]
);

if (!is_resource($process)) {
    $writeStatus('error', 127);
    $writeAudit([
        'event' => 'finish',
        'session_id' => $sessionId,
        'user_id' => $metadata['user_id'] ?? null,
        'username' => $metadata['username'] ?? null,
        'command' => $metadata['command'] ?? implode(' ', $command),
        'state' => 'error',
        'exit_code' => 127,
        'duration_seconds' => round(microtime(true) - $startedAt, 3),
    ]);
    @fclose($workerLock);
    @unlink($lockFile);
    exit(127);
}

foreach ([0, 1, 2] as $i) {
    stream_set_blocking($pipes[$i], false);
}

$writeStatus('running');
$heartbeat();

while (true) {
    $heartbeat();

    if ((microtime(true) - $startedAt) >= 900) {
        @proc_terminate($process);
        $timeout = true;
    }

    if (!$stdinClosed && is_file($queueFile)) {
        $fp = @fopen($queueFile, 'c+');
        if (is_resource($fp) && @flock($fp, LOCK_EX)) {
            clearstatcache(true, $queueFile);
            $queueSize = (int) (@filesize($queueFile) ?: 0);
            if ($queueSize > 65536) {
                ftruncate($fp, 0);
                $resourceLimit = true;
                @proc_terminate($process);
            }

            rewind($fp);
            $payload = stream_get_contents($fp);
            if ($payload !== false && $payload !== '') {
                ftruncate($fp, 0);
                rewind($fp);
                foreach (preg_split('/\R/', trim($payload), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
                    $item = json_decode($line, true);
                    if (!is_array($item)) {
                        continue;
                    }

                    if (($item['type'] ?? '') === 'input') {
                        $value = (string) ($item['value'] ?? '');
                        if (strlen($value) > 8192) {
                            $resourceLimit = true;
                            @proc_terminate($process);
                            continue;
                        }
                        if (!str_ends_with($value, "\n")) {
                            $value .= "\n";
                        }
                        @fwrite($pipes[0], $value);
                        @fflush($pipes[0]);
                    } elseif (($item['type'] ?? '') === 'eof') {
                        @fclose($pipes[0]);
                        $stdinClosed = true;
                    } elseif (($item['type'] ?? '') === 'terminate') {
                        @proc_terminate($process);
                        $terminated = true;
                    }
                }
            }
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    foreach ([1, 2] as $i) {
        if (!is_resource($pipes[$i])) {
            continue;
        }

        $data = stream_get_contents($pipes[$i]);
        if ($data !== false && $data !== '') {
            $ok = $appendCapped($i === 1 ? $stdoutFile : $stderrFile, $data);
            if (!$ok) {
                $terminated = false;
                $resourceLimit = true;
                @proc_terminate($process);
            }
        }
    }

    $ps = proc_get_status($process);
    if (!$ps['running']) {
        foreach ([1, 2] as $i) {
            if (!is_resource($pipes[$i])) {
                continue;
            }
            $data = stream_get_contents($pipes[$i]);
            if ($data !== false && $data !== '') {
                $appendCapped($i === 1 ? $stdoutFile : $stderrFile, $data);
            }
            fclose($pipes[$i]);
        }
        if (is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $exitCode = proc_close($process);
        $state = $timeout
            ? 'timeout'
            : ($resourceLimit ? 'resource_limit' : ($terminated ? 'terminated' : ($exitCode === 0 ? 'finished' : 'error')));

        $writeStatus($state, $exitCode);
        $writeAudit([
            'event' => 'finish',
            'session_id' => $sessionId,
            'user_id' => $metadata['user_id'] ?? null,
            'username' => $metadata['username'] ?? null,
            'command' => $metadata['command'] ?? implode(' ', $command),
            'state' => $state,
            'exit_code' => $exitCode,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ]);
        break;
    }

    usleep(100000);
}

@flock($workerLock, LOCK_UN);
@fclose($workerLock);
@unlink($lockFile);
@unlink($heartbeatFile);
