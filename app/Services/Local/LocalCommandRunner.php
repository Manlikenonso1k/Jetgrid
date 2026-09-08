<?php

namespace App\Services\Local;

use App\Models\AuditLog;
use App\Services\Local\Platform\PlatformDetector;
use App\Services\Local\Platform\ToolLocator;
use App\Services\Privilege\BoundCommand;
use App\Services\Server\ProcessResult;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * The single place in local mode where a command can run. The order of the
 * checks mirrors App\Services\Privilege\CommandRunner and is just as deliberate:
 *
 *   1. whitelist        — an unknown key never becomes a process
 *   2. bind + validate  — every argument must match its exact pattern
 *   3. local-mode gate  — all four L0 checks, on reads as well as writes
 *   4. force dry run    — render + audit, execute nothing
 *   5. resolve binary   — an absent tool degrades, it does not throw
 *   6. audit open       — the record exists BEFORE the process starts
 *   7. execute          — argv array with an explicit cwd, never a shell
 *   8. audit close      — exit code, stdout, stderr, duration
 *
 * TWO DELIBERATE DIFFERENCES from the privileged runner, both of which are
 * choices rather than omissions:
 *
 *   - The production KILL SWITCH is not consulted. JETGRID_READONLY governs
 *     writes to the MANAGED SERVER, and it ships enabled; honouring it here
 *     would make local mode permanently inert on a default install while
 *     implying a protection it does not provide. LocalModeGate is this module's
 *     kill switch, and it is checked on every call including reads.
 *   - Only WRITES are audited. The run-state probe issues several read commands
 *     per project every five seconds while a dashboard is open; auditing those
 *     would add tens of thousands of rows a day and bury the records that
 *     matter. Every command that can change anything — start, stop, install,
 *     migrate, compose up — writes an audit record.
 *
 * Detached spawning uses proc_open rather than Symfony Process. Two things force
 * it: Symfony's Process destructor calls stop() on a running child, so a dev
 * server would be killed the moment the request that started it ended; and
 * Symfony offers no way to point a child's stdout at a file, which is what the
 * per-project log tail in L6 needs. proc_open is given the same argv ARRAY, so
 * no shell is involved there either.
 */
class LocalCommandRunner
{
    public function __construct(
        private readonly LocalCommandRegistry $registry,
        private readonly LocalModeGate $gate,
        private readonly PlatformDetector $platform,
        private readonly ToolLocator $tools,
    ) {}

    /**
     * @param  array<string,string|int>  $args
     * @param  string|null  $cwd  the project directory; must already be canonical
     */
    public function run(string $key, array $args = [], ?string $cwd = null, ?int $timeout = null): LocalCommandResult
    {
        $definition = $this->registry->get($key);
        $bound = $definition->bind($args);

        $this->gate->assertOpen($key);

        $timeout ??= (int) config('jetgrid.local.probe.command_timeout', 5);

        if ($definition->isWrite && config('jetgrid.force_dry_run', false)) {
            $audit = $this->openAudit($bound, dryRun: true);
            $result = ProcessResult::skipped('Dry run: JETGRID_FORCE_DRY_RUN is set, so the command was rendered and audited, not executed.');
            $audit->close($result);

            return new LocalCommandResult($bound, $result, available: true, audit: $audit);
        }

        $argv = $this->tools->resolveArgv($bound->argv);

        if ($argv === null) {
            // Not an exception: L5 requires layer 3 to fail soft when a tool is
            // missing, and L8's doctor is what tells the operator about it.
            return new LocalCommandResult(
                $bound,
                new ProcessResult(-1, '', $bound->argv[0].' is not on PATH.', 0),
                available: false,
            );
        }

        $audit = $definition->isWrite ? $this->openAudit($bound, dryRun: false) : null;

        $result = $this->execute($argv, $cwd, $timeout);

        $audit?->close($result);

        return new LocalCommandResult($bound, $result, available: true, audit: $audit);
    }

    /**
     * Start a whitelisted command detached, with its output appended to a log
     * file. The child outlives this process; that is the entire point.
     *
     * @param  array<string,string|int>  $args
     */
    public function spawn(string $key, array $args, string $cwd, string $logPath): SpawnResult
    {
        $definition = $this->registry->get($key);
        $bound = $definition->bind($args);

        $this->gate->assertOpen($key);

        if (config('jetgrid.force_dry_run', false)) {
            $audit = $this->openAudit($bound, dryRun: true);
            $audit->close(ProcessResult::skipped('Dry run: JETGRID_FORCE_DRY_RUN is set, so nothing was spawned.'));

            return new SpawnResult($bound, null, 'Dry-run mode: the command was rendered and audited, not started.', $logPath, $audit);
        }

        $argv = $this->tools->resolveArgv($bound->argv);

        if ($argv === null) {
            return new SpawnResult($bound, null, $bound->argv[0].' is not on PATH.', $logPath);
        }

        if (! is_dir($cwd)) {
            return new SpawnResult($bound, null, 'The project directory no longer exists: '.$cwd, $logPath);
        }

        $audit = $this->openAudit($bound, dryRun: false);
        $commands = $this->platform->commands();

        $started = microtime(true);

        $handle = @proc_open(
            $argv,
            [
                0 => ['file', $commands->nullDevice(), 'r'],
                // Append, not truncate: a restart should extend the project's
                // log rather than destroy the reason for the restart.
                1 => ['file', $logPath, 'a'],
                2 => ['file', $logPath, 'a'],
            ],
            $pipes,
            $cwd,
            null,
            $commands->detachOptions(),
        );

        if (! is_resource($handle)) {
            $result = new ProcessResult(-1, '', 'proc_open refused to start '.$bound->argv[0].'.', 0);
            $audit->close($result);

            return new SpawnResult($bound, null, $result->stderr, $logPath, $audit);
        }

        $status = proc_get_status($handle);

        // proc_close() is never called, and that is load-bearing. PHP only waits
        // for a child when proc_close asks it to; letting the resource fall out
        // of scope reaps it with WNOHANG and leaves the dev server running.
        $pid = $status['pid'] ?? null;

        $audit->close(new ProcessResult(
            exitCode: 0,
            stdout: 'Spawned detached, pid '.$pid.', logging to '.$logPath,
            stderr: '',
            durationMs: (int) round((microtime(true) - $started) * 1000),
        ));

        return new SpawnResult($bound, is_int($pid) ? $pid : null, null, $logPath, $audit);
    }

    /** Render a command without running it. This is what a confirmation shows. */
    public function preview(string $key, array $args = []): string
    {
        return $this->registry->get($key)->bind($args)->display();
    }

    /** Whether the binary a command needs is installed. */
    public function available(string $key): bool
    {
        $binary = $this->registry->get($key)->argv[0];

        return str_contains($binary, '/') || str_contains($binary, '\\')
            ? true
            : $this->tools->has($binary);
    }

    /** @param list<string> $argv */
    private function execute(array $argv, ?string $cwd, int $timeout): ProcessResult
    {
        $started = microtime(true);

        try {
            $process = new Process($argv, $cwd, timeout: $timeout);
            $process->run();

            return new ProcessResult(
                exitCode: $process->getExitCode() ?? -1,
                stdout: $process->getOutput(),
                stderr: $process->getErrorOutput(),
                durationMs: (int) round((microtime(true) - $started) * 1000),
            );
        } catch (ExceptionInterface $e) {
            // A timeout or a working directory that vanished. Both are states
            // the probe has to survive, so they become a failed result rather
            // than an exception that would abort a whole scan.
            return new ProcessResult(
                exitCode: -1,
                stdout: '',
                stderr: $e->getMessage(),
                durationMs: (int) round((microtime(true) - $started) * 1000),
            );
        }
    }

    private function openAudit(BoundCommand $bound, bool $dryRun): AuditLog
    {
        return AuditLog::open(
            user: Auth::user(),
            command: $bound,
            target: null,
            dryRun: $dryRun,
            driver: 'local:'.$this->platform->family()->value,
        );
    }
}
