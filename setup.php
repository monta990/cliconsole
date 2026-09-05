<?php

declare(strict_types=1);

use Glpi\Plugin\Hooks;
use GlpiPlugin\Cliconsole\CliConsole;

define('PLUGIN_CLICONSOLE_VERSION', '1.0.0');
define('PLUGIN_CLICONSOLE_MIN_GLPI', '12.0.0');
define('PLUGIN_CLICONSOLE_MAX_GLPI', '13.0.0');

global $PLUGIN_HOOKS;

function plugin_init_cliconsole(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['add_css']['cliconsole'][] = 'css/cliconsole.css';

    // Native plugin "Configure" action -> modern Controller route.
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['cliconsole'] = 'config';

    if (!\Plugin::isPluginActive('cliconsole')) {
        return;
    }

    if (CliConsole::isSuperAdmin()) {
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['cliconsole'] = [
            'tools' => CliConsole::class,
        ];
    }
}

function plugin_version_cliconsole(): array
{
    return [
        'name'   => __('CLI Console', 'cliconsole'),
        'version' => PLUGIN_CLICONSOLE_VERSION,
        'author' => 'Edwin Elias Alvarez',
        'homepage' => 'https://github.com/monta990/cliconsole',
        'license' => 'GPLv3+',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_CLICONSOLE_MIN_GLPI,
                'max' => PLUGIN_CLICONSOLE_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_cliconsole_check_prerequisites(): bool
{
    // GLPI performs the version compatibility check from plugin_version_cliconsole().
    // Keep this hook limited to runtime prerequisites that cannot be expressed there.
    if (!function_exists('proc_open')) {
        echo __('The proc_open() PHP function is required.', 'cliconsole') . '<br>';
        return false;
    }

    return true;
}

function plugin_cliconsole_check_config(bool $verbose = false): bool
{
    // Configuration is intentionally not required to activate the plugin.
    return true;
}

