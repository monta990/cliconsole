<?php

declare(strict_types=1);

namespace GlpiPlugin\Cliconsole\Controller;

use Config as GlpiConfig;
use Glpi\Controller\AbstractController;
use Glpi\Security\ReAuth\ReAuthManager;
use GlpiPlugin\Cliconsole\CliConsole;
use GlpiPlugin\Cliconsole\RuntimeLimits;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

        $this->checkReAuthPage();

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
        [$phpBinary] = CliConsole::resolveExecutables();

        $userId = CliConsole::getCurrentUserId();
        $username = trim((string) ($_SESSION['glpiname'] ?? ''));
        if ($username === '') {
            $username = __('Unknown user', 'cliconsole');
        }

        $baseDir = CliConsole::getSessionBaseDir();
        $allocationLock = CliConsole::acquireSessionAllocationLock($baseDir);
        try {
            // Session creation, cleanup, and slot accounting are serialized as one
            // operation. A ready session reserves a slot immediately, preventing
            // concurrent Start requests from creating an unbounded number of
            // abandoned session directories.
            CliConsole::cleanupOldSessions($baseDir);
            if (CliConsole::countReservedSessions($baseDir) >= RuntimeLimits::MAX_ACTIVE_SESSIONS) {
                throw new BadRequestHttpException(__('The maximum number of active terminal sessions has been reached.', 'cliconsole'));
            }

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
                @chmod($sessionDir . '/' . $file, 0600);
            }

            touch($sessionDir . '/heartbeat');
            chmod($sessionDir . '/heartbeat', 0600);

            $status = [
                'state' => 'ready',
                'exit_code' => null,
                'started_at' => null,
                'finished_at' => null,
            ];
            if (file_put_contents(
                $sessionDir . '/status.json',
                json_encode($status, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                LOCK_EX
            ) === false) {
                CliConsole::removeSession($sessionDir);
                throw new \RuntimeException(__('Unable to initialize the terminal session.', 'cliconsole'));
            }
            chmod($sessionDir . '/status.json', 0600);

            $metadata = [
                'user_id' => $userId,
                'username' => $username,
                'created_at' => time(),
            ];
            if (file_put_contents(
                $sessionDir . '/metadata.json',
                json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                LOCK_EX
            ) === false) {
                CliConsole::removeSession($sessionDir);
                throw new \RuntimeException(__('Unable to initialize the terminal session.', 'cliconsole'));
            }
            chmod($sessionDir . '/metadata.json', 0600);

            return new JsonResponse(['success' => true, 'session' => $sessionId]);
        } finally {
            CliConsole::releaseSessionAllocationLock($allocationLock);
        }
    }

    #[Route('/ajax/Console/Run', name: 'cliconsole_console_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $this->loadPluginLanguage();
        $this->checkAccess();
        $this->checkReAuth();

        $sessionId = $request->request->getString('session');
        if (!preg_match('/\A[a-f0-9]{64}\z/', $sessionId)) {
            throw new BadRequestHttpException(__('Invalid terminal session.', 'cliconsole'));
        }

        $sessionDir = CliConsole::getSessionDir($sessionId);
        $metadata = CliConsole::assertSessionOwner($sessionDir);
        $statusFile = $sessionDir . '/status.json';
        $status = CliConsole::readStatus($statusFile);
        if (($status['state'] ?? '') !== 'ready') {
            throw new BadRequestHttpException(__('This terminal session has already been started or is no longer available.', 'cliconsole'));
        }

        $commandLine = trim($request->request->getString('command'));
        if ($commandLine === '') {
            throw new BadRequestHttpException(__('No command was provided.', 'cliconsole'));
        }

        $arguments = CliConsole::parseCommand($commandLine);
        [$phpBinary, $console] = CliConsole::resolveExecutables();
        $arguments = CliConsole::normalizeCommand($arguments, $phpBinary, $console);

        $baseDir = CliConsole::getSessionBaseDir();
        $allocationLock = CliConsole::acquireSessionAllocationLock($baseDir);
        try {
            // Serialize the active-session check with the state transition to
            // prevent concurrent Run requests from exceeding the limit.
            $status = CliConsole::readStatus($statusFile);
            if (($status['state'] ?? '') !== 'ready') {
                throw new BadRequestHttpException(__('This terminal session has already been started or is no longer available.', 'cliconsole'));
            }

            CliConsole::cleanupOldSessions($baseDir);

            // The Start endpoint already reserved one of the finite session slots.
            // Only the ready -> starting transition must be atomic here so the same
            // session cannot be launched twice.
            // Create the marker and transition to starting while holding the same
            // allocation lock used by the active-session check.
            $workerLock = @fopen($sessionDir . '/worker.lock', 'x');
            if (!is_resource($workerLock)) {
                throw new BadRequestHttpException(__('This terminal session is already in use.', 'cliconsole'));
            }
            fclose($workerLock);
            @chmod($sessionDir . '/worker.lock', 0600);

            @file_put_contents(
                $statusFile,
                json_encode([
                    'state' => 'starting',
                    'exit_code' => null,
                    'started_at' => time(),
                    'finished_at' => null,
                ], JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
        } finally {
            CliConsole::releaseSessionAllocationLock($allocationLock);
        }

        $userId = (int) ($metadata['user_id'] ?? CliConsole::getCurrentUserId());
        $username = (string) ($metadata['username'] ?? ($_SESSION['glpiname'] ?? ''));
        $redactedCommand = CliConsole::redactCommand($arguments);

        $metadata['command'] = $redactedCommand;
        $metadata['php_binary'] = $phpBinary;
        $metadata['started_at'] = time();
        if (file_put_contents(
            $sessionDir . '/metadata.json',
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            LOCK_EX
        ) === false) {
            @unlink($sessionDir . '/worker.lock');
            CliConsole::removeSession($sessionDir);
            throw new \RuntimeException(__('Unable to initialize the terminal session.', 'cliconsole'));
        }
        chmod($sessionDir . '/metadata.json', 0600);

        try {
            CliConsole::writeAudit([
            'event' => 'start',
            'session_id' => $sessionId,
            'user_id' => $userId,
            'username' => $username,
            'command' => $redactedCommand,
            'php_binary' => $phpBinary,
        ]);
        } catch (\Throwable $e) {
            @unlink($sessionDir . '/worker.lock');
            CliConsole::removeSession($sessionDir);
            throw new \RuntimeException(
                __('Unable to securely initialize the CLI Console audit log.', 'cliconsole'),
                0,
                $e
            );
        }

        $worker = dirname(__DIR__) . '/TerminalWorker.php';

        $process = proc_open(
            [
                $phpBinary,
                $worker,
                $sessionId,
                $sessionDir,
                json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $phpBinary,
                $console,
                GLPI_ROOT,
                CliConsole::getAuditLogPath(),
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $sessionDir . '/worker.log', 'ab'],
                2 => ['file', $sessionDir . '/worker-error.log', 'ab'],
            ],
            $pipes,
            GLPI_ROOT,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            @unlink($sessionDir . '/worker.lock');
            @file_put_contents(
                $statusFile,
                json_encode(['state' => 'error', 'exit_code' => 127, 'started_at' => time(), 'finished_at' => time()], JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
            CliConsole::writeAudit([
                'event' => 'finish',
                'session_id' => $sessionId,
                'user_id' => $userId,
                'username' => $username,
                'command' => $redactedCommand,
                'state' => 'error',
                'exit_code' => 127,
                'duration_seconds' => 0,
                'php_binary' => $phpBinary,
            ]);
            throw new \RuntimeException(__('Unable to start the terminal worker.', 'cliconsole'));
        }

        // Deliberately detach the worker from this short HTTP request.
        unset($pipes, $process, $CFG_GLPI);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/ajax/Console/Input', name: 'cliconsole_console_input', methods: ['POST'])]
    public function input(Request $request): Response
    {
        $this->loadPluginLanguage();
        $this->checkAccess();
        $this->checkReAuth();

        $sessionDir = CliConsole::getSessionDir($request->request->getString('session'));
        CliConsole::assertSessionOwner($sessionDir);
        $status = CliConsole::readStatus($sessionDir . '/status.json');
        if (!in_array(($status['state'] ?? ''), ['starting', 'running'], true)) {
            throw new BadRequestHttpException(__('This terminal session is not accepting input.', 'cliconsole'));
        }

        $value = $request->request->getString('input');
        if (strlen($value) > RuntimeLimits::MAX_INPUT_LENGTH) {
            throw new BadRequestHttpException(__('The input exceeds the maximum allowed length.', 'cliconsole'));
        }

        if ($value !== '') {
            $queueFile = $sessionDir . '/input.queue';
            clearstatcache(true, $queueFile);
            $currentSize = (int) (@filesize($queueFile) ?: 0);
            $payload = json_encode(['type' => 'input', 'value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            if ($currentSize + strlen($payload) > RuntimeLimits::MAX_QUEUE_SIZE) {
                throw new BadRequestHttpException(__('The terminal input queue is full.', 'cliconsole'));
            }
            file_put_contents($queueFile, $payload, FILE_APPEND | LOCK_EX);
            @chmod($queueFile, 0600);
            @touch($sessionDir . '/heartbeat');
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route('/ajax/Console/Output', name: 'cliconsole_console_output', methods: ['POST'])]
    public function output(Request $request): Response
    {
        $this->loadPluginLanguage();
        $this->checkAccess();
        $this->checkReAuth();

        $sessionDir = CliConsole::getSessionDir($request->request->getString('session'));
        CliConsole::assertSessionOwner($sessionDir);
        touch($sessionDir . '/heartbeat');
        chmod($sessionDir . '/heartbeat', 0600);
        $stdoutOffset = max(0, $request->request->getInt('stdout_offset'));
        $stderrOffset = max(0, $request->request->getInt('stderr_offset'));

        [$stdout, $stdoutOffset] = CliConsole::readTailFromOffset($sessionDir . '/stdout.log', $stdoutOffset);
        [$stderr, $stderrOffset] = CliConsole::readTailFromOffset($sessionDir . '/stderr.log', $stderrOffset);

        return new JsonResponse([
            'success' => true,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'stdout_offset' => $stdoutOffset,
            'stderr_offset' => $stderrOffset,
            'status' => CliConsole::readStatus($sessionDir . '/status.json'),
        ]);
    }

    #[Route('/ajax/Console/Control', name: 'cliconsole_console_control', methods: ['POST'])]
    public function control(Request $request): Response
    {
        $this->loadPluginLanguage();
        $this->checkAccess();
        $this->checkReAuth();

        $sessionDir = CliConsole::getSessionDir($request->request->getString('session'));
        CliConsole::assertSessionOwner($sessionDir);
        $status = CliConsole::readStatus($sessionDir . '/status.json');
        if (!in_array(($status['state'] ?? ''), ['starting', 'running'], true)) {
            throw new BadRequestHttpException(__('This terminal session is no longer active.', 'cliconsole'));
        }

        $action = $request->request->getString('action');
        if (!in_array($action, ['terminate', 'eof'], true)) {
            throw new BadRequestHttpException(__('Unsupported terminal action.', 'cliconsole'));
        }

        $queueFile = $sessionDir . '/input.queue';
        $payload = json_encode(['type' => $action], JSON_UNESCAPED_SLASHES) . "\n";
        clearstatcache(true, $queueFile);
        $currentSize = (int) (@filesize($queueFile) ?: 0);
        if ($currentSize + strlen($payload) > RuntimeLimits::MAX_QUEUE_SIZE) {
            throw new BadRequestHttpException(__('The terminal input queue is full.', 'cliconsole'));
        }
        file_put_contents($queueFile, $payload, FILE_APPEND | LOCK_EX);
        @chmod($queueFile, 0600);
        @touch($sessionDir . '/heartbeat');

        return new JsonResponse(['success' => true]);
    }

    private function checkAccess(): void
    {
        if (!CliConsole::isSuperAdmin()) {
            throw new AccessDeniedHttpException();
        }
    }

    private function checkReAuthPage(): void
    {
        if (!class_exists(ReAuthManager::class)) {
            throw new AccessDeniedHttpException(
                __('Re-authentication support is unavailable.', 'cliconsole')
            );
        }

        ReAuthManager::getInstance()->checkReAuthenticationOrRedirect();
    }

    private function checkReAuth(): void
    {
        if (!class_exists(ReAuthManager::class)) {
            throw new AccessDeniedHttpException(
                __('Re-authentication support is unavailable.', 'cliconsole')
            );
        }

        if (!ReAuthManager::getInstance()->isReAuthenticated()) {
            throw new AccessDeniedHttpException(
                __('Re-authentication is required. Reload the console page and verify your identity again.', 'cliconsole')
            );
        }
    }
}
