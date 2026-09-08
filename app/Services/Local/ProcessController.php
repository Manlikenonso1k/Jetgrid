<?php

namespace App\Services\Local;

use App\Enums\InstallState;
use App\Enums\LocalProjectType;
use App\Enums\RunState;
use App\Models\LocalProject;
use App\Services\Local\Platform\PlatformDetector;
use App\Support\CanonicalPath;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * L6. Starts, stops and restarts a discovered project.
 *
 * Every path in here goes through LocalCommandRunner, so every command is
 * whitelisted, pattern-validated, gated by L0 and audited before it exists as a
 * process.
 *
 * Three rules are enforced structurally rather than by convention:
 *
 *   - NOTHING IS WRITTEN INSIDE A DISCOVERED PROJECT. The only files this class
 *     creates are the PID file and the log file, and assertOwnStorage() refuses
 *     any path that is not underneath JetGrid's own storage directory. A project
 *     directory is used as a working directory and read from; never written to.
 *   - NOTHING IS INSTALLED AUTOMATICALLY. composer install, npm install and
 *     migrate exist as whitelisted commands, and maintenance() refuses to run
 *     one unless the caller echoes back the exact rendered command line.
 *   - NOTHING UNATTRIBUTED IS KILLED. If JetGrid did not start the process and
 *     layer 3 cannot prove the process belongs to this project, stop refuses.
 *     The alternative is a tool that occasionally kills your database because it
 *     was on port 3306.
 */
class ProcessController
{
    public function __construct(
        private readonly LocalCommandRunner $runner,
        private readonly RunStateProbe $probe,
        private readonly PortResolver $ports,
        private readonly PlatformDetector $platform,
        private readonly LocalModeGate $gate,
    ) {}

    public function start(LocalProject $project): ProcessOutcome
    {
        $this->gate->assertOpen('start '.$project->name);

        if ($project->install_state !== InstallState::Ready) {
            return ProcessOutcome::refused(
                $project->name.' cannot start yet: '.implode(' ', (array) $project->blockers),
                context: ['blockers' => $project->blockers, 'install_state' => $project->install_state->value],
            );
        }

        $plan = $this->startPlan($project);

        if ($plan === null) {
            return ProcessOutcome::refused(
                'JetGrid has no start command for a '.$project->type->label().' project. Set one on the project before starting it.',
            );
        }

        [$key, $args, $port] = $plan;

        $collision = $this->checkPort($project, $port);

        if ($collision !== null) {
            return $collision;
        }

        // Auto-port may have moved the port during the collision check, and the
        // command's own argument has to move with it.
        if (array_key_exists('port', $args)) {
            $args['port'] = $port;
        }

        $logPath = $this->assertOwnStorage($project->logPath());
        File::ensureDirectoryExists(dirname($logPath));
        File::ensureDirectoryExists(dirname($this->assertOwnStorage($project->pidPath())));

        $spawn = $this->runner->spawn($key, $args, $project->path, $logPath);

        if (! $spawn->ok()) {
            $project->forceFill([
                'run_state' => RunState::Failed,
                'last_error' => $spawn->error,
            ])->save();

            return ProcessOutcome::refused(
                'Could not start '.$project->name.': '.$spawn->error,
                command: $spawn->command->display(),
            );
        }

        File::put($this->assertOwnStorage($project->pidPath()), (string) $spawn->pid);

        $project->forceFill([
            'pid' => $spawn->pid,
            'port' => $port,
            'run_state' => RunState::Starting,
            'started_at' => now(),
            'log_path' => $logPath,
            'last_error' => null,
        ])->save();

        return ProcessOutcome::success(
            $project->name.' starting on port '.$port.'.',
            command: $spawn->command->display(),
            pid: $spawn->pid,
            port: $port,
        );
    }

    public function stop(LocalProject $project): ProcessOutcome
    {
        $this->gate->assertOpen('stop '.$project->name);

        $port = $project->effectivePort();

        if ($project->has_docker && $project->run_state === RunState::Docker) {
            $result = $this->runner->run('docker.compose.down', [], $project->path, 60);

            return $result->ok()
                ? ProcessOutcome::success($project->name.' compose project stopped.', command: $result->preview())
                : ProcessOutcome::refused('docker compose down failed: '.trim($result->result->stderr), command: $result->preview());
        }

        $pid = $this->stoppablePid($project, $port);

        if ($pid === null) {
            return ProcessOutcome::refused(
                'JetGrid will not stop this one. It did not start it, and it cannot prove the process on port '
                .$port.' belongs to '.$project->name.'.',
                context: ['port' => $port],
            );
        }

        $terminated = $this->terminate($pid);

        // L6: verify the port actually closed rather than trusting the kill.
        $freed = $port === null || $this->waitForPort($port, listening: false);

        $project->forceFill([
            'pid' => null,
            'started_at' => null,
            'run_state' => $freed ? RunState::Stopped : $project->run_state,
        ])->save();

        $this->forgetPidFile($project);

        if (! $freed) {
            return ProcessOutcome::refused(
                'Signalled PID '.$pid.' but port '.$port.' is still open. Something else is holding it.',
                command: $terminated,
                context: ['pid' => $pid, 'port' => $port],
            );
        }

        return ProcessOutcome::success($project->name.' stopped.', command: $terminated, pid: $pid, port: $port);
    }

    public function restart(LocalProject $project): ProcessOutcome
    {
        $this->gate->assertOpen('restart '.$project->name);

        $stopped = $this->stop($project);

        // A project that was not running is a legitimate restart, so a refusal
        // to stop only aborts when something is actually still on the port.
        if (! $stopped->ok && $project->effectivePort() !== null && $this->probe->tcpProbe($project->effectivePort())[0]) {
            return ProcessOutcome::refused('Restart aborted: '.$stopped->message, command: $stopped->command);
        }

        return $this->start($project->refresh());
    }

    /**
     * L6 HARD RULE: composer install, npm install and migrations are never
     * automatic. The caller must echo back the literal command, which is what
     * makes a UI confirmation dialog a real confirmation rather than decoration.
     */
    public function maintenance(LocalProject $project, string $key, string $confirmation): ProcessOutcome
    {
        $this->gate->assertOpen($key.' '.$project->name);

        $allowed = ['install.composer', 'install.npm', 'install.yarn', 'install.pnpm', 'laravel.migrate'];

        if (! in_array($key, $allowed, true)) {
            return ProcessOutcome::refused('['.$key.'] is not an offered maintenance action.');
        }

        $preview = $this->runner->preview($key);

        if (trim($confirmation) !== $preview) {
            return ProcessOutcome::refused(
                'Not confirmed. To run this, send back the literal command.',
                command: $preview,
            );
        }

        $result = $this->runner->run($key, [], $project->path, 900);

        return $result->ok()
            ? ProcessOutcome::success($preview.' completed.', command: $preview)
            : ProcessOutcome::refused(
                $preview.' failed: '.trim($result->result->stderr ?: $result->stdout()),
                command: $preview,
            );
    }

    /** The literal command a start would run, for the confirmation dialog. */
    public function previewStart(LocalProject $project): ?string
    {
        $plan = $this->startPlan($project);

        return $plan === null ? null : $this->runner->preview($plan[0], $plan[1]);
    }

    /**
     * The last N lines of a project's log. Reads backwards so a log that has
     * been running for a week does not have to be loaded to show its tail.
     *
     * @return list<string>
     */
    public function tail(LocalProject $project, int $lines = 200): array
    {
        $path = $this->assertOwnStorage($project->logPath());

        if (! is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $buffer = '';
        $chunk = 8192;
        $position = filesize($path) ?: 0;

        while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
            $read = (int) min($chunk, $position);
            $position -= $read;

            fseek($handle, $position);
            $buffer = fread($handle, $read).$buffer;
        }

        fclose($handle);

        $all = preg_split('/\r?\n/', rtrim($buffer, "\r\n")) ?: [];

        return array_values(array_slice($all, -$lines));
    }

    /**
     * @return array{0:string,1:array<string,string|int>,2:int}|null
     */
    private function startPlan(LocalProject $project): ?array
    {
        $port = $project->effectivePort() ?? $project->type->defaultPort();

        if ($project->has_docker && $project->type === LocalProjectType::Php) {
            // A folder whose only signature is a compose file is run by compose.
            return ['docker.compose.up', [], $port];
        }

        $key = $project->type->startCommandKey();

        if ($key === null) {
            return null;
        }

        if ($key === 'start.node') {
            $key = match ($project->package_manager) {
                'yarn' => 'start.node.yarn',
                'pnpm' => 'start.node.pnpm',
                default => 'start.node',
            };

            return [$key, ['script' => $project->dev_script ?? 'dev'], $port];
        }

        if ($key === 'start.go') {
            return [$key, [], $port];
        }

        return [$key, ['port' => $port], $port];
    }

    /**
     * L6 port collision. A refusal has to name what is holding the port —
     * "address already in use" is the error this exists to replace.
     *
     * @param  array<string,string|int>  $args  mutated when auto-port moves the port
     */
    private function checkPort(LocalProject $project, int &$port): ?ProcessOutcome
    {
        [$open] = $this->probe->tcpProbe($port);

        if (! $open) {
            return null;
        }

        $status = $this->probe->probe($project->path, $port, $project->pid, $project->started_at?->getTimestamp());

        if ($status->attributed === true) {
            return ProcessOutcome::success(
                $project->name.' is already running on port '.$port.'.',
                pid: $status->pid,
                port: $port,
            );
        }

        if ($project->auto_port || config('jetgrid.local.auto_port', false)) {
            $free = $this->nextFreePort($port);

            if ($free !== null) {
                $port = $free;
                $project->forceFill(['port' => $free])->save();

                return null;
            }
        }

        $holder = $status->processName ?? 'an unidentified process';

        return ProcessOutcome::refused(
            'Port '.$port.' is already held by '.$holder
            .($status->pid !== null ? ' (PID '.$status->pid.')' : '')
            .'. Change the port for '.$project->name.', enable auto-port, or stop the other process.',
            context: $status->toArray(),
        );
    }

    private function nextFreePort(int $from): ?int
    {
        $range = (int) config('jetgrid.local.auto_port_range', 40);

        for ($port = $from + 1; $port <= min(65535, $from + $range); $port++) {
            if (! $this->probe->tcpProbe($port)[0]) {
                return $port;
            }
        }

        return null;
    }

    /**
     * The PID it is safe to kill: one JetGrid recorded, or one layer 3 proved
     * belongs to this project. Anything else returns null and stop refuses.
     */
    private function stoppablePid(LocalProject $project, ?int $port): ?int
    {
        if ($project->pid !== null) {
            return $project->pid;
        }

        $recorded = $this->readPidFile($project);

        if ($recorded !== null) {
            return $recorded;
        }

        if ($port === null) {
            return null;
        }

        $status = $this->probe->probe($project->path, $port);

        return $status->attributed === true ? $status->pid : null;
    }

    /** Returns the command line used, for the outcome and the audit trail. */
    private function terminate(int $pid): string
    {
        $commands = $this->platform->commands();

        if ($commands->signalDirectly($pid, force: false)) {
            $this->waitForExit($pid);

            if ($this->stillAlive($pid)) {
                $commands->signalDirectly($pid, force: true);
            }

            return 'posix_kill('.$pid.', SIGTERM)';
        }

        $used = [];

        foreach ($commands->terminationCommands($pid) as [$key, $args]) {
            $result = $this->runner->run($key, $args, null, 15);
            $used[] = $result->preview();

            if ($result->ok()) {
                break;
            }
        }

        return implode(' then ', $used) ?: 'no termination command available on this platform';
    }

    private function waitForExit(int $pid, int $seconds = 5): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (! $this->stillAlive($pid)) {
                return;
            }

            usleep(100_000);
        }
    }

    private function stillAlive(int $pid): bool
    {
        // Unknown counts as alive: escalating to SIGKILL on a process that has
        // already gone is harmless, whereas reporting a stop that did not happen
        // is not.
        return Platform\UnixSignals::alive($pid) ?? true;
    }

    private function waitForPort(int $port, bool $listening, int $seconds = 5): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if ($this->probe->tcpProbe($port)[0] === $listening) {
                return true;
            }

            usleep(150_000);
        }

        return $this->probe->tcpProbe($port)[0] === $listening;
    }

    private function readPidFile(LocalProject $project): ?int
    {
        $path = $this->assertOwnStorage($project->pidPath());

        if (! is_file($path)) {
            return null;
        }

        $contents = trim((string) @file_get_contents($path));

        return ctype_digit($contents) && (int) $contents > 0 ? (int) $contents : null;
    }

    private function forgetPidFile(LocalProject $project): void
    {
        $path = $this->assertOwnStorage($project->pidPath());

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * The structural half of "never write inside a discovered project". Any
     * path this class is about to create must be inside JetGrid's own storage;
     * if a config change ever pointed the log directory at a project folder,
     * this throws rather than writing there.
     */
    private function assertOwnStorage(string $path): string
    {
        $root = CanonicalPath::normalise(storage_path('app'.DIRECTORY_SEPARATOR.'jetgrid'));
        $normalised = CanonicalPath::normalise($path);

        if (! CanonicalPath::isWithin($normalised, $root)) {
            throw new RuntimeException(
                'Refused to write outside JetGrid storage: '.$normalised.' is not inside '.$root.'.'
            );
        }

        return $normalised;
    }
}
