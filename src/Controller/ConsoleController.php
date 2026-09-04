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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ConsoleController extends AbstractController
{
    #[Route('/Console', name: 'cliconsole_console', methods: 'GET')]
    public function index(): Response
    {
        $this->checkUseRight();

        // GLPI 12: open the page only after sudo/re-authentication.
        if (class_exists(ReAuthManager::class)) {
            ReAuthManager::getInstance()->checkReAuthenticationOrRedirect();
        }

        $config = $this->getConfig();
        global $CFG_GLPI;

        return $this->render('@cliconsole/console.html.twig', [
            'php_binary' => $config['php_binary'] ?? '',
            'run_url' => rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole/ajax/Console/Run',
            'config_url' => rtrim($CFG_GLPI['root_doc'], '/') . '/plugins/cliconsole/config',
        ]);
    }

    #[Route('/ajax/Console/Run', name: 'cliconsole_console_run', methods: ['GET', 'POST'])]
    public function run(Request $request): Response
    {
        $this->checkUseRight();

        if (!$request->isMethod('POST')) {
            throw new BadRequestHttpException(__('This endpoint accepts POST requests only.', 'cliconsole'));
        }

        $commandLine = trim($request->request->getString('command'));
        if ($commandLine === '') {
            throw new BadRequestHttpException(__('No command was provided.', 'cliconsole'));
        }

        $arguments = $this->parseCommand($commandLine);
        [$phpBinary, $console] = $this->resolveExecutables();

        // Keep the sensitive streaming action tied to the same session and active sudo window.
        if (class_exists(ReAuthManager::class) && !ReAuthManager::getInstance()->isReAuthenticated()) {
            throw new AccessDeniedHttpException(__('Re-authentication is required. Reload the console page and verify your identity again.', 'cliconsole'));
        }

        $command = [$phpBinary, $console, ...$arguments];

        return new StreamedResponse(
            function () use ($command, $commandLine): void {
                $this->streamProcess($command, $commandLine);
            },
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'X-Accel-Buffering' => 'no',
                'X-LiteSpeed-Cache-Control' => 'no-cache',
            ]
        );
    }

    private function checkUseRight(): void
    {
        if (!CliConsole::isSuperAdmin()) {
            throw new AccessDeniedHttpException();
        }
    }

    /** @return array<string, mixed> */
    private function getConfig(): array
    {
        return GlpiConfig::getConfigurationValues('plugin:cliconsole');
    }

    /** @return array{0: string, 1: string} */
    private function resolveExecutables(): array
    {
        $config = $this->getConfig();
        $phpBinary = trim((string) ($config['php_binary'] ?? ''));

        if ($phpBinary === '') {
            throw new BadRequestHttpException(__('Configure the PHP CLI binary path before using the console.', 'cliconsole'));
        }

        $glpiRoot = realpath(GLPI_ROOT);
        $console = realpath(GLPI_ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console');
        $configuredPhp = realpath($phpBinary);

        if ($glpiRoot === false || $console === false) {
            throw new \RuntimeException(__('GLPI bin/console could not be found.', 'cliconsole'));
        }

        if ($configuredPhp === false || !is_file($configuredPhp) || !is_executable($configuredPhp)) {
            throw new BadRequestHttpException(__('The configured PHP CLI binary does not exist or is not executable.', 'cliconsole'));
        }

        // The executable script is fixed to this GLPI installation; symlink escape is rejected.
        $expectedBin = $glpiRoot . DIRECTORY_SEPARATOR . 'bin';
        if (dirname($console) !== $expectedBin) {
            throw new \RuntimeException(__('The detected bin/console is outside the GLPI root.', 'cliconsole'));
        }

        return [$configuredPhp, $console];
    }

    /** @return list<string> */
    private function parseCommand(string $input): array
    {
        if (str_contains($input, "\0") || preg_match('/[;|&`$<>\n\r]/', $input) === 1) {
            throw new BadRequestHttpException(__('Shell operators are not allowed. Enter only a GLPI console command and its arguments.', 'cliconsole'));
        }

        $matches = [];
        if (preg_match_all('/[^\s"\']+|"[^"]*"|\'[^\']*\'/u', $input, $matches) === false) {
            throw new BadRequestHttpException(__('The command could not be parsed.', 'cliconsole'));
        }

        $arguments = [];
        foreach ($matches[0] as $token) {
            $first = $token[0];
            $last = $token[strlen($token) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $token = substr($token, 1, -1);
            }
            if ($token === '' || str_contains($token, "\0")) {
                throw new BadRequestHttpException(__('An empty or invalid argument was supplied.', 'cliconsole'));
            }
            $arguments[] = $token;
        }

        if ($arguments === []) {
            throw new BadRequestHttpException(__('No command was provided.', 'cliconsole'));
        }

        return $arguments;
    }

    /** @param list<string> $command */
    private function streamProcess(array $command, string $commandLine): void
    {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        echo "CLI Console\n";
        echo "Command: {$commandLine}\n";
        echo str_repeat('-', 72) . "\n\n";
        // Help break common FastCGI/proxy buffering on shared hosting.
        echo str_repeat(' ', 1024);
        flush();

        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            GLPI_ROOT,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            echo "ERROR: Unable to start the PHP CLI process.\n";
            return;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if ($read !== []) {
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 100000);

                foreach ($read as $pipe) {
                    $data = stream_get_contents($pipe);
                    if ($data === false || $data === '') {
                        continue;
                    }
                    if ($pipe === $pipes[2]) {
                        echo "[stderr] ";
                    }
                    echo $data;
                    flush();
                }
            }

            if (connection_aborted()) {
                proc_terminate($process);
                break;
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
        }

        foreach ([1, 2] as $index) {
            $remaining = stream_get_contents($pipes[$index]);
            if ($remaining !== false && $remaining !== '') {
                echo $index === 2 ? "[stderr] " : '';
                echo $remaining;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        echo "\n" . str_repeat('-', 72) . "\n";
        echo "Exit code: {$exitCode}\n";
        echo $exitCode === 0 ? "Status: SUCCESS\n" : "Status: FAILED\n";
        flush();
    }
}
