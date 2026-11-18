<?php

declare(strict_types=1);

/**
 * Build a clean, installable GLPI plugin ZIP.
 *
 * Usage:
 *   php tools/build-zip.php
 *   php tools/build-zip.php /path/to/output-directory
 *   php tools/build-zip.php /path/to/cliconsole-1.0.5.zip
 *
 * The archive contains a single top-level "cliconsole/" directory and only
 * the runtime plugin files. CI tooling, tests, Git metadata and handoff notes
 * are intentionally excluded.
 */

$root = dirname(__DIR__);

$fail = static function (string $message): never {
    fwrite(STDERR, '[build-zip] ERROR: ' . $message . PHP_EOL);
    exit(1);
};

if (PHP_SAPI !== 'cli') {
    $fail('This script can only be run from the command line.');
}

if (!class_exists(ZipArchive::class)) {
    $fail('The PHP Zip extension is required. Enable ext-zip and run the script again.');
}

if ($argc > 2) {
    fwrite(STDERR, 'Usage: php tools/build-zip.php [output-directory|output.zip]' . PHP_EOL);
    exit(2);
}

$versionSource = (string) file_get_contents($root . '/setup.php');
if (
    preg_match(
        "/define\\(\\s*'PLUGIN_CLICONSOLE_VERSION'\\s*,\\s*'([0-9]+\\.[0-9]+\\.[0-9]+)'\\s*\\)/",
        $versionSource,
        $versionMatch
    ) !== 1
) {
    $fail('Could not read a valid plugin version from setup.php.');
}

$version = $versionMatch[1];
$manifestPath = $root . '/plugin.xml';
if (!is_file($manifestPath)) {
    $fail('plugin.xml is missing.');
}

$manifest = file_get_contents($manifestPath);
if (
    $manifest === false
    || preg_match('/<num>\s*' . preg_quote($version, '/') . '\s*<\/num>/', $manifest) !== 1
) {
    $fail('The version in plugin.xml does not match setup.php (' . $version . ').');
}

$target = $argv[1] ?? ($root . '/dist');
if (strtolower((string) pathinfo($target, PATHINFO_EXTENSION)) === 'zip') {
    $outputZip = $target;
} else {
    $outputZip = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cliconsole-' . $version . '.zip';
}

$outputDirectory = dirname($outputZip);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
    $fail('Unable to create output directory: ' . $outputDirectory);
}

$releaseFiles = [
    'CHANGELOG.md',
    'LICENSE',
    'README.md',
    'config.png',
    'hook.php',
    'logo.png',
    'plugin.xml',
    'setup.php',
    'user.png',
];
$releaseDirectories = [
    'css',
    'locales',
    'src',
    'templates',
];

$files = [];
foreach ($releaseFiles as $relativePath) {
    $absolutePath = $root . DIRECTORY_SEPARATOR . $relativePath;
    if (!is_file($absolutePath) || is_link($absolutePath)) {
        $fail('Required release file is missing or is a symbolic link: ' . $relativePath);
    }
    $files[$relativePath] = $absolutePath;
}

foreach ($releaseDirectories as $relativeDirectory) {
    $absoluteDirectory = $root . DIRECTORY_SEPARATOR . $relativeDirectory;
    if (!is_dir($absoluteDirectory) || is_link($absoluteDirectory)) {
        $fail('Required release directory is missing or is a symbolic link: ' . $relativeDirectory);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteDirectory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            $fail('Symbolic links are not allowed in release packages: ' . $entry->getPathname());
        }
        if (!$entry->isFile()) {
            continue;
        }

        $relativePath = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($entry->getPathname(), strlen($root) + 1)
        );
        $files[$relativePath] = $entry->getPathname();
    }
}

ksort($files, SORT_STRING);

$archive = new ZipArchive();
$openResult = $archive->open($outputZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($openResult !== true) {
    $fail('Unable to create ZIP archive (ZipArchive error ' . $openResult . ').');
}

$added = [];
try {
    foreach ($files as $relativePath => $absolutePath) {
        $entryName = 'cliconsole/' . str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        if (!$archive->addFile($absolutePath, $entryName)) {
            throw new RuntimeException('Unable to add file to archive: ' . $relativePath);
        }

        if (!$archive->setCompressionName($entryName, ZipArchive::CM_DEFLATE, 9)) {
            throw new RuntimeException('Unable to set ZIP compression for: ' . $relativePath);
        }

        // A fixed timestamp makes repeated builds reproducible.
        if (method_exists($archive, 'setMtimeName')) {
            $archive->setMtimeName($entryName, 315532800); // 1980-01-01 00:00:00 UTC
        }

        $added[] = $entryName;
    }

    if (!$archive->close()) {
        throw new RuntimeException('Unable to finalize the ZIP archive.');
    }
} catch (Throwable $exception) {
    $archive->close();
    @unlink($outputZip);
    $fail($exception->getMessage());
}

$check = new ZipArchive();
if ($check->open($outputZip, ZipArchive::CHECKCONS) !== true) {
    @unlink($outputZip);
    $fail('The generated ZIP archive failed its integrity check.');
}

$requiredEntries = [
    'cliconsole/setup.php',
    'cliconsole/hook.php',
    'cliconsole/plugin.xml',
    'cliconsole/src/CliConsole.php',
    'cliconsole/src/TerminalWorker.php',
    'cliconsole/templates/console.html.twig',
    'cliconsole/locales/es_MX.mo',
];
foreach ($requiredEntries as $requiredEntry) {
    if ($check->locateName($requiredEntry) === false) {
        $check->close();
        @unlink($outputZip);
        $fail('The generated package is missing a required entry: ' . $requiredEntry);
    }
}

for ($index = 0; $index < $check->numFiles; $index++) {
    $entryName = $check->getNameIndex($index);
    if (
        $entryName === false
        || !str_starts_with($entryName, 'cliconsole/')
        || str_contains($entryName, '/.git/')
        || str_starts_with($entryName, 'cliconsole/.github/')
        || str_starts_with($entryName, 'cliconsole/tools/')
        || $entryName === 'cliconsole/HANDOFF.md'
    ) {
        $check->close();
        @unlink($outputZip);
        $fail('The generated package contains a development-only or unexpected entry.');
    }
}
$check->close();

$size = filesize($outputZip);
$hash = hash_file('sha256', $outputZip);
if ($size === false || $hash === false) {
    $fail('Unable to calculate the generated archive size or checksum.');
}

fwrite(STDOUT, 'CLI Console ' . $version . ' package created successfully.' . PHP_EOL);
fwrite(STDOUT, 'File: ' . $outputZip . PHP_EOL);
fwrite(STDOUT, 'Files: ' . count($added) . PHP_EOL);
fwrite(STDOUT, 'Size: ' . number_format($size) . ' bytes' . PHP_EOL);
fwrite(STDOUT, 'SHA-256: ' . $hash . PHP_EOL);
