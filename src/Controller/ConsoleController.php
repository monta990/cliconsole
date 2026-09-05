<?php

declare(strict_types=1);

namespace GlpiPlugin\Cliconsole\Controller;

use Config as GlpiConfig;
use Glpi\Controller\AbstractController;
use Glpi\Security\ReAuth\ReAuthManager;
use GlpiPlugin\Cliconsole\CliConsole;
use GlpiPlugin\Cliconsole\VersionChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ConsoleController extends AbstractController
{
    private function loadPluginLanguage(): void
    {
        \Plugin::loadLang('cliconsole');
    }

    #[Route('/Console', name: 'cliconsole_console', methods: ['GET'])]
    public function index(): Response
    {
        $this->loadPluginLanguage();
        $this->checkAccess();

        if (class_exists(ReAuthManager::class)) {
            ReAuthManager::getInstance()->checkReAuthenticationOrRedirect();
        }

        global $CFG_GLPI;
        $config = GlpiConfig::getConfigurationValues('plugin:cliconsole');

        return $this->render('@cliconsole/console.html.twig', [
            'php_binary' => $config['php_binary'] ?? '',
            'config_url' => rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole/config',
            'start_url' => rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole/ajax/Console/Start',
            'run_url' => rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole/ajax/Console/Run',
            'input_url' => rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole/ajax/Console/Input',
            'output_url' => rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole/ajax/Console/Output',
            'control_url' => rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole/ajax/Console/Control',
            'version_status' => VersionChecker::getStatus(),
        ]);
    }

    #[Route('/ajax/Console/Start', name: 'cliconsole_console_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $this->loadPluginLanguage();
        $this->checkAccess();
        $this->checkReAuth();

        $commandLine = trim($request->request->getString('command'));
        if ($commandLine === '') {
            throw new BadRequestHttpException(__('No command was provided.', 'cliconsole'));
        }

        CliConsole::parseCommand($commandLine);
        CliConsole::resolveExecutables();

        $baseDir = CliConsole::getSessionBaseDir();
        CliConsole::cleanupOldSessions($baseDir);
        $sessionId = bin2hex(random_bytes(32));
        $sessionDir = $baseDir . DIRECTORY_SEPARATOR . $sessionId;

        if (!mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
            throw new \RuntimeException(__('Unable to create the terminal session.', 'cliconsole'));
        }

        foreach (['input.queue', 'stdout.log', 'stderr.log'] as $file) {
            if (file_put_contents($sessionDir . '/' . $file, '') === false) {
                CliConsole::removeSession($sessionDir);
                throw new \RuntimeException(__('Unable to initialize the terminal session.', 'cliconsole'));
            }
        }

        file_put_contents(
            $sessionDir . '/status.json',
            json_encode(['state'=>'ready','exit_code'=>null,'started_at'=>null,'finished_at'=>null], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        return new JsonResponse(['success'=>true,'session'=>$sessionId]);
    }

    #[Route('/ajax/Console/Run', name: 'cliconsole_console_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $this->loadPluginLanguage();
        $this->checkAccess();
        $this->checkReAuth();

        $sessionId=$request->request->getString('session');
        if (!preg_match('/\A[a-f0-9]{64}\z/',$sessionId)) {
            throw new BadRequestHttpException(__('Invalid terminal session.', 'cliconsole'));
        }

        $sessionDir=CliConsole::getSessionDir($sessionId);
        $commandLine=trim($request->request->getString('command'));
        if ($commandLine==='') {
            throw new BadRequestHttpException(__('No command was provided.', 'cliconsole'));
        }

        $arguments=CliConsole::parseCommand($commandLine);
        [$phpBinary,$console]=CliConsole::resolveExecutables();

        $worker=dirname(__DIR__) . '/TerminalWorker.php';

        $process=proc_open(
            [
                $phpBinary,
                $worker,
                $sessionId,
                $sessionDir,
                json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $phpBinary,
                $console,
                GLPI_ROOT,
            ],
            [
                0=>['file','/dev/null','r'],
                1=>['file',$sessionDir.'/worker.log','ab'],
                2=>['file',$sessionDir.'/worker-error.log','ab'],
            ],
            $pipes,
            GLPI_ROOT,
            null,
            ['bypass_shell'=>true]
        );

        if (!is_resource($process)) {
            CliConsole::removeSession($sessionDir);
            throw new \RuntimeException(__('Unable to start the terminal worker.', 'cliconsole'));
        }

        // Deliberately detach the worker from this short HTTP request.
        unset($pipes,$process);

        return new JsonResponse(['success'=>true]);
    }

    #[Route('/ajax/Console/Input', name: 'cliconsole_console_input', methods: ['POST'])]
    public function input(Request $request): Response
    {
        $this->loadPluginLanguage();
        $this->checkAccess();
        $this->checkReAuth();

        $sessionDir=CliConsole::getSessionDir($request->request->getString('session'));
        $value=$request->request->getString('input');

        if ($value!=='') {
            file_put_contents(
                $sessionDir.'/input.queue',
                json_encode(['type'=>'input','value'=>$value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
                FILE_APPEND | LOCK_EX
            );
        }

        return new JsonResponse(['success'=>true]);
    }

    #[Route('/ajax/Console/Output', name: 'cliconsole_console_output', methods: ['GET'])]
    public function output(Request $request): Response
    {
        $this->loadPluginLanguage();
        $this->checkAccess();
        $this->checkReAuth();

        $sessionDir=CliConsole::getSessionDir($request->query->getString('session'));
        $stdoutOffset=max(0,$request->query->getInt('stdout_offset'));
        $stderrOffset=max(0,$request->query->getInt('stderr_offset'));

        [$stdout,$stdoutSize]=CliConsole::readTailFromOffset($sessionDir.'/stdout.log',$stdoutOffset);
        [$stderr,$stderrSize]=CliConsole::readTailFromOffset($sessionDir.'/stderr.log',$stderrOffset);

        return new JsonResponse([
            'success'=>true,
            'stdout'=>$stdout,
            'stderr'=>$stderr,
            'stdout_offset'=>$stdoutSize,
            'stderr_offset'=>$stderrSize,
            'status'=>CliConsole::readStatus($sessionDir.'/status.json'),
        ]);
    }

    #[Route('/ajax/Console/Control', name: 'cliconsole_console_control', methods: ['POST'])]
    public function control(Request $request): Response
    {
        $this->loadPluginLanguage();
        $this->checkAccess();
        $this->checkReAuth();

        $sessionDir=CliConsole::getSessionDir($request->request->getString('session'));
        $action=$request->request->getString('action');

        if (!in_array($action,['terminate','eof'],true)) {
            throw new BadRequestHttpException(__('Unsupported terminal action.', 'cliconsole'));
        }

        file_put_contents(
            $sessionDir.'/input.queue',
            json_encode(['type'=>$action], JSON_UNESCAPED_SLASHES)."\n",
            FILE_APPEND | LOCK_EX
        );

        return new JsonResponse(['success'=>true]);
    }

    private function checkAccess(): void
    {
        if (!CliConsole::isSuperAdmin()) {
            throw new AccessDeniedHttpException();
        }
    }

    private function checkReAuth(): void
    {
        if (
            class_exists(ReAuthManager::class)
            && !ReAuthManager::getInstance()->isReAuthenticated()
        ) {
            throw new AccessDeniedHttpException(
                __('Re-authentication is required. Reload the console page and verify your identity again.', 'cliconsole')
            );
        }
    }
}
