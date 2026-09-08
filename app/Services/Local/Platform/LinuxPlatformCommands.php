<?php

namespace App\Services\Local\Platform;

use App\Enums\PlatformFamily;

class LinuxPlatformCommands implements PlatformCommands
{
    public function __construct(private readonly ToolLocator $tools) {}

    public function family(): PlatformFamily
    {
        return PlatformFamily::Linux;
    }

    public function toolMatrix(): array
    {
        return [
            'ss' => 'Port-to-PID attribution (L5 layer 3). lsof covers for it if it is missing; without either, an open port is reported as running-but-unattributed.',
            'lsof' => 'Fallback for port-to-PID attribution when iproute2 is not installed.',
            'readlink' => 'Working directory of an attributed PID, via /proc. Without it, ownership is inferred from the command line instead.',
            'ps' => 'Process name and command line for an attributed PID.',
            'kill' => 'Fallback stop path when ext-posix is unavailable.',
        ];
    }

    public function portOwnerCommand(int $port): ?array
    {
        // ss is preferred: one call returns the whole listening table, and it is
        // present by default on every distro that has dropped net-tools.
        if ($this->tools->has('ss')) {
            return ['linux.port.owner', []];
        }

        if ($this->tools->has('lsof')) {
            return ['linux.port.owner.lsof', ['port' => $port]];
        }

        return null;
    }

    public function parsePortOwner(string $stdout, int $port): ?int
    {
        // Which of the two commands produced this is decided by the output, not
        // by re-asking ToolLocator: ss's process column is unmistakable, and
        // sniffing here keeps the ss table from being fed to the lsof parser,
        // whose second column would otherwise read ss's Recv-Q as a PID.
        return $this->parsePortOwners($stdout, $port)[0] ?? null;
    }

    public function parsePortOwners(string $stdout, int $port): array
    {
        return $this->looksLikeSs($stdout)
            ? $this->parseSsAll($stdout, $port)
            : LsofOutput::listenerPids($stdout);
    }

    public function processNameCommand(int $pid): ?array
    {
        return ['linux.process.name', ['pid' => $pid]];
    }

    public function parseProcessName(string $stdout): ?string
    {
        $line = trim(strtok($stdout, "\r\n") ?: '');

        return $line === '' ? null : basename($line);
    }

    public function processCommandLineCommand(int $pid): ?array
    {
        return ['linux.process.commandline', ['pid' => $pid]];
    }

    public function parseProcessCommandLine(string $stdout): ?string
    {
        $line = trim($stdout);

        return $line === '' ? null : $line;
    }

    public function processCwdCommand(int $pid): ?array
    {
        return ['linux.process.cwd', ['pid' => $pid]];
    }

    public function parseProcessCwd(string $stdout): ?string
    {
        $path = trim($stdout);

        return $path === '' ? null : $path;
    }

    public function terminationCommands(int $pid): array
    {
        return [
            ['unix.process.term', ['pid' => $pid]],
            ['unix.process.kill', ['pid' => $pid]],
        ];
    }

    public function signalDirectly(int $pid, bool $force): bool
    {
        return UnixSignals::send($pid, $force);
    }

    public function nullDevice(): string
    {
        return '/dev/null';
    }

    public function detachOptions(): array
    {
        return [];
    }

    private function looksLikeSs(string $stdout): bool
    {
        return str_contains($stdout, 'users:(') || str_contains($stdout, 'Local Address:Port');
    }

    /**
     * `ss -tlnp` lines look like:
     *   LISTEN 0 511 127.0.0.1:8000 0.0.0.0:* users:(("php",pid=20456,fd=8))
     * The users: field is absent when the socket belongs to another user, which
     * is a normal, non-fatal outcome — it just means no attribution.
     */
    private function parseSs(string $stdout, int $port): ?int
    {
        return $this->parseSsAll($stdout, $port)[0] ?? null;
    }

    /** @return list<int> */
    private function parseSsAll(string $stdout, int $port): array
    {
        $pids = [];

        foreach (preg_split('/\r?\n/', $stdout) ?: [] as $line) {
            if (! str_contains($line, 'pid=')) {
                continue;
            }

            $fields = preg_split('/\s+/', trim($line)) ?: [];
            $local = $fields[3] ?? '';

            $colon = strrpos($local, ':');

            if ($colon === false || (int) substr($local, $colon + 1) !== $port) {
                continue;
            }

            // A single ss row can name several pids when a server has forked
            // workers that inherited the listening socket.
            if (preg_match_all('/pid=(\d+)/', $line, $m) > 0) {
                foreach ($m[1] as $pid) {
                    $pids[] = (int) $pid;
                }
            }
        }

        return array_values(array_unique($pids));
    }
}
