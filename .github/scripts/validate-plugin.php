<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$fail = static function (string $message): never {
    fwrite(STDERR, '[plugin-validation] ' . $message . PHP_EOL);
    exit(1);
};

$requiredFiles = [
    'setup.php',
    'hook.php',
    'plugin.xml',
    'src/CliConsole.php',
    'src/PhpCliValidator.php',
    'src/RuntimeLimits.php',
    'src/TerminalWorker.php',
    'src/AuditLogger.php',
    'src/VersionChecker.php',
    'src/Controller/ConfigController.php',
    'src/Controller/ConsoleController.php',
    'templates/configuration.html.twig',
    'templates/console.html.twig',
    'css/cliconsole.css',
    'locales/cliconsole.po',
    'locales/es_MX.po',
    'locales/es_MX.mo',
];

foreach ($requiredFiles as $relativePath) {
    if (!is_file($root . '/' . $relativePath)) {
        $fail('Required plugin file is missing: ' . $relativePath);
    }
}

if (file_exists($root . '/composer.json') || file_exists($root . '/composer.lock') || is_dir($root . '/vendor')) {
    $fail('A Composer manifest, lockfile, or vendor directory was added; this plugin is intentionally Composer-free.');
}

if (!function_exists('simplexml_load_file')) {
    $fail('SimpleXML is required by the CI validation environment.');
}

libxml_use_internal_errors(true);
$manifest = simplexml_load_file($root . '/plugin.xml');
if ($manifest === false) {
    $errors = array_map(
        static fn (LibXMLError $error): string => trim($error->message),
        libxml_get_errors()
    );
    $fail('plugin.xml is not valid XML: ' . implode('; ', $errors));
}

if ((string) $manifest->key !== 'cliconsole') {
    $fail('plugin.xml key must remain cliconsole.');
}

$manifestVersion = (string) ($manifest->versions->version->num ?? '');
$setup = (string) file_get_contents($root . '/setup.php');

if (preg_match("/define\\(\\s*'PLUGIN_CLICONSOLE_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $setup, $match) !== 1) {
    $fail('The plugin version constant could not be read from setup.php.');
}

if ($manifestVersion === '' || $manifestVersion !== $match[1]) {
    $fail('The version in plugin.xml does not match setup.php.');
}

if ((string) ($manifest->versions->version->compatibility ?? '') !== '~12.0.0') {
    $fail('The plugin compatibility declaration must remain GLPI 12.x.');
}

if (!str_contains($setup, "'min' => '8.3'")) {
    $fail('setup.php must continue to declare PHP 8.3 as the minimum supported version.');
}

if (!str_contains($setup, "function_exists('proc_open')")) {
    $fail('The activation prerequisite for proc_open() is missing.');
}

$sourceRoot = $root . '/src';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
);
$classCount = 0;

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === 'TerminalWorker.php') {
        continue;
    }

    $absolutePath = $file->getPathname();
    $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($sourceRoot) + 1));
    $source = (string) file_get_contents($absolutePath);

    if (preg_match('/^namespace\\s+([^;]+);/m', $source, $namespaceMatch) !== 1) {
        $fail('An autoloadable PHP source file has no namespace: src/' . $relativePath);
    }

    preg_match_all(
        '/^(?:(?:final|abstract|readonly)\\s+)*(?:class|interface|trait|enum)\\s+([A-Za-z_][A-Za-z0-9_]*)/m',
        $source,
        $classMatches
    );

    foreach ($classMatches[1] as $className) {
        $namespace = trim($namespaceMatch[1]);
        $expectedNamespace = 'GlpiPlugin\\Cliconsole';
        $expectedPath = '';

        $directory = dirname($relativePath);
        if ($directory !== '.') {
            $namespaceSuffix = str_replace('/', '\\', $directory);
            $expectedNamespace .= '\\' . $namespaceSuffix;
            $expectedPath = $directory . '/';
        }

        if ($namespace !== $expectedNamespace) {
            $fail(
                'PSR-4 namespace mismatch in src/' . $relativePath
                . ': expected ' . $expectedNamespace . ', found ' . $namespace
            );
        }

        if ($relativePath !== $expectedPath . $className . '.php') {
            $fail(
                'PSR-4 filename mismatch for ' . $namespace . '\\' . $className
                . ': expected src/' . $expectedPath . $className . '.php'
            );
        }

        $classCount++;
    }
}

if ($classCount < 7) {
    $fail('The PSR-4 scan found fewer than the expected seven plugin classes.');
}

echo 'Plugin manifest, required files, Composer-free packaging, and PSR-4 checks passed (' . $classCount . " classes).\n";
