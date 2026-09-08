<?php

namespace App\Services\Local\Platform;

use App\Enums\PlatformFamily;

class WindowsPlatformCommands implements PlatformCommands
{
    public function family(): PlatformFamily
    {
        return PlatformFamily::Windows;
    }

    public function toolMatrix(): array
    {
        return [
            'netstat' => 'Port-to-PID attribution (L5 layer 3). Without it, an open port is reported as running-but-unattributed.',
            'tasklist' => 'Image name for an attributed PID. Without it, the PID is shown without a process name.',
            'powershell' => 'Command line for an attributed PID, which is how ownership of a port is confirmed on Windows. Without it, attribution stops at the PID.',
            'taskkill' => 'Stopping a dev server and its children. Without it, Stop is unavailable and processes must be killed by hand.',
        ];
    }

    public function portOwnerCommand(int $port): ?array
    {
        return ['windows.port.owner', []];
    }

    public function parsePortOwner(string $stdout, int $port): ?int
    {
        return $this->parsePortOwners($stdout, $port)[0] ?? null;
    }

    public function parsePortOwners(string $stdout, int $port): array
    {
        // netstat lines look like:
        //   TCP    127.0.0.1:8000    0.0.0.0:0    LISTENING    20456
        // Matching ":{port}" against the local address only, and requiring
        // LISTENING, keeps an outbound connection to the same port number from
        // being read as a server.
        //
        // Windows readily reports several listeners on one port — an IPv4 and an
        // IPv6 binding, or a stale server that never released it — so collect
        // them all and let attribution decide.
        $pids = [];

        foreach (preg_split('/\r?\n/', $stdout) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (count($fields) < 5 || strcasecmp($fields[0], 'TCP') !== 0) {
                continue;
            }

            [, $local, , $state, $pid] = $fields;

            if (strcasecmp($state, 'LISTENING') !== 0) {
                continue;
            }

            if ($this->localPort($local) !== $port || ! ctype_digit($pid)) {
                continue;
            }

            $pids[] = (int) $pid;
        }

        return array_values(array_unique($pids));
    }

    public function processNameCommand(int $pid): ?array
    {
        return ['windows.process.name', ['pid' => $pid]];
    }

    public function parseProcessName(string $stdout): ?string
    {
        // tasklist /FO CSV /NH: "php.exe","20456","Console","1","28,904 K"
        // The escape character is explicitly disabled: tasklist quotes fields
        // but has no escape convention, and a backslash is the last character of
        // every Windows path.
        $row = str_getcsv(trim(strtok($stdout, "\r\n") ?: ''), ',', '"', '');

        $name = $row[0] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function processCommandLineCommand(int $pid): ?array
    {
        return ['windows.process.commandline', ['pid' => $pid]];
    }

    public function parseProcessCommandLine(string $stdout): ?string
    {
        $line = trim($stdout);

        return $line === '' ? null : $line;
    }

    public function processCwdCommand(int $pid): ?array
    {
        // Win32_Process exposes no working directory. Attribution on Windows is
        // done by matching the project path inside the command line instead,
        // which is why L3 describes it that way rather than as a cwd check.
        return null;
    }

    public function parseProcessCwd(string $stdout): ?string
    {
        return null;
    }

    public function terminationCommands(int $pid): array
    {
        return [['windows.process.kill', ['pid' => $pid]]];
    }

    public function signalDirectly(int $pid, bool $force): bool
    {
        return false;
    }

    public function nullDevice(): string
    {
        return 'NUL';
    }

    public function detachOptions(): array
    {
        // bypass_shell keeps cmd.exe out of the picture entirely: the argv array
        // becomes the process's own command line with no interpreter in between.
        // create_process_group is what stops a Ctrl-C in the console that
        // launched JetGrid from also killing every dev server it started.
        return ['bypass_shell' => true, 'create_process_group' => true];
    }

    private function localPort(string $address): ?int
    {
        // IPv6 local addresses are bracketed: [::1]:8000
        $colon = strrpos($address, ':');

        if ($colon === false) {
            return null;
        }

        $port = substr($address, $colon + 1);

        return ctype_digit($port) ? (int) $port : null;
    }
}
