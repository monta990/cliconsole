<?php

declare(strict_types=1);

namespace GlpiPlugin\Cliconsole;

use CommonDBTM;
use Profile as GlpiProfile;

final class CliConsole extends CommonDBTM
{
    public static function getTypeName($nb = 0): string
    {
        return __('CLI Console', 'cliconsole');
    }

    public static function getMenuName($nb = 0): string
    {
        return self::getTypeName($nb);
    }

    public static function getIcon(): string
    {
        return 'ti ti-terminal-2';
    }

    /**
     * CLI Console is intentionally restricted to GLPI's native Super-Admin
     * profiles. This follows GLPI's own definition of a Super-Admin profile.
     */
    public static function isSuperAdmin(): bool
    {
        $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);

        if ($profileId <= 0) {
            return false;
        }

        return in_array(
            $profileId,
            GlpiProfile::getSuperAdminProfilesId(),
            true
        );
    }

    public static function getMenuContent(): array
    {
        global $CFG_GLPI;

        if (!self::isSuperAdmin()) {
            return [];
        }

        $base = rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole';
        $consoleUrl = $base . '/Console';
        $configUrl = $base . '/config';

        return [
            'title' => self::getMenuName(),
            'icon'  => self::getIcon(),
            'page'  => $consoleUrl,
            'links' => [
                'search' => $consoleUrl,
                'config' => $configUrl,
            ],
        ];
    }
}
