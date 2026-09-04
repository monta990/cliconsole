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

    // GLPI 11.0.9+ and GLPI 12 handle the plugin/template cache lifecycle
    // in the core. Older GLPI 11 releases require the plugin to invalidate
    // the cache explicitly after installation or upgrade so that compiled
    // Twig templates from a previous plugin version are not reused.
    if (version_compare(GLPI_VERSION, '11.0.9', '<')) {
        (new \Glpi\Cache\CacheManager())->resetAllCaches();
    }

    return true;
}

function plugin_cliconsole_uninstall(): bool
{
    $config = new Config();
    return $config->deleteByCriteria(['context' => 'plugin:cliconsole']);
}
