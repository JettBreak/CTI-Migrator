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
 * Output goes to var/log/<log name>.log and <log name>-error.log (WorkerRole::logName()).
 */
#[WhenNot(env: 'test')]
#[AsAlias(WorkerLauncher::class)]
final class ProcessWorkerLauncher implements WorkerLauncher
{
    /**
     * The command line of a worker: messenger:consume of its role's transports (WorkerRole::transports()).
     * Runs in the same debug mode as the web app: a --no-debug process never recompiles its container, so it
     * would miss config changes, and it would use different cache directories (so no heartbeat or stop signal).
     * No --keepalive: it needs the pcntl extension, which Windows does not have, and the worker refuses to start.
     *
     * @return list<string>
     */
    public static function arguments(WorkerRole $role): array
    {
        return ['bin/console', 'messenger:consume', ...$role->transports(), '--sleep=1', '-vv', '--no-ansi', '--no-interaction'];
    }

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.logs_dir%')] private readonly string $logsDir,
    ) {
    }

    public function launch(WorkerRole $role): int
    {
        $php = (new PhpExecutableFinder())->find(false);
        if (false === $php) {
            throw new \RuntimeException('Could not find the PHP CLI executable to start the worker.');
        }
        $log = $this->logsDir.'/'.$role->logName().'.log';
        $errorLog = $this->logsDir.'/'.$role->logName().'-error.log';

        // The worker must not inherit anything from the web server process. Started as a plain child
        // of php-cgi it inherits the FastCGI listening socket; when the web server later recycles
        // php-cgi on the same port, that stale socket keeps accepting requests nobody answers and
        // the whole site hangs (seen with `symfony serve` on Windows).
        if ('\\' === \DIRECTORY_SEPARATOR) {
            // WMI creates the process outside our process tree, with no inherited handles and no window.
            $commandLine = sprintf(
                'cmd.exe /d /s /c ""%s" %s >> "%s" 2>> "%s""',
                $php, implode(' ', self::arguments($role)), $log, $errorLog,
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
                .implode(' ', array_map('escapeshellarg', self::arguments($role))).' >> "$LOG" 2>> "$ERRLOG" < /dev/null & echo $!',
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

    /**
     * Processes running one of this app's worker commands (see arguments()), with their role. A worker can be a
     * chain of processes (on Windows: cmd.exe, a launcher shim, php.exe), so only the top of each chain counts: a
     * process whose parent is not one of them. On Linux a process is this app's if it runs in this project
     * directory; one whose directory cannot be read (another user's) is counted, as it may well be.
     */
    public function runningWorkers(): array
    {
        $processes = []; // pid => parent pid
        $roles = []; // pid => role
        if ('\\' === \DIRECTORY_SEPARATOR) {
            $process = new Process(['powershell', '-NoProfile', '-NonInteractive', '-Command',
                'Get-CimInstance Win32_Process -Filter "CommandLine LIKE \'%messenger:consume%\'" | ForEach-Object { "$($_.ProcessId) $($_.ParentProcessId) $($_.CommandLine)" }']);
            $process->run();
            foreach (preg_split('/\R/', $process->getOutput()) as $line) {
                if (preg_match('/^(\d+) (\d+) (.*)$/', trim($line), $m) && null !== ($role = $this->roleOf($m[3])) && !str_contains($m[3], 'Get-CimInstance')) {
                    $processes[(int) $m[1]] = (int) $m[2];
                    $roles[(int) $m[1]] = $role;
                }
            }
        } else {
            $process = new Process(['ps', '-eo', 'pid=,ppid=,args=']);
            $process->run();
            $project = realpath($this->projectDir);
            foreach (preg_split('/\R/', $process->getOutput()) as $line) {
                if (!preg_match('/^(\d+)\s+(\d+)\s+(.*)$/', trim($line), $m) || null === ($role = $this->roleOf($m[3]))) {
                    continue;
                }
                $cwd = @readlink('/proc/'.$m[1].'/cwd');
                if (false === $cwd || $cwd === $project) {
                    $processes[(int) $m[1]] = (int) $m[2];
                    $roles[(int) $m[1]] = $role;
                }
            }
        }

        $tops = array_filter($processes, static fn (int $parent) => !isset($processes[$parent]));

        return array_intersect_key($roles, $tops);
    }

    /** The role of a worker command line, from the transport names right after messenger:consume. */
    private function roleOf(string $commandLine): ?WorkerRole
    {
        $at = strpos($commandLine, 'messenger:consume');
        if (false === $at) {
            return null;
        }
        $transports = [];
        foreach (preg_split('/\s+/', trim(substr($commandLine, $at + \strlen('messenger:consume')))) as $word) {
            if (!preg_match('/^\w+$/', $word)) {
                break; // an option, a redirection or the end of a quoted command
            }
            $transports[] = $word;
        }

        return WorkerRole::fromTransports($transports);
    }
}
