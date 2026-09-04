<?php

declare(strict_types=1);

namespace GlpiPlugin\Cliconsole\Controller;

use Config as GlpiConfig;
use Glpi\Controller\AbstractController;
use Glpi\Security\ReAuth\ReAuthManager;
use GlpiPlugin\Cliconsole\CliConsole;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigController extends AbstractController
{
    #[Route('/config', name: 'cliconsole_config', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
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
            'version_check_url' => rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole/config/version-check',
        ]);
    }

    #[Route('/config/version-check', name: 'cliconsole_version_check', methods: 'GET')]
    public function checkVersion(): Response
    {
        $this->checkAccess();

        $url = 'https://raw.githubusercontent.com/monta990/cliconsole/main/setup.php';
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'header' => "User-Agent: GLPI-CLI-Console/" . PLUGIN_CLICONSOLE_VERSION . "\r\nAccept: text/plain\r\n",
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            return new JsonResponse([
                'success' => false,
                'message' => __('Unable to contact GitHub right now.', 'cliconsole'),
            ], Response::HTTP_BAD_GATEWAY);
        }

        if (preg_match("/PLUGIN_CLICONSOLE_VERSION\\'?,\s*['\"]([^'\"]+)['\"];/", $content, $matches) !== 1) {
            return new JsonResponse([
                'success' => false,
                'message' => __('The GitHub project does not expose a readable plugin version yet.', 'cliconsole'),
            ], Response::HTTP_NOT_FOUND);
        }

        $remoteVersion = $matches[1];
        $currentVersion = PLUGIN_CLICONSOLE_VERSION;
        $updateAvailable = version_compare($remoteVersion, $currentVersion, '>');

        return new JsonResponse([
            'success' => true,
            'current' => $currentVersion,
            'remote' => $remoteVersion,
            'update_available' => $updateAvailable,
            'message' => $updateAvailable
                ? sprintf(__('Version %s is available on GitHub.', 'cliconsole'), $remoteVersion)
                : __('You are running the latest version published on GitHub.', 'cliconsole'),
        ]);
    }

    private function save(Request $request): Response
    {
        if (class_exists(ReAuthManager::class)) {
            ReAuthManager::getInstance()->checkReAuthenticationOrRedirect();
        }

        $phpBinary = trim($request->request->getString('php_binary'));
        if ($phpBinary === '' || str_contains($phpBinary, "\0") || !str_starts_with($phpBinary, '/')) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(
                __('Enter an absolute path to the PHP CLI executable.', 'cliconsole')
            );
        }

        $resolved = realpath($phpBinary);
        if ($resolved === false || !is_file($resolved) || !is_executable($resolved)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(
                __('The configured PHP CLI binary does not exist or is not executable.', 'cliconsole')
            );
        }

        GlpiConfig::setConfigurationValues('plugin:cliconsole', [
            'php_binary' => $phpBinary,
        ]);

        global $CFG_GLPI;
        return new RedirectResponse(
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
