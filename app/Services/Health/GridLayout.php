<?php

namespace App\Services\Health;

use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Places houses on the grid.
 *
 * Coordinates are assigned once and stored, because a house that moves between
 * polls is unreadable — you lose the ability to learn where your own projects
 * live on the map. New sites take the next free plot in a spiral from the
 * origin, which keeps the settlement compact as it grows.
 */
class GridLayout
{
    /** @param Collection<int,Site> $sites */
    public function assign(Collection $sites): void
    {
        $taken = $sites
            ->filter(static fn (Site $s) => $s->grid_x !== null && $s->grid_z !== null)
            ->map(static fn (Site $s) => $s->grid_x.':'.$s->grid_z)
            ->flip();

        $cursor = 0;

        foreach ($sites as $site) {
            if ($site->grid_x !== null && $site->grid_z !== null) {
                continue;
            }

            do {
                [$x, $z] = $this->spiral($cursor++);
            } while ($taken->has($x.':'.$z));

            $taken[$x.':'.$z] = true;

            // grid_x/grid_z are monitoring fields, so this is allowed even for
            // protected sites — it changes where a house is drawn, nothing else.
            $site->update(['grid_x' => $x, 'grid_z' => $z]);
        }
    }

    /**
     * Empty plots representing remaining capacity (Feature 2), placed in the
     * same spiral so they read as the next places a house could go.
     *
     * @param  Collection<int,Site>  $sites
     * @return list<array{x:int,z:int}>
     */
    public function emptyPlots(Collection $sites, int $count): array
    {
        $taken = $sites
            ->map(static fn (Site $s) => $s->grid_x.':'.$s->grid_z)
            ->flip();

        $plots = [];
        $cursor = 0;

        while (count($plots) < $count && $cursor < 4096) {
            [$x, $z] = $this->spiral($cursor++);

            if (! $taken->has($x.':'.$z)) {
                $plots[] = ['x' => $x, 'z' => $z];
            }
        }

        return $plots;
    }

    /** Square spiral: 0 → (0,0), then outward ring by ring. */
    private function spiral(int $n): array
    {
        if ($n === 0) {
            return [0, 0];
        }

        $ring = (int) ceil((sqrt($n + 1) - 1) / 2);
        $sideLength = 2 * $ring;
        $ringStart = (2 * $ring - 1) ** 2;
        $offset = $n - $ringStart;
        $side = intdiv($offset, $sideLength);
        $pos = $offset % $sideLength;

        return match ($side) {
            0 => [$ring, -$ring + $pos + 1],
            1 => [$ring - $pos - 1, $ring],
            2 => [-$ring, $ring - $pos - 1],
            default => [-$ring + $pos + 1, -$ring],
        };
    }
}
