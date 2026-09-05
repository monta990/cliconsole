<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

if ($argc !== 7) {
    fwrite(STDERR, "Invalid worker arguments.\n");
    exit(2);
}

[, $sessionId, $sessionDir, $commandJson, $phpBinary, $console, $glpiRootArg] = $argv;

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

$writeStatus = static function (string $state, ?int $exitCode = null) use ($statusFile): void {
    @file_put_contents(
        $statusFile,
        json_encode([
            'state' => $state,
            'exit_code' => $exitCode,
            'finished_at' => in_array($state, ['finished', 'error', 'terminated'], true) ? time() : null,
        ], JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
};

$writeStatus('starting');

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
    exit(127);
}

foreach ([0,1,2] as $i) stream_set_blocking($pipes[$i], false);

$terminated=false;
$stdinClosed=false;
$writeStatus('running');

while (true) {
    if (!$stdinClosed && is_file($queueFile)) {
        $fp=@fopen($queueFile,'c+');
        if (is_resource($fp) && @flock($fp,LOCK_EX)) {
            $payload=stream_get_contents($fp);
            if ($payload!==false && $payload!=='') {
                ftruncate($fp,0); rewind($fp);
                foreach (preg_split('/\R/',trim($payload),-1,PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
                    $item=json_decode($line,true);
                    if (!is_array($item)) continue;

                    if (($item['type'] ?? '') === 'input') {
                        $value=(string)($item['value'] ?? '');
                        if (!str_ends_with($value,"\n")) $value.="\n";
                        @fwrite($pipes[0],$value);
                        @fflush($pipes[0]);
                    } elseif (($item['type'] ?? '') === 'eof') {
                        @fclose($pipes[0]);
                        $stdinClosed=true;
                    } elseif (($item['type'] ?? '') === 'terminate') {
                        @proc_terminate($process);
                        $terminated=true;
                    }
                }
            }
            @flock($fp,LOCK_UN); fclose($fp);
        }
    }

    foreach ([1,2] as $i) {
        if (!is_resource($pipes[$i])) continue;
        $data=stream_get_contents($pipes[$i]);
        if ($data!==false && $data!=='') {
            @file_put_contents($i===1?$stdoutFile:$stderrFile,$data,FILE_APPEND);
        }
    }

    $ps=proc_get_status($process);
    if (!$ps['running']) {
        foreach ([1,2] as $i) {
            if (!is_resource($pipes[$i])) continue;
            $data=stream_get_contents($pipes[$i]);
            if ($data!==false && $data!=='') {
                @file_put_contents($i===1?$stdoutFile:$stderrFile,$data,FILE_APPEND);
            }
            fclose($pipes[$i]);
        }
        if (is_resource($pipes[0])) fclose($pipes[0]);

        $exitCode=proc_close($process);
        $writeStatus($terminated?'terminated':($exitCode===0?'finished':'error'),$exitCode);
        break;
    }

    usleep(100000);
}
