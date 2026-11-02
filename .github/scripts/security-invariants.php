<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$fail = static function (string $message): never {
    fwrite(STDERR, '[security-invariants] ' . $message . PHP_EOL);
    exit(1);
};

$read = static function (string $relativePath) use ($root, $fail): string {
    $path = $root . '/' . $relativePath;
    if (!is_file($path)) {
        $fail('Security-critical file is missing: ' . $relativePath);
    }

    return (string) file_get_contents($path);
};

$processFiles = [
    'src/CliConsole.php',
    'src/Controller/ConsoleController.php',
    'src/PhpCliValidator.php',
    'src/TerminalWorker.php',
];

foreach ($processFiles as $relativePath) {
    $source = $read($relativePath);
    $count = preg_match_all('/\bproc_open\s*\(/', $source);

    if ($count !== 1) {
        $fail($relativePath . ' must have exactly one reviewed proc_open() call; found ' . $count . '.');
    }

    if (preg_match('/proc_open\s*\(\s*\[/s', $source) !== 1) {
        $fail($relativePath . ' must pass an argument array to proc_open().');
    }

    if (!str_contains($source, "'bypass_shell' => true")) {
        $fail($relativePath . ' must keep bypass_shell enabled.');
    }
}

$allPhp = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($allPhp as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_starts_with($path, '.git/')) {
        continue;
    }

    $source = (string) file_get_contents($file->getPathname());
    if (preg_match('/\b(?:shell_exec|exec|system|passthru|popen|eval)\s*\(/', $source) === 1) {
        $fail('A shell-oriented or dynamic-code execution function was introduced in ' . $path . '.');
    }
}

$worker = $read('src/TerminalWorker.php');
$workerInvariants = [
    "PHP_SAPI !== 'cli'",
    "DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR",
    'realpath($console)',
    'realpath($glpiRootArg)',
    'str_starts_with($binConsole, $binDir)',
    'RuntimeLimits::MAX_ARGUMENTS',
    'RuntimeLimits::MAX_ARGUMENT_LENGTH',
    'str_contains($argument, "\0")',
    'flock($workerLock, LOCK_EX | LOCK_NB)',
    "['bypass_shell' => true]",
];

foreach ($workerInvariants as $invariant) {
    if (!str_contains($worker, $invariant)) {
        $fail('The terminal worker is missing a required execution boundary: ' . $invariant);
    }
}

$console = $read('src/Controller/ConsoleController.php');
$ownerChecks = preg_match_all('/CliConsole::assertSessionOwner\(\$sessionDir\)/', $console);
$accessChecks = preg_match_all('/\$this->checkAccess\(\)/', $console);
$reauthChecks = preg_match_all('/\$this->checkReAuth\(\)/', $console);

if ($ownerChecks !== 4) {
    $fail('All four session-specific controller actions must verify session ownership; found ' . $ownerChecks . '.');
}
if ($accessChecks !== 6 || $reauthChecks !== 5 || !str_contains($console, '$this->checkReAuthPage();')) {
    $fail('Controller authorization or GLPI re-authentication checks changed unexpectedly.');
}

$session = $read('src/CliConsole.php');
if (
    !str_contains($session, "'user_id'] ?? 0")
    || !str_contains($session, 'ownerId !== self::getCurrentUserId()')
    || !str_contains($session, '\A[a-f0-9]{64}\z')
) {
    $fail('Session ownership or session identifier validation changed unexpectedly.');
}

$audit = $read('src/AuditLogger.php');
$auditInvariants = [
    'flock($lock, LOCK_EX)',
    'is_link($path)',
    'chmod($path, 0600)',
    'RuntimeLimits::AUDIT_MAX_SIZE',
    'LOCK_EX',
];

foreach ($auditInvariants as $invariant) {
    if (!str_contains($audit, $invariant)) {
        $fail('Audit log protection is missing: ' . $invariant);
    }
}

$redaction = $read('src/CliConsole.php');
if (
    !str_contains($redaction, "['glpi:config:set', 'config:set']")
    || !str_contains($redaction, 'str_contains($name, \'pass\')')
    || !str_contains($redaction, 'str_contains($name, \'token\')')
    || !str_contains($redaction, 'str_contains($name, \'secret\')')
) {
    $fail('Sensitive command argument redaction changed unexpectedly.');
}

echo "CLI execution, session isolation, re-authentication, resource limits, and audit-log invariants passed.\n";
