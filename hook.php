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
    return $config->deleteByCriteria(['context' => 'plugin:cliconsole']);
}
