<?php

declare(strict_types=1);

namespace GlpiPlugin\Cliconsole;

use RuntimeException;

final class VersionChecker
{
    private const REPOSITORY = 'https://github.com/monta990/cliconsole';
    private const RELEASE_API = 'https://api.github.com/repos/monta990/cliconsole/releases/latest';
    private const CACHE_TTL = 21600;

    public static function getStatus(): array
    {
        $current = PLUGIN_CLICONSOLE_VERSION;
        $cacheFile = self::getCacheFile();

        $cached = self::readCache($cacheFile);
        if ($cached !== null && (time() - ($cached['checked_at'] ?? 0)) < self::CACHE_TTL) {
            return self::buildStatus($current, $cached);
        }

        $remote = self::fetchLatestRelease();

        if ($remote !== null) {
            $cache = [
                'checked_at' => time(),
                'version' => $remote['version'],
                'url' => $remote['url'],
                'success' => true,
            ];
            self::writeCache($cacheFile, $cache);
            return self::buildStatus($current, $cache);
        }

        // Stale-cache fallback: do not make the configuration page fail just
        // because GitHub is temporarily unavailable.
        if ($cached !== null && !empty($cached['version'])) {
            $cached['stale'] = true;
            return self::buildStatus($current, $cached);
        }

        return [
            'state' => 'unavailable',
            'current' => $current,
            'latest' => null,
            'url' => self::REPOSITORY . '/releases',
        ];
    }

    public static function getRepositoryUrl(): string
    {
        return self::REPOSITORY;
    }

    private static function buildStatus(string $current, array $data): array
    {
        $latest = self::normalizeVersion((string) ($data['version'] ?? ''));
        if ($latest === null) {
            return [
                'state' => 'unavailable',
                'current' => $current,
                'latest' => null,
                'url' => self::REPOSITORY . '/releases',
            ];
        }

        $url = self::safeReleaseUrl((string) ($data['url'] ?? ''));
        if ($url === null) {
            $url = self::REPOSITORY . '/releases';
        }

        return [
            'state' => version_compare($latest, $current, '>')
                ? 'update'
                : 'current',
            'current' => $current,
            'latest' => $latest,
            'url' => $url,
            'stale' => !empty($data['stale']),
        ];
    }

    private static function fetchLatestRelease(): ?array
    {
        if (!function_exists('curl_init') || !function_exists('curl_setopt_array')) {
            return null;
        }

        // Prefer the protocol-name options added with libcurl 7.85.0. Older
        // PHP/cURL builds do not expose them, so use the equivalent bitmask
        // options only on those older builds. If neither safe form is available,
        // fail closed and leave the optional release check unavailable.
        $protocolOptions = self::getProtocolOptions();
        if ($protocolOptions === null) {
            return null;
        }

        $curl = curl_init(self::RELEASE_API);
        if ($curl === false) {
            return null;
        }

        $body = '';
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'GLPI-CLI-Console/' . PLUGIN_CLICONSOLE_VERSION,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 65536) {
                    return 0;
                }

                $body .= $chunk;
                return strlen($chunk);
            },
        ];

        foreach ($protocolOptions as $option => $value) {
            $options[$option] = $value;
        }

        // Do not make a network request if any security or transport option was
        // rejected by the installed PHP/libcurl combination.
        if (!curl_setopt_array($curl, $options)) {
            curl_close($curl);
            return null;
        }

        $ok = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($ok !== true || $statusCode < 200 || $statusCode >= 300 || $body === '') {
            return null;
        }

        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease'])) {
            return null;
        }

        $version = self::normalizeVersion((string) ($data['tag_name'] ?? ''));
        if ($version === null) {
            return null;
        }

        $url = self::safeReleaseUrl((string) ($data['html_url'] ?? ''));

        return [
            'version' => $version,
            'url' => $url ?? self::REPOSITORY . '/releases',
        ];
    }

    /**
     * Return strict HTTPS-only protocol restrictions supported by this cURL build.
     *
     * @return array<int, int|string>|null
     */
    private static function getProtocolOptions(): ?array
    {
        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            return [
                (int) constant('CURLOPT_PROTOCOLS_STR') => 'https',
                (int) constant('CURLOPT_REDIR_PROTOCOLS_STR') => 'https',
            ];
        }

        if (
            defined('CURLOPT_PROTOCOLS')
            && defined('CURLOPT_REDIR_PROTOCOLS')
            && defined('CURLPROTO_HTTPS')
        ) {
            return [
                (int) constant('CURLOPT_PROTOCOLS') => (int) constant('CURLPROTO_HTTPS'),
                (int) constant('CURLOPT_REDIR_PROTOCOLS') => (int) constant('CURLPROTO_HTTPS'),
            ];
        }

        return null;
    }

    private static function normalizeVersion(string $version): ?string
    {
        $version = trim($version);
        $version = ltrim($version, 'vV');

        return preg_match('/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $version)
            ? $version
            : null;
    }

    private static function safeReleaseUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (
            $parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'github.com'
        ) {
            return null;
        }

        return $url;
    }

    private static function getCacheFile(): string
    {
        $dir = defined('GLPI_PLUGIN_DOC_DIR')
            ? GLPI_PLUGIN_DOC_DIR . '/cliconsole'
            : GLPI_FILES_DIR . '/_plugins/cliconsole';

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException(__('Unable to create the CLI Console version cache directory.', 'cliconsole'));
        }

        return $dir . '/github-version.json';
    }

    private static function readCache(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    private static function writeCache(string $file, array $data): void
    {
        @file_put_contents(
            $file,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
