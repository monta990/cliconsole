<?php

declare(strict_types=1);

use Glpi\Plugin\Hooks;
use GlpiPlugin\Cliconsole\CliConsole;

define('PLUGIN_CLICONSOLE_VERSION', '1.0.0');
define('PLUGIN_CLICONSOLE_MIN_GLPI', '11.0.0');
define('PLUGIN_CLICONSOLE_MAX_GLPI', '13.0.0');

global $PLUGIN_HOOKS;

// Keep the plugin management "Configure" action available even though the
// actual configuration UI is implemented as a modern Controller route.
$PLUGIN_HOOKS['csrf_compliant']['cliconsole'] = true;

function plugin_init_cliconsole(): void
{
    $PLUGIN_HOOKS['add_css']['cliconsole'][] = 'css/cliconsole.css';
    global $PLUGIN_HOOKS;

    // GLPI 11 uses CSRF form tokens; GLPI 12 uses Fetch Metadata.
    if (version_compare(GLPI_VERSION, '12.0.0', '<')) {
        $PLUGIN_HOOKS['csrf_compliant']['cliconsole'] = true;
    }

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
    if (version_compare(GLPI_VERSION, PLUGIN_CLICONSOLE_MIN_GLPI, 'lt')) {
        echo 'This plugin requires GLPI >= ' . PLUGIN_CLICONSOLE_MIN_GLPI . '.<br>';
        return false;
    }

    if (version_compare(GLPI_VERSION, PLUGIN_CLICONSOLE_MAX_GLPI, 'ge')) {
        echo 'This plugin requires GLPI < ' . PLUGIN_CLICONSOLE_MAX_GLPI . '.<br>';
        return false;
    }

    if (!function_exists('proc_open')) {
        echo 'The proc_open() PHP function is required.<br>';
        return false;
    }

    return true;
}

function plugin_cliconsole_check_config(bool $verbose = false): bool
{
    // Configuration is intentionally not required to activate the plugin.
    return true;
}
