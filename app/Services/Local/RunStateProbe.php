<?php

namespace App\Services\Local;

use App\Enums\DetectionLayer;
use App\Enums\RunState;
use App\Services\Local\Platform\PlatformDetector;
use App\Support\CanonicalPath;

/**
 * L5. Is it running? Four layers, cheapest first, each one degrading into the
 * answer the layer below it already produced.
 *
 *   1. TCP    a 200ms connect to 127.0.0.1. Pure PHP, always available, and on
 *             its own enough for running / not-running.
 *   2. HTTP   a GET to the same port. Separates "listening" from "listening and
 *             answering", and 500 from 200.
 *   3. PROCESS which PID owns the port, and whether its working directory or
 *             command line is this project. Platform-specific, fails soft.
 *   4. DOCKER compose projects matched back to directories.
 *
 * The layer that produced the answer travels with it. A caller that is told
 * "running" without being told how much of that is a guess will eventually act
 * on the guess.
 *
 * Nothing here writes. The whole probe is safe to run against a directory
 * JetGrid has no business touching.
 */
class RunStateProbe
{
    /** @var array<string,array{project:string,container:string}>|null */
    private ?array $dockerSnapshot = null;

    public function __construct(
        private readonly PlatformDetector $platform,
        private readonly LocalCommandRunner $runner,
    ) {}

    /**
     * @param  int|null  $managedPid  the PID JetGrid recorded when it started this
     *                                project, if it started it
     * @param  int|null  $startedAt  unix time of that start, for the boot grace period
     */
    public function probe(string $path, int $port, ?int $managedPid = null, ?int $startedAt = null): RunStatus
    {
        $docker = $this->dockerStatus($path, $port);

        if ($docker !== null) {
            return $docker;
        }

        [$open, $connectMs] = $this->tcpProbe($port);

        if (! $open) {
            return $this->notListening($port, $managedPid, $startedAt);
        }

        [$httpStatus, $responseMs] = $this->httpProbe($port);

        $attribution = $this->attribute($path, $port, $managedPid);

        $state = match (true) {
            $attribution['attributed'] === false => RunState::Conflict,
            $httpStatus === null => RunState::Running,
            $httpStatus >= 500 => RunState::Erroring,
            default => RunState::Running,
        };

        $layer = match (true) {
            $attribution['layer'] === DetectionLayer::Process => DetectionLayer::Process,
            $httpStatus !== null => DetectionLayer::Http,
            default => DetectionLayer::Tcp,
        };

        return new RunStatus(
            state: $state,
            layer: $layer,
            port: $port,
            attributed: $attribution['attributed'],
            pid: $attribution['pid'],
            processName: $attribution['name'],
            commandLine: $attribution['commandLine'],
            workingDirectory: $attribution['cwd'],
            httpStatus: $httpStatus,
            responseMs: $responseMs ?? $connectMs,
            note: $attribution['note'],
        );
    }

    /**
     * L5 layer 1. stream_socket_client rather than fsockopen: it is the one that
     * takes a float timeout, and 200ms is the budget for the whole thing.
     *
     * @return array{0:bool,1:int}
     */
    public function tcpProbe(int $port, string $host = '127.0.0.1'): array
    {
        $timeout = ((int) config('jetgrid.local.probe.tcp_timeout_ms', 200)) / 1000;
        $started = microtime(true);

        $socket = @stream_socket_client(
            'tcp://'.$host.':'.$port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
        );

        $elapsed = (int) round((microtime(true) - $started) * 1000);

        if ($socket === false) {
            return [false, $elapsed];
        }

        fclose($socket);

        return [true, $elapsed];
    }

    /**
     * L5 layer 2. A hand-written HTTP/1.0 request rather than the HTTP client:
     * a dev server that is mid-boot answers with a reset or with garbage, and
     * this needs to record that as a state rather than raise it as an exception.
     *
     * @return array{0:int|null,1:int|null}
     */
    public function httpProbe(int $port, string $host = '127.0.0.1'): array
    {
        $timeout = ((int) config('jetgrid.local.probe.http_timeout_ms', 1500)) / 1000;
        $started = microtime(true);

        $socket = @stream_socket_client('tcp://'.$host.':'.$port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);

        if ($socket === false) {
            return [null, null];
        }

        stream_set_timeout($socket, (int) $timeout, (int) (fmod($timeout, 1) * 1_000_000));

        // Connection: close so the server does not hold the socket open waiting
        // for a second request that will never come.
        $request = "GET / HTTP/1.0\r\nHost: {$host}:{$port}\r\nUser-Agent: JetGrid-LocalProbe\r\nConnection: close\r\n\r\n";

        @fwrite($socket, $request);

        $line = @fgets($socket, 256);

        fclose($socket);

        $elapsed = (int) round((microtime(true) - $started) * 1000);

        if (! is_string($line) || preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $line, $m) !== 1) {
            // Something is listening but it does not speak HTTP — a database, a
            // websocket server, or an app that has not finished booting.
            return [null, $elapsed];
        }

        return [(int) $m[1], $elapsed];
    }

    /**
     * L5 layer 3. Never throws, and never guesses: an unavailable tool produces
     * attributed=null, which the caller renders as "unattributed".
     *
     * @return array{attributed:bool|null,layer:DetectionLayer,pid:int|null,name:string|null,commandLine:string|null,cwd:string|null,note:string|null}
     */
    private function attribute(string $path, int $port, ?int $managedPid): array
    {
        $none = [
            'attributed' => null,
            'layer' => DetectionLayer::Http,
            'pid' => null,
            'name' => null,
            'commandLine' => null,
            'cwd' => null,
            'note' => null,
        ];

        $commands = $this->platform->commands();
        $lookup = $commands->portOwnerCommand($port);

        if ($lookup === null) {
            return array_merge($none, ['note' => 'No port-attribution tool on this platform.']);
        }

        $result = $this->runner->run($lookup[0], $lookup[1]);

        if (! $result->ok()) {
            return array_merge($none, [
                'note' => 'Port attribution unavailable: '.trim($result->result->stderr ?: 'command failed').'.',
            ]);
        }

        $pids = $commands->parsePortOwners($result->stdout(), $port);

        if ($pids === []) {
            return array_merge($none, ['note' => 'Port is open but no owning process could be read.']);
        }

        /*
         * Test EVERY listener, not just the first.
         *
         * Two processes on one port is ordinary on a developer machine — a stale
         * dev server, or two projects that both default to 8000. Reading only the
         * first pid the tool printed made this answer depend on output ordering,
         * which is not stable: the same project would report "running" and "held
         * by something else" on consecutive runs.
         *
         * A candidate that positively matches wins immediately. Otherwise the
         * first readable candidate is reported, so the UI can still say what is
         * holding the port.
         */
        $fallback = null;

        foreach ($pids as $pid) {
            $candidate = [
                'attributed' => null,
                'layer' => DetectionLayer::Process,
                'pid' => $pid,
                'name' => $this->readProcess($commands->processNameCommand($pid), fn (string $out): ?string => $commands->parseProcessName($out)),
                'cwd' => $this->readProcess($commands->processCwdCommand($pid), fn (string $out): ?string => $commands->parseProcessCwd($out)),
                'commandLine' => $this->readProcess($commands->processCommandLineCommand($pid), fn (string $out): ?string => $commands->parseProcessCommandLine($out)),
                'note' => null,
            ];

            $candidate['attributed'] = $this->ownedByProject(
                $path, $candidate['cwd'], $candidate['commandLine'], $pid, $managedPid,
            );

            if ($candidate['attributed'] === true) {
                return $candidate;
            }

            $fallback ??= $candidate;
        }

        $fallback['note'] = $fallback['attributed'] === null
            ? 'Owning PID '.$fallback['pid'].' found, but neither its working directory nor its command line could be read.'
            : (count($pids) > 1
                ? 'Port held by '.count($pids).' processes, none of which belongs to this project.'
                : null);

        return $fallback;
    }

    /**
     * Three ways to be sure, in descending order of certainty: JetGrid started
     * it and remembers the PID; the process's working directory is the project;
     * the project path appears in its command line — which is the only one
     * Windows offers, because Win32_Process has no cwd.
     */
    private function ownedByProject(string $path, ?string $cwd, ?string $commandLine, int $pid, ?int $managedPid): ?bool
    {
        if ($managedPid !== null && $managedPid === $pid) {
            return true;
        }

        if ($cwd !== null) {
            return CanonicalPath::isWithin($cwd, $path);
        }

        if ($commandLine !== null) {
            return str_contains(
                CanonicalPath::comparable($commandLine),
                CanonicalPath::comparable($path),
            );
        }

        return null;
    }

    /** @param array{0:string,1:array<string,int|string>}|null $command */
    private function readProcess(?array $command, callable $parse): ?string
    {
        if ($command === null) {
            return null;
        }

        $result = $this->runner->run($command[0], $command[1]);

        return $result->ok() ? $parse($result->stdout()) : null;
    }

    /**
     * L5 layer 4. Absent Docker is the normal case on a workstation, so the
     * whole layer is skipped without comment when the binary is not there.
     */
    private function dockerStatus(string $path, int $port): ?RunStatus
    {
        $snapshot = $this->dockerSnapshot();

        foreach ($snapshot as $directory => $entry) {
            if (! CanonicalPath::same($directory, $path)) {
                continue;
            }

            return new RunStatus(
                state: RunState::Docker,
                layer: DetectionLayer::Docker,
                port: $port,
                attributed: true,
                container: $entry['project'],
                note: 'Matched compose project "'.$entry['project'].'".',
            );
        }

        return null;
    }

    /**
     * Compose projects keyed by the directory of their config file. Read once
     * per instance: one docker call for a whole dashboard, not one per project.
     *
     * @return array<string,array{project:string,container:string}>
     */
    private function dockerSnapshot(): array
    {
        if ($this->dockerSnapshot !== null) {
            return $this->dockerSnapshot;
        }

        $this->dockerSnapshot = [];

        if (! $this->runner->available('docker.compose.ls')) {
            return $this->dockerSnapshot;
        }

        $result = $this->runner->run('docker.compose.ls');

        if (! $result->ok()) {
            return $this->dockerSnapshot;
        }

        foreach ($this->decodeDockerJson($result->stdout()) as $row) {
            $name = $row['Name'] ?? null;
            $configFiles = $row['ConfigFiles'] ?? '';

            if (! is_string($name) || ! is_string($configFiles) || $configFiles === '') {
                continue;
            }

            // ConfigFiles is a comma-separated list of absolute compose file
            // paths; the project directory is the one they live in.
            foreach (explode(',', $configFiles) as $file) {
                $directory = CanonicalPath::of(dirname(trim($file)));

                if ($directory !== null) {
                    $this->dockerSnapshot[$directory] = ['project' => $name, 'container' => $name];
                }
            }
        }

        return $this->dockerSnapshot;
    }

    /**
     * Docker's --format json is a JSON array on some versions and one object
     * per line on others. Both are accepted rather than pinning a version.
     *
     * @return list<array<string,mixed>>
     */
    private function decodeDockerJson(string $stdout): array
    {
        $trimmed = trim($stdout);

        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);

        if (is_array($decoded) && array_is_list($decoded)) {
            return array_values(array_filter($decoded, 'is_array'));
        }

        $rows = [];

        foreach (preg_split('/\r?\n/', $trimmed) ?: [] as $line) {
            $row = json_decode(trim($line), true);

            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function notListening(int $port, ?int $managedPid, ?int $startedAt): RunStatus
    {
        // A process JetGrid spawned that has not opened its port yet is
        // starting, not stopped — but only for as long as booting plausibly
        // takes. After that it has failed, and saying so is the useful answer.
        $grace = (int) config('jetgrid.local.start_grace_seconds', 30);

        if ($managedPid !== null && $startedAt !== null && (time() - $startedAt) <= $grace) {
            return new RunStatus(
                state: RunState::Starting,
                layer: DetectionLayer::Tcp,
                port: $port,
                pid: $managedPid,
                note: 'Spawned '.(time() - $startedAt).'s ago; port not listening yet.',
            );
        }

        if ($managedPid !== null && $startedAt !== null) {
            return new RunStatus(
                state: RunState::Failed,
                layer: DetectionLayer::Tcp,
                port: $port,
                pid: $managedPid,
                note: 'Started '.(time() - $startedAt).'s ago but never opened port '.$port.'. Check the project log.',
            );
        }

        return new RunStatus(RunState::Stopped, DetectionLayer::None, $port);
    }
}
