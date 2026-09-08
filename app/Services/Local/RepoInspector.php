<?php

namespace App\Services\Local;

/**
 * L3's "repo info if .git exists": current branch, and whether the tree is
 * dirty.
 *
 * Two processes per project, so this runs during a scan and never during the
 * dashboard's poll loop. A project with no .git, or a git that is not installed,
 * simply has no repo information — it is not an error and it is not a reason to
 * skip the project.
 */
class RepoInspector
{
    public function __construct(private readonly LocalCommandRunner $runner) {}

    /** @return array{branch:string|null,dirty:bool|null} */
    public function inspect(string $path): array
    {
        $none = ['branch' => null, 'dirty' => null];

        if (! is_dir($path.DIRECTORY_SEPARATOR.'.git') && ! is_file($path.DIRECTORY_SEPARATOR.'.git')) {
            // A file rather than a directory means a worktree or submodule; both
            // are real repositories and git handles them from here.
            return $none;
        }

        if (! $this->runner->available('git.branch')) {
            return $none;
        }

        $branch = $this->runner->run('git.branch', [], $path);

        if (! $branch->ok()) {
            return $none;
        }

        $status = $this->runner->run('git.dirty', [], $path);

        return [
            'branch' => trim($branch->stdout()) ?: null,
            'dirty' => $status->ok() ? trim($status->stdout()) !== '' : null,
        ];
    }
}
