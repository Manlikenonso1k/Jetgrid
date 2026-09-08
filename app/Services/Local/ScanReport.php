<?php

namespace App\Services\Local;

/**
 * The result of one walk.
 *
 * Errors are a first-class part of it, not an exception: L1 requires a
 * permission-denied directory or a symlink loop to be collected and surfaced,
 * because a scan that aborts on the first unreadable folder finds nothing on a
 * machine that has one.
 */
final class ScanReport
{
    /**
     * @param  list<DiscoveredProject>  $projects
     * @param  list<array{path:string,reason:string}>  $errors
     * @param  list<string>  $roots
     */
    public function __construct(
        public readonly array $projects,
        public readonly array $errors,
        public readonly array $roots,
        public readonly int $directoriesVisited,
        public readonly bool $fromCache = false,
    ) {}

    public function total(): int
    {
        return count($this->projects);
    }

    /** @return array<string,int> */
    public function countByType(): array
    {
        $counts = [];

        foreach ($this->projects as $project) {
            $key = $project->signature->type->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
