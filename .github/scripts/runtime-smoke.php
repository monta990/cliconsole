<?php

declare(strict_types=1);

namespace Symfony\Component\HttpKernel\Exception {
    class BadRequestHttpException extends \RuntimeException
    {
    }

    class AccessDeniedHttpException extends \RuntimeException
    {
    }
}

namespace {
    use GlpiPlugin\Cliconsole\AuditLogger;
    use GlpiPlugin\Cliconsole\CliConsole;
    use GlpiPlugin\Cliconsole\PhpCliValidator;
    use GlpiPlugin\Cliconsole\RuntimeLimits;

    if (!class_exists('CommonDBTM')) {
        class CommonDBTM
        {
        }
    }

    require_once __DIR__ . '/../../src/RuntimeLimits.php';
    require_once __DIR__ . '/../../src/PhpCliValidator.php';
    require_once __DIR__ . '/../../src/AuditLogger.php';
    require_once __DIR__ . '/../../src/CliConsole.php';

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };

    $temporaryRoot = sys_get_temp_dir() . '/cliconsole-ci-' . bin2hex(random_bytes(8));

    $mkdir = static function (string $path): void {
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create CI fixture directory: ' . $path);
        }
    };

    $removeTree = static function (string $path) use (&$removeTree): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $removeTree($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
            @rmdir($path);
            return;
        }

        @unlink($path);
    };

    $mkdir($temporaryRoot);

    try {
        $assert(RuntimeLimits::MAX_ACTIVE_SESSIONS === 3, 'The active-session limit changed unexpectedly.');
        $assert(RuntimeLimits::MAX_EXECUTION_TIME === 900, 'The command timeout changed unexpectedly.');
        $assert(RuntimeLimits::MAX_OUTPUT_SIZE === 5 * 1024 * 1024, 'The output cap changed unexpectedly.');
        $assert(RuntimeLimits::MAX_QUEUE_SIZE === 65536, 'The input queue cap changed unexpectedly.');

        $parsed = CliConsole::parseCommand('glpi:about --env=prod');
        $assert($parsed === ['glpi:about', '--env=prod'], 'Safe command parsing failed.');

        $shellOperatorRejected = false;
        try {
            CliConsole::parseCommand('glpi:about; id');
        } catch (\Symfony\Component\HttpKernel\Exception\BadRequestHttpException) {
            $shellOperatorRejected = true;
        }
        $assert($shellOperatorRejected, 'A shell operator was not rejected.');

        $redacted = CliConsole::redactCommand([
            '--db-password',
            'do-not-log-this',
            '--token=private-token',
            '-psecret',
            'glpi:about',
        ]);
        $assert(!str_contains($redacted, 'do-not-log-this'), 'A password value was not redacted.');
        $assert(!str_contains($redacted, 'private-token'), 'A token value was not redacted.');
        $assert(!str_contains($redacted, '-psecret'), 'An attached short password value was not redacted.');

        $configSet = CliConsole::redactCommand([
            '--env',
            'prod',
            'glpi:config:set',
            'dbpassword',
            'never-log-this',
        ]);
        $assert(!str_contains($configSet, 'never-log-this'), 'Sensitive config:set arguments were not withheld.');

        PhpCliValidator::assertValid(PHP_BINARY, $temporaryRoot);

        $auditDirectory = $temporaryRoot . '/audit';
        $mkdir($auditDirectory);
        $auditFile = $auditDirectory . '/cliconsole.log';

        AuditLogger::write($auditFile, [
            'event' => 'ci-smoke',
            'user_id' => 42,
            'command' => 'glpi:about',
        ]);

        $assert(is_file($auditFile), 'The audit log was not created.');
        $assert((fileperms($auditFile) & 0777) === 0600, 'The audit log permissions are not restricted to 0600.');
        $firstRecord = json_decode(trim((string) file_get_contents($auditFile)), true);
        $assert(is_array($firstRecord) && ($firstRecord['event'] ?? '') === 'ci-smoke', 'The audit log record is invalid.');

        file_put_contents($auditFile, str_repeat('R', RuntimeLimits::AUDIT_MAX_SIZE));
        chmod($auditFile, 0600);
        AuditLogger::write($auditFile, [
            'event' => 'after-rotation',
            'user_id' => 42,
        ]);

        $rotatedFile = $auditFile . '.1';
        $assert(is_file($rotatedFile), 'The audit log did not rotate at its configured size limit.');
        $assert(filesize($rotatedFile) === RuntimeLimits::AUDIT_MAX_SIZE, 'The rotated audit log has an unexpected size.');
        $assert(str_contains((string) file_get_contents($auditFile), 'after-rotation'), 'The audit log did not accept a record after rotation.');

        $ownerDirectory = $temporaryRoot . '/owner-session';
        $mkdir($ownerDirectory);
        file_put_contents($ownerDirectory . '/metadata.json', json_encode(['user_id' => 42], JSON_THROW_ON_ERROR));
        $_SESSION = ['glpiID' => 42];

        $ownerMetadata = CliConsole::assertSessionOwner($ownerDirectory);
        $assert(($ownerMetadata['user_id'] ?? 0) === 42, 'The owning user could not access their terminal session.');

        $_SESSION = ['glpiID' => 99];
        $crossUserRejected = false;
        try {
            CliConsole::assertSessionOwner($ownerDirectory);
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
            $crossUserRejected = true;
        }
        $assert($crossUserRejected, 'A different user was allowed to access a terminal session.');

        $glpiRoot = $temporaryRoot . '/fake-glpi';
        $binDirectory = $glpiRoot . '/bin';
        $sessionDirectory = $temporaryRoot . '/worker-session';
        $mkdir($binDirectory);
        $mkdir($sessionDirectory);

        $consolePath = $binDirectory . '/console';
        $consoleSource = <<<'PHP'
<?php
fwrite(STDOUT, 'fixture-console-ok:' . implode(',', array_slice($argv, 1)) . PHP_EOL);
$input = stream_get_contents(STDIN);
fwrite(STDOUT, 'fixture-input:' . $input);
fwrite(STDERR, 'fixture-stderr-ok' . PHP_EOL);
PHP;
        file_put_contents($consolePath, $consoleSource);

        $sessionId = str_repeat('a', 64);
        $initialFiles = [
            'input.queue' => json_encode(['type' => 'input', 'value' => 'smoke-input'], JSON_THROW_ON_ERROR)
                . PHP_EOL
                . json_encode(['type' => 'eof'], JSON_THROW_ON_ERROR)
                . PHP_EOL,
            'stdout.log' => '',
            'stderr.log' => '',
            'status.json' => json_encode(['state' => 'ready'], JSON_THROW_ON_ERROR),
            'metadata.json' => json_encode([
                'user_id' => 42,
                'username' => 'ci-smoke',
                'command' => 'smoke-command',
                'php_binary' => PHP_BINARY,
            ], JSON_THROW_ON_ERROR),
        ];

        foreach ($initialFiles as $name => $contents) {
            file_put_contents($sessionDirectory . '/' . $name, $contents);
            chmod($sessionDirectory . '/' . $name, 0600);
        }

        $workerPath = realpath(__DIR__ . '/../../src/TerminalWorker.php');
        $realGlpiRoot = realpath($glpiRoot);
        $realConsolePath = realpath($consolePath);
        $realSessionDirectory = realpath($sessionDirectory);

        $assert(
            $workerPath !== false && $realGlpiRoot !== false && $realConsolePath !== false && $realSessionDirectory !== false,
            'Unable to resolve the isolated CLI worker fixture paths.'
        );

        $workerCommand = [
            PHP_BINARY,
            $workerPath,
            $sessionId,
            $realSessionDirectory,
            json_encode(['smoke-command'], JSON_THROW_ON_ERROR),
            PHP_BINARY,
            $realConsolePath,
            $realGlpiRoot,
            $auditFile,
        ];

        $worker = proc_open(
            $workerCommand,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $workerPipes,
            $realGlpiRoot,
            null,
            ['bypass_shell' => true]
        );

        $assert(is_resource($worker), 'The isolated CLI worker could not be started.');
        fclose($workerPipes[0]);

        $workerStartedAt = microtime(true);
        $workerExitCode = null;
        do {
            $workerStatus = proc_get_status($worker);
            if (!$workerStatus['running']) {
                $workerExitCode = (int) $workerStatus['exitcode'];
                break;
            }

            if ((microtime(true) - $workerStartedAt) > 15) {
                proc_terminate($worker);
                throw new RuntimeException('The isolated CLI worker exceeded the 15-second smoke-test timeout.');
            }

            usleep(50000);
        } while (true);

        $workerStdout = stream_get_contents($workerPipes[1]);
        $workerStderr = stream_get_contents($workerPipes[2]);
        fclose($workerPipes[1]);
        fclose($workerPipes[2]);
        $closeCode = proc_close($worker);

        if ($workerExitCode === -1) {
            $workerExitCode = $closeCode;
        }

        $assert(
            $workerExitCode === 0,
            'The isolated CLI worker failed. Exit code: ' . $workerExitCode . '. STDERR: ' . $workerStderr . '. STDOUT: ' . $workerStdout
        );

        $workerStatusData = json_decode((string) file_get_contents($sessionDirectory . '/status.json'), true);
        $assert(
            is_array($workerStatusData)
            && ($workerStatusData['state'] ?? '') === 'finished'
            && ($workerStatusData['exit_code'] ?? null) === 0,
            'The isolated CLI worker did not finish with a successful status.'
        );

        $capturedOutput = (string) file_get_contents($sessionDirectory . '/stdout.log');
        $capturedError = (string) file_get_contents($sessionDirectory . '/stderr.log');
        $assert(str_contains($capturedOutput, 'fixture-console-ok:smoke-command'), 'The worker did not capture STDOUT.');
        $assert(str_contains($capturedOutput, "fixture-input:smoke-input\n"), 'The worker did not forward queued terminal input.');
        $assert(str_contains($capturedError, 'fixture-stderr-ok'), 'The worker did not capture STDERR.');

        $auditContents = (string) file_get_contents($auditFile);
        $assert(str_contains($auditContents, '"event":"finish"'), 'The worker did not write its completion audit event.');

        $outsideConsole = $temporaryRoot . '/outside-console.php';
        file_put_contents($outsideConsole, "<?php echo 'outside';\n");

        $escapedConsoleWorker = proc_open(
            [
                PHP_BINARY,
                $workerPath,
                str_repeat('b', 64),
                $realSessionDirectory,
                json_encode(['must-not-run'], JSON_THROW_ON_ERROR),
                PHP_BINARY,
                realpath($outsideConsole),
                $realGlpiRoot,
                $auditFile,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $escapePipes,
            $realGlpiRoot,
            null,
            ['bypass_shell' => true]
        );

        $assert(is_resource($escapedConsoleWorker), 'The path-confinement worker test could not start.');
        foreach ($escapePipes as $pipe) {
            fclose($pipe);
        }
        $escapeExitCode = proc_close($escapedConsoleWorker);
        $assert($escapeExitCode === 8, 'The worker accepted a bin/console path outside the GLPI root.');

        echo "CLI Console runtime smoke tests passed.\n";
    } finally {
        $removeTree($temporaryRoot);
    }
}
