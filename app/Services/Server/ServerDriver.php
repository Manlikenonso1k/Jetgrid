<?php

namespace App\Services\Server;

use App\Services\Privilege\BoundCommand;

/**
 * The only surface through which JetGrid touches a host.
 *
 * Two implementations ship:
 *   LinuxServerDriver — a real host, via Symfony Process (argv array, no shell).
 *   FakeServerDriver  — fixtures on disk. Executes nothing, ever. This is what
 *                       makes the UI buildable on a laptop with no nginx, and
 *                       what the test suite runs against.
 */
interface ServerDriver
{
    public function name(): string;

    /** True when this driver cannot affect a real machine. */
    public function isFake(): bool;

    public function run(BoundCommand $command, int $timeoutSeconds = 60): ProcessResult;

    /** Read-only file access used by discovery. Returns null when unreadable. */
    public function readFile(string $path): ?string;

    /** @return list<string> absolute paths; empty when the directory is unreadable */
    public function listDirectory(string $path): array;

    public function fileExists(string $path): bool;

    /** Unix mtime, or null. */
    public function modifiedAt(string $path): ?int;
}
