<?php

namespace App\Services\Local\Platform;

use App\Enums\PlatformFamily;

class MacPlatformCommands implements PlatformCommands
{
    public function family(): PlatformFamily
    {
        return PlatformFamily::MacOS;
    }

    public function toolMatrix(): array
    {
        return [
            'lsof' => 'Port-to-PID attribution and the working directory of the owning process (L5 layer 3). Without it, an open port is reported as running-but-unattributed.',
            'ps' => 'Process name and command line for an attributed PID. Part of the base system; its absence means something is very wrong.',
            'kill' => 'Fallback stop path when ext-posix is unavailable.',
        ];
    }

    public function portOwnerCommand(int $port): ?array
    {
        return ['macos.port.owner', ['port' => $port]];
    }

    public function parsePortOwner(string $stdout, int $port): ?int
    {
        return LsofOutput::firstListenerPid($stdout);
    }

    public function parsePortOwners(string $stdout, int $port): array
    {
        return LsofOutput::listenerPids($stdout);
    }

    public function processNameCommand(int $pid): ?array
    {
        return ['macos.process.name', ['pid' => $pid]];
    }

    public function parseProcessName(string $stdout): ?string
    {
        $line = trim(strtok($stdout, "\r\n") ?: '');

        return $line === '' ? null : basename($line);
    }

    public function processCommandLineCommand(int $pid): ?array
    {
        return ['macos.process.commandline', ['pid' => $pid]];
    }

    public function parseProcessCommandLine(string $stdout): ?string
    {
        $line = trim($stdout);

        return $line === '' ? null : $line;
    }

    public function processCwdCommand(int $pid): ?array
    {
        return ['macos.process.cwd', ['pid' => $pid]];
    }

    public function parseProcessCwd(string $stdout): ?string
    {
        // -Fn emits one field per line, each prefixed by its field character.
        // The n record is the name — for the cwd descriptor, the directory.
        foreach (preg_split('/\r?\n/', $stdout) ?: [] as $line) {
            if (str_starts_with($line, 'n')) {
                $path = substr($line, 1);

                return $path === '' ? null : $path;
            }
        }

        return null;
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
}
