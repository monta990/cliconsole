<?php

declare(strict_types=1);

namespace GlpiPlugin\Cliconsole\Controller;

use Config as GlpiConfig;
use Glpi\Controller\AbstractController;
use Glpi\Security\ReAuth\ReAuthManager;
use GlpiPlugin\Cliconsole\CliConsole;
use GlpiPlugin\Cliconsole\VersionChecker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Session;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigController extends AbstractController
{
    private function loadPluginLanguage(): void
    {
        \Plugin::loadLang('cliconsole');
    }

    #[Route('/config', name: 'cliconsole_config', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $this->loadPluginLanguage();
        $this->checkAccess();

        if ($request->isMethod('POST')) {
            return $this->save($request);
        }

        $this->checkReAuthPage();

        global $CFG_GLPI;
        return $this->render('@cliconsole/configuration.html.twig', [
            'config' => GlpiConfig::getConfigurationValues('plugin:cliconsole'),
            'save_url' => rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole/config',
            'console_url' => rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole/Console',
            'plugin_version' => PLUGIN_CLICONSOLE_VERSION,
            'version_status' => VersionChecker::getStatus(),
            'version_repository' => VersionChecker::getRepositoryUrl(),
        ]);
    }


    private function save(Request $request): Response
    {
        $this->checkReAuth();

        $phpBinary = trim($request->request->getString('php_binary'));

        try {
            if ($phpBinary === '' || str_contains($phpBinary, "\0") || !str_starts_with($phpBinary, '/')) {
                throw new \InvalidArgumentException(
                    __('Enter an absolute path to the PHP CLI executable.', 'cliconsole')
                );
            }

            $resolved = realpath($phpBinary);
            if ($resolved === false || !is_file($resolved) || !is_executable($resolved)) {
                throw new \InvalidArgumentException(
                    __('The configured PHP CLI binary does not exist or is not executable.', 'cliconsole')
                );
            }

            // Validate that the configured executable is actually PHP CLI before storing it.
            try {
                CliConsole::validatePhpCliBinary($resolved);
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException(
                    __('The configured PHP CLI executable is not a valid PHP CLI interpreter.', 'cliconsole'),
                    0,
                    $e
                );
            }

            GlpiConfig::setConfigurationValues('plugin:cliconsole', [
                'php_binary' => $resolved,
            ]);

            Session::addMessageAfterRedirect(
                __('CLI Console configuration saved successfully.', 'cliconsole'),
                false,
                INFO
            );
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect(
                $e->getMessage(),
                false,
                ERROR
            );
        }

        global $CFG_GLPI;
        return new \Symfony\Component\HttpFoundation\RedirectResponse(
            rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole/config'
        );
    }

    private function checkAccess(): void
    {
        if (!CliConsole::isSuperAdmin()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }
    }

    private function checkReAuthPage(): void
    {
        if (!class_exists(ReAuthManager::class)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                __('Re-authentication support is unavailable.', 'cliconsole')
            );
        }

        ReAuthManager::getInstance()->checkReAuthenticationOrRedirect();
    }

    private function checkReAuth(): void
    {
        if (!class_exists(ReAuthManager::class)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                __('Re-authentication support is unavailable.', 'cliconsole')
            );
        }

        if (!ReAuthManager::getInstance()->isReAuthenticated()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                __('Re-authentication is required. Reload the console page and verify your identity again.', 'cliconsole')
            );
        }
    }
}
