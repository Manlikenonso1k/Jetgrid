<?php

namespace App\Services\Local\Platform;

use App\Enums\PlatformFamily;

/**
 * The seam required by L9: every OS-specific shell command and every OS-specific
 * output format lives behind this interface, one implementation per platform.
 *
 * Nothing outside this package may ask which OS it is on. Callers ask for a
 * capability and get back either a whitelist key with its arguments, or null
 * meaning "this platform cannot answer that" — which is what makes the graceful
 * degradation in L5 fall out of the design rather than out of a chain of
 * conditionals.
 *
 * The command accessors return a [key, args] pair, never a command line. The key
 * is looked up in LocalCommandRegistry and the args are pattern-validated there,
 * so a platform implementation cannot introduce a command that is not on the
 * whitelist even by accident.
 */
interface PlatformCommands
{
    public function family(): PlatformFamily;

    /**
     * Binaries this platform uses, and what stops working without each one.
     * Consumed by `jetgrid:doctor`.
     *
     * @return array<string,string>
     */
    public function toolMatrix(): array;

    /**
     * L5 layer 3, step 1: which PID is listening on this port.
     *
     * @return array{0:string,1:array<string,int|string>}|null
     */
    public function portOwnerCommand(int $port): ?array;

    public function parsePortOwner(string $stdout, int $port): ?int;

    /**
     * EVERY pid listening on this port, not just the first.
     *
     * More than one process can hold the same port — a stale dev server left
     * behind by a crashed terminal, or two projects that both default to 8000.
     * Attribution has to test each candidate against the project, because
     * picking the first one netstat happens to print makes the answer depend on
     * output ordering, which is not stable.
     *
     * @return list<int>
     */
    public function parsePortOwners(string $stdout, int $port): array;

    /** @return array{0:string,1:array<string,int|string>}|null */
    public function processNameCommand(int $pid): ?array;

    public function parseProcessName(string $stdout): ?string;

    /** @return array{0:string,1:array<string,int|string>}|null */
    public function processCommandLineCommand(int $pid): ?array;

    public function parseProcessCommandLine(string $stdout): ?string;

    /**
     * L5 layer 3, step 2: the process's working directory. Null when the
     * platform has no way to read it — Windows, where the command line is
     * matched instead.
     *
     * @return array{0:string,1:array<string,int|string>}|null
     */
    public function processCwdCommand(int $pid): ?array;

    public function parseProcessCwd(string $stdout): ?string;

    /**
     * L6 stop, in escalation order: the first entry is the polite one.
     *
     * @return list<array{0:string,1:array<string,int|string>}>
     */
    public function terminationCommands(int $pid): array;

    /**
     * Signal a process without spawning anything. Returns false when the
     * platform or build has no in-process way to do it, in which case
     * terminationCommands() is used instead.
     */
    public function signalDirectly(int $pid, bool $force): bool;

    /** The platform's bit bucket, used as stdin for detached dev servers. */
    public function nullDevice(): string;

    /**
     * Extra proc_open options needed to detach a child from this process.
     *
     * @return array<string,bool>
     */
    public function detachOptions(): array;
}
