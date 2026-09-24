<?php

namespace App\Worker;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs `messenger:consume` as a detached OS process that inherits no handles from the web server:
 * created through WMI on Windows, via setsid with inherited descriptors closed elsewhere.
 * Output goes to var/log/worker.log and worker-error.log.
 */
#[WhenNot(env: 'test')]
#[AsAlias(WorkerLauncher::class)]
final class ProcessWorkerLauncher implements WorkerLauncher
{
    /**
     * async = queued exports and large batches; scheduler_default = recurring maintenance (App\Schedule).
     * Runs in the same debug mode as the web app: a --no-debug process never recompiles its container, so it
     * would miss config changes, and it would use different cache directories (so no heartbeat or stop signal).
     */
    public const ARGUMENTS = ['bin/console', 'messenger:consume', 'async', 'scheduler_default', '--sleep=1', '-vv', '--no-ansi', '--no-interaction'];

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.logs_dir%')] private readonly string $logsDir,
    ) {
    }

    public function launch(): int
    {
        $php = (new PhpExecutableFinder())->find(false);
        if (false === $php) {
            throw new \RuntimeException('Could not find the PHP CLI executable to start the worker.');
        }
        $log = $this->logsDir.'/worker.log';
        $errorLog = $this->logsDir.'/worker-error.log';

        // The worker must not inherit anything from the web server process. Started as a plain child
        // of php-cgi it inherits the FastCGI listening socket; when the web server later recycles
        // php-cgi on the same port, that stale socket keeps accepting requests nobody answers and
        // the whole site hangs (seen with `symfony serve` on Windows).
        if ('\\' === \DIRECTORY_SEPARATOR) {
            // WMI creates the process outside our process tree, with no inherited handles and no window.
            $commandLine = sprintf(
                'cmd.exe /d /s /c ""%s" %s >> "%s" 2>> "%s""',
                $php, implode(' ', self::ARGUMENTS), $log, $errorLog,
            );
            $quote = static fn (string $s) => "'".str_replace("'", "''", $s)."'";
            $script = sprintf(
                '$startup = New-CimInstance -ClassName Win32_ProcessStartup -ClientOnly -Property @{ ShowWindow = [uint16]0 };'
                .' $r = Invoke-CimMethod -ClassName Win32_Process -MethodName Create -Arguments @{ CommandLine = %s; CurrentDirectory = %s; ProcessStartupInformation = $startup };'
                .' if ($r.ReturnValue -ne 0) { [Console]::Error.WriteLine("Win32_Process.Create failed with code " + $r.ReturnValue); exit 1 }; $r.ProcessId',
                $quote($commandLine), $quote($this->projectDir),
            );
            $process = new Process(['powershell', '-NoProfile', '-NonInteractive', '-Command', $script], $this->projectDir);
        } else {
            // Close every inherited descriptor above stderr, then detach into a new session.
            $process = Process::fromShellCommandline(
                'setsid sh -c \'for fd in $(ls /proc/$$/fd 2>/dev/null); do [ "$fd" -gt 2 ] && eval "exec $fd>&-"; done; exec "$0" "$@"\' "$PHP" '
                .implode(' ', array_map('escapeshellarg', self::ARGUMENTS)).' >> "$LOG" 2>> "$ERRLOG" < /dev/null & echo $!',
                $this->projectDir,
                ['PHP' => $php, 'LOG' => $log, 'ERRLOG' => $errorLog],
            );
        }

        $process->mustRun();
        $pid = (int) trim($process->getOutput());
        if ($pid <= 0) {
            throw new \RuntimeException('The worker did not report a process id: '.$process->getErrorOutput());
        }

        return $pid;
    }

    public function isAlive(int $pid): bool
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            $process = new Process(['tasklist', '/FI', 'PID eq '.$pid, '/FO', 'CSV', '/NH']);
            $process->run();

            return str_contains($process->getOutput(), '"'.$pid.'"');
        }

        return \function_exists('posix_kill') ? posix_kill($pid, 0) : is_dir('/proc/'.$pid);
    }

    public function kill(int $pid): void
    {
        $process = '\\' === \DIRECTORY_SEPARATOR
            ? new Process(['taskkill', '/PID', (string) $pid, '/T', '/F'])
            : new Process(['kill', '-TERM', (string) $pid]);
        $process->run();
    }
}
