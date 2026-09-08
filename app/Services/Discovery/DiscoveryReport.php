<?php

namespace App\Services\Discovery;

/**
 * What a discovery run saw. Built so the operator can diff it against their own
 * knowledge of the box before trusting anything — the prompt requires exactly
 * that verification step before JetGrid is allowed to do anything else.
 */
class DiscoveryReport
{
    /** @var list<array{type:string,name:string,path:?string,detail:string}> */
    public array $found = [];

    /** @var list<string> */
    public array $sitesCreated = [];

    /** @var list<string> */
    public array $sitesSeenAgain = [];

    /** @var list<array{name:string,path:?string}> */
    public array $drifted = [];

    /** @var list<string> */
    public array $unreadable = [];

    public int $writes = 0; // Must stay 0. Asserted by the test suite.

    public function record(string $type, string $name, ?string $path, string $detail): void
    {
        $this->found[] = compact('type', 'name', 'path', 'detail');
    }

    public function countByType(): array
    {
        $counts = [];

        foreach ($this->found as $item) {
            $counts[$item['type']] = ($counts[$item['type']] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    public function total(): int
    {
        return count($this->found);
    }
}
