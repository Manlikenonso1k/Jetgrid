<?php

namespace App\Services\Local\Platform;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Where the module finds out whether a binary exists, and where it turns a
 * logical name into something proc_open can execute.
 *
 * The privileged registry pins absolute paths because a managed server has a
 * known layout. A workstation does not: php may be in a Herd bundle, npm is
 * npm.cmd on Windows, and go may only exist inside a shell profile. So local
 * commands name the binary and this class resolves it — via Symfony's
 * ExecutableFinder, which is the only PATH walker in the tree that already
 * understands PATHEXT.
 *
 * Results are memoised for the life of the process. A PATH that changes while a
 * request is in flight is not a case worth paying a filesystem walk per probe
 * for, and `jetgrid:doctor` is a fresh process every time it runs.
 */
class ToolLocator
{
    /** @var array<string,string|null> */
    private array $resolved = [];

    public function __construct(private readonly ExecutableFinder $finder = new ExecutableFinder) {}

    public function path(string $binary): ?string
    {
        return $this->resolved[$binary] ??= $this->finder->find($binary);
    }

    public function has(string $binary): bool
    {
        return $this->path($binary) !== null;
    }

    /**
     * Turn a whitelisted argv into one that can actually be executed.
     *
     * Only argv[0] is touched, and only when it is a bare binary name. A
     * relative binstub such as `bin/rails` is left alone: it is resolved against
     * the working directory the process is given, which is the project itself.
     *
     * @param  list<string>  $argv
     * @return list<string>|null null when the binary is not installed
     */
    public function resolveArgv(array $argv): ?array
    {
        $binary = $argv[0] ?? null;

        if ($binary === null) {
            return null;
        }

        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return $argv;
        }

        $path = $this->path($binary);

        if ($path === null) {
            return null;
        }

        $argv[0] = $path;

        return $argv;
    }
}
