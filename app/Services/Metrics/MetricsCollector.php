<?php

namespace App\Services\Metrics;

use App\Models\Metric;
use App\Models\Site;
use App\Services\Privilege\CommandRunner;

/**
 * Reads the server using only whitelisted, read-only commands, so it runs
 * unchanged with JETGRID_READONLY=true.
 */
class MetricsCollector
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly CpuCreditReader $credits,
    ) {}

    public function collect(): ServerFacts
    {
        $mem = $this->parseFree($this->stdout('mem.info'));
        $disk = $this->parseDf($this->stdout('disk.usage'));
        $load = $this->parseLoadAvg($this->stdout('load.avg'));
        $updates = $this->parseUpgradable($this->stdout('apt.upgradable'));
        $credit = $this->credits->read();

        return new ServerFacts(
            ramTotalBytes: $mem['total'],
            ramUsedBytes: $mem['used'],
            ramAvailableBytes: $mem['available'],
            diskTotalBytes: $disk['total'],
            diskUsedBytes: $disk['used'],
            diskFreeBytes: $disk['free'],
            load1: $load[0],
            load5: $load[1],
            load15: $load[2],
            pendingUpdates: $updates,
            cpuCreditBalance: $credit['balance'],
            cpuCreditUsage: $credit['usage'],
            cpuSurplusCreditBalance: $credit['surplus'],
            creditSource: $credit['source'],
        );
    }

    /** Persist a snapshot for the sparklines. */
    public function record(ServerFacts $facts): void
    {
        $now = now();

        $points = array_filter([
            'ram_used_pct' => $facts->ramUsedPercent(),
            'disk_used_pct' => $facts->diskUsedPercent(),
            'load_1' => $facts->load1,
            'cpu_credit_balance' => $facts->cpuCreditBalance,
        ], static fn ($v) => $v !== null);

        foreach ($points as $key => $value) {
            Metric::create([
                'site_id' => null,
                'key' => $key,
                'value' => $value,
                'recorded_at' => $now,
            ]);
        }
    }

    /** Per-site disk footprint, used for house size and cost attribution. */
    public function measureSite(Site $site): void
    {
        if (! $site->document_root) {
            return;
        }

        $outcome = $this->runner->run('disk.dir_size', ['path' => $site->document_root]);

        if (! $outcome->ok()) {
            return;
        }

        $bytes = (int) (preg_split('/\s+/', trim($outcome->result->stdout))[0] ?? 0);

        if ($bytes > 0) {
            $site->update(['disk_bytes' => $bytes]);
        }
    }

    private function stdout(string $key): string
    {
        $outcome = $this->runner->run($key);

        return $outcome->ok() ? $outcome->result->stdout : '';
    }

    /** @return array{total:?int,used:?int,available:?int} */
    private function parseFree(string $output): array
    {
        if (preg_match('/^Mem:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/mi', $output, $m) !== 1) {
            return ['total' => null, 'used' => null, 'available' => null];
        }

        return [
            'total' => (int) $m[1],
            'used' => (int) $m[2],
            // "available" is the honest number for capacity: it counts
            // reclaimable page cache, which "free" does not.
            'available' => (int) $m[6],
        ];
    }

    /** @return array{total:?int,used:?int,free:?int} */
    private function parseDf(string $output): array
    {
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $cols = preg_split('/\s+/', trim($line)) ?: [];

            // Only the root filesystem matters for "can another site fit".
            if (count($cols) >= 6 && end($cols) === '/') {
                return ['total' => (int) $cols[1], 'used' => (int) $cols[2], 'free' => (int) $cols[3]];
            }
        }

        return ['total' => null, 'used' => null, 'free' => null];
    }

    /** @return array{0:?float,1:?float,2:?float} */
    private function parseLoadAvg(string $output): array
    {
        $parts = preg_split('/\s+/', trim($output)) ?: [];

        return [
            isset($parts[0]) ? (float) $parts[0] : null,
            isset($parts[1]) ? (float) $parts[1] : null,
            isset($parts[2]) ? (float) $parts[2] : null,
        ];
    }

    private function parseUpgradable(string $output): int
    {
        $count = 0;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (str_contains($line, '[upgradable from:')) {
                $count++;
            }
        }

        return $count;
    }
}
