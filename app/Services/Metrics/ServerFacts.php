<?php

namespace App\Services\Metrics;

/**
 * A snapshot of the box, read from real commands.
 *
 * Every field is nullable on purpose. If JetGrid could not read something it
 * says so rather than substituting a plausible number — the capacity estimator
 * downstream names its limiting factor, and it cannot do that honestly on top
 * of invented inputs.
 */
final class ServerFacts
{
    public function __construct(
        public readonly ?int $ramTotalBytes = null,
        public readonly ?int $ramUsedBytes = null,
        public readonly ?int $ramAvailableBytes = null,
        public readonly ?int $diskTotalBytes = null,
        public readonly ?int $diskUsedBytes = null,
        public readonly ?int $diskFreeBytes = null,
        public readonly ?float $load1 = null,
        public readonly ?float $load5 = null,
        public readonly ?float $load15 = null,
        public readonly int $pendingUpdates = 0,
        public readonly ?float $cpuCreditBalance = null,
        public readonly ?float $cpuCreditUsage = null,
        public readonly ?float $cpuSurplusCreditBalance = null,
        public readonly ?string $creditSource = null,
    ) {}

    public function ramUsedPercent(): ?float
    {
        if (! $this->ramTotalBytes) {
            return null;
        }

        return round((($this->ramUsedBytes ?? 0) / $this->ramTotalBytes) * 100, 1);
    }

    public function diskUsedPercent(): ?float
    {
        if (! $this->diskTotalBytes) {
            return null;
        }

        return round((($this->diskUsedBytes ?? 0) / $this->diskTotalBytes) * 100, 1);
    }

    public function hasCreditData(): bool
    {
        return $this->cpuCreditBalance !== null;
    }
}
