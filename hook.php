<?php

declare(strict_types=1);


function plugin_cliconsole_install(): bool
{
    $config = Config::getConfigurationValues('plugin:cliconsole');

    if (!array_key_exists('php_binary', $config)) {
        Config::setConfigurationValues('plugin:cliconsole', [
            'php_binary' => '',
        ]);
    }

    return true;
}

function plugin_cliconsole_uninstall(): bool
{
    $config = new Config();
    $result = $config->deleteByCriteria(['context' => 'plugin:cliconsole']);

    // Remove plugin-owned temporary session/cache data only when no worker is active.
    // The standard GLPI audit log is intentionally preserved for audit/forensic purposes.
    $sessionBase = defined('GLPI_VAR_DIR')
        ? GLPI_VAR_DIR . '/cliconsole'
        : GLPI_FILES_DIR . '/_plugins/cliconsole';

    $removeTree = static function (string $path) use (&$removeTree): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (glob($path . '/*') ?: [] as $child) {
            $removeTree($child);
        }
        @rmdir($path);
    };

    $hasActiveWorker = false;
    if (is_dir($sessionBase)) {
        foreach (glob($sessionBase . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!preg_match('/\A[a-f0-9]{64}\z/', basename($dir))) {
                continue;
            }

            $lockPath = $dir . '/worker.lock';
            $lock = is_file($lockPath) ? @fopen($lockPath, 'c+') : false;
            if (is_resource($lock)) {
                $locked = !@flock($lock, LOCK_EX | LOCK_NB);
                if (!$locked) {
                    @flock($lock, LOCK_UN);
                } else {
                    $hasActiveWorker = true;
                }
                fclose($lock);
            }
        }

        if (!$hasActiveWorker) {
            $removeTree($sessionBase);
        }
    }

    $cacheBase = defined('GLPI_PLUGIN_DOC_DIR')
        ? GLPI_PLUGIN_DOC_DIR . '/cliconsole'
        : GLPI_FILES_DIR . '/_plugins/cliconsole';
    if (!$hasActiveWorker || realpath($cacheBase) !== realpath($sessionBase)) {
        $removeTree($cacheBase);
    }

    return $result;
}
