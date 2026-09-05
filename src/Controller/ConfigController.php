<?php

declare(strict_types=1);

namespace GlpiPlugin\Cliconsole\Controller;

use Config as GlpiConfig;
use Glpi\Controller\AbstractController;
use Glpi\Security\ReAuth\ReAuthManager;
use GlpiPlugin\Cliconsole\CliConsole;
use GlpiPlugin\Cliconsole\VersionChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

        if (class_exists(ReAuthManager::class)) {
            ReAuthManager::getInstance()->checkReAuthenticationOrRedirect();
        }

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
        if (class_exists(ReAuthManager::class)) {
            ReAuthManager::getInstance()->checkReAuthenticationOrRedirect();
        }

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

            GlpiConfig::setConfigurationValues('plugin:cliconsole', [
                'php_binary' => $phpBinary,
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
}
