<?php

namespace App\Services\Local\Platform;

use App\Enums\PlatformFamily;

/**
 * The implementation for an OS JetGrid does not recognise.
 *
 * Every capability answers "cannot" rather than guessing at a syntax that
 * happens to look Unix-ish. The effect is that L5 degrades to its first two
 * layers — a TCP and an HTTP probe, both pure PHP — and start/stop are refused
 * with a reason instead of failing at the process layer. Adding a fourth
 * platform means writing one class next to this one and nothing else.
 */
class UnsupportedPlatformCommands implements PlatformCommands
{
    public function family(): PlatformFamily
    {
        return PlatformFamily::Unknown;
    }

    public function toolMatrix(): array
    {
        return [];
    }

    public function portOwnerCommand(int $port): ?array
    {
        return null;
    }

    public function parsePortOwner(string $stdout, int $port): ?int
    {
        return null;
    }

    public function parsePortOwners(string $stdout, int $port): array
    {
        return [];
    }

    public function processNameCommand(int $pid): ?array
    {
        return null;
    }

    public function parseProcessName(string $stdout): ?string
    {
        return null;
    }

    public function processCommandLineCommand(int $pid): ?array
    {
        return null;
    }

    public function parseProcessCommandLine(string $stdout): ?string
    {
        return null;
    }

    public function processCwdCommand(int $pid): ?array
    {
        return null;
    }

    public function parseProcessCwd(string $stdout): ?string
    {
        return null;
    }

    public function terminationCommands(int $pid): array
    {
        return [];
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
