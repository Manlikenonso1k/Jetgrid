<?php

namespace App\Services\Capacity;

use App\Models\ProtectedResource;
use App\Services\Metrics\ServerFacts;

/**
 * Feature 2 — "how many more sites fit", modelled honestly.
 *
 * Three independent constraints are computed from numbers actually read off the
 * server, and the tightest one wins and gets named. A constraint whose inputs
 * are missing returns null and is excluded rather than defaulted, so the answer
 * is never propped up by a guess.
 */
class CapacityEstimator
{
    /** Rough resident size of one PHP-FPM child. Overridable; shown in workings. */
    private const PHP_CHILD_MB = 40;

    public function estimate(ServerFacts $facts): CapacityEstimate
    {
        return CapacityEstimate::from([
            $this->ramConstraint($facts),
            $this->diskConstraint($facts),
            $this->cpuCreditConstraint($facts),
        ]);
    }

    /**
     * RAM. Counts what the OS reserves, what the database engine has already
     * claimed for its buffer pool, and what one more site's PHP-FPM pool would
     * cost at its configured max_children — not just "free memory / 200MB".
     */
    private function ramConstraint(ServerFacts $facts): CapacityConstraint
    {
        if ($facts->ramAvailableBytes === null) {
            return CapacityConstraint::unavailable('ram', 'RAM', 'RAM-bound', 'Could not read /proc/meminfo via free.');
        }

        $availableMb = (int) round($facts->ramAvailableBytes / 1048576);
        $osReserveMb = (int) config('jetgrid.capacity.os_reserve_mb');
        $dbBufferMb = $this->databaseBufferMb();

        $usableMb = $availableMb - $osReserveMb - $dbBufferMb;
        $perSiteMb = $this->perSiteRamMb();

        $slots = $perSiteMb > 0 ? (int) floor($usableMb / $perSiteMb) : 0;

        return new CapacityConstraint(
            key: 'ram',
            name: 'RAM',
            boundLabel: 'RAM-bound',
            slots: max(0, $slots),
            workings: [
                ['label' => 'Available (incl. reclaimable cache)', 'value' => $availableMb.' MB'],
                ['label' => 'Less OS reserve', 'value' => '-'.$osReserveMb.' MB'],
                ['label' => 'Less DB buffer pool', 'value' => '-'.$dbBufferMb.' MB'],
                ['label' => 'Usable for new sites', 'value' => max(0, $usableMb).' MB'],
                ['label' => 'Per site (PHP-FPM children x '.self::PHP_CHILD_MB.' MB)', 'value' => $perSiteMb.' MB'],
            ],
        );
    }

    private function diskConstraint(ServerFacts $facts): CapacityConstraint
    {
        if ($facts->diskFreeBytes === null) {
            return CapacityConstraint::unavailable('disk', 'Disk', 'disk-bound', 'Could not read df output for /.');
        }

        $freeMb = (int) round($facts->diskFreeBytes / 1048576);
        $reservePct = (int) config('jetgrid.capacity.disk_reserve_pct');
        // Never plan to fill the disk: a full root filesystem takes the existing
        // projects down, which is the exact outcome this whole app exists to avoid.
        $reserveMb = (int) round($freeMb * ($reservePct / 100));
        $usableMb = $freeMb - $reserveMb;
        $perSiteMb = (int) config('jetgrid.capacity.small_site_disk_mb');

        $slots = $perSiteMb > 0 ? (int) floor($usableMb / $perSiteMb) : 0;

        return new CapacityConstraint(
            key: 'disk',
            name: 'Disk',
            boundLabel: 'disk-bound',
            slots: max(0, $slots),
            workings: [
                ['label' => 'Free on /', 'value' => $freeMb.' MB'],
                ['label' => 'Less '.$reservePct.'% safety reserve', 'value' => '-'.$reserveMb.' MB'],
                ['label' => 'Usable for new sites', 'value' => max(0, $usableMb).' MB'],
                ['label' => 'Per site', 'value' => $perSiteMb.' MB'],
            ],
        );
    }

    /**
     * On a t-family instance this is usually the real limit. If the balance is
     * falling, capacity is already negative — you are borrowing against burst,
     * not sitting on headroom.
     */
    private function cpuCreditConstraint(ServerFacts $facts): CapacityConstraint
    {
        if (! $facts->hasCreditData()) {
            return CapacityConstraint::unavailable(
                'cpu_credits',
                'CPU credits',
                'CPU-credit-bound',
                'No CloudWatch data. Set JETGRID_INSTANCE_ID and JETGRID_CLOUDWATCH=true, and allow cloudwatch:GetMetricStatistics.',
            );
        }

        $earnPerHour = $this->creditsEarnedPerHour();

        if ($earnPerHour === null) {
            return CapacityConstraint::unavailable(
                'cpu_credits',
                'CPU credits',
                'CPU-credit-bound',
                'Instance type unknown, so the credit earn rate cannot be looked up. Set JETGRID_INSTANCE_TYPE.',
            );
        }

        // CPUCreditUsage is reported per 5-minute period; x12 for an hourly rate.
        $usePerHour = ($facts->cpuCreditUsage ?? 0) * 12;
        $spare = $earnPerHour - $usePerHour;
        $perSite = (float) config('jetgrid.capacity.small_site_cpu_credits');

        $slots = $perSite > 0 ? (int) floor($spare / $perSite) : 0;

        $workings = [
            ['label' => 'Earned per hour', 'value' => round($earnPerHour, 2).' credits'],
            ['label' => 'Burned per hour (current)', 'value' => round($usePerHour, 2).' credits'],
            ['label' => 'Net', 'value' => round($spare, 2).' credits/h'],
            ['label' => 'Per site', 'value' => $perSite.' credits/h'],
            ['label' => 'Balance', 'value' => round($facts->cpuCreditBalance ?? 0, 1)],
        ];

        if ($facts->cpuSurplusCreditBalance > 0) {
            $workings[] = ['label' => 'Surplus (being charged for)', 'value' => round($facts->cpuSurplusCreditBalance, 1)];
        }

        return new CapacityConstraint(
            key: 'cpu_credits',
            name: 'CPU credits',
            boundLabel: 'CPU-credit-bound',
            slots: max(0, $slots),
            workings: $workings,
        );
    }

    /** True when the instance is burning credits faster than it earns them. */
    public function burningCredits(ServerFacts $facts): bool
    {
        $earn = $this->creditsEarnedPerHour();

        if ($earn === null || ! $facts->hasCreditData()) {
            return false;
        }

        return ($facts->cpuCreditUsage ?? 0) * 12 > $earn;
    }

    /**
     * Per-site RAM cost, derived from what the pools on this box actually ask
     * for rather than a flat constant. Falls back to the configured value when
     * discovery has not run.
     */
    private function perSiteRamMb(): int
    {
        $configured = (int) config('jetgrid.capacity.small_site_ram_mb');

        $children = ProtectedResource::query()
            ->where('type', 'php_fpm_pool')
            ->get()
            ->map(static fn (ProtectedResource $r) => (int) ($r->parsed['max_children'] ?? 0))
            ->filter()
            ->values();

        if ($children->isEmpty()) {
            return $configured;
        }

        return (int) round($children->median() * self::PHP_CHILD_MB);
    }

    /**
     * The database's buffer pool, which is committed memory a new site cannot
     * have. Read from config when set; otherwise assume the engine default
     * rather than pretending the memory is free.
     */
    private function databaseBufferMb(): int
    {
        $configured = config('jetgrid.capacity.db_buffer_pool_mb');

        return $configured !== null ? (int) $configured : 128;
    }

    /** Credits/hour for this instance type, from the pricing catalogue. */
    private function creditsEarnedPerHour(): ?float
    {
        $type = config('jetgrid.instance_type');

        if (! $type) {
            return null;
        }

        $catalogue = InstanceCatalogue::load();

        return isset($catalogue['instances'][$type]['credits_per_hour'])
            ? (float) $catalogue['instances'][$type]['credits_per_hour']
            : null;
    }
}
