<?php

namespace App\Services\Metrics;

use Aws\CloudWatch\CloudWatchClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * CPUCreditBalance / CPUCreditUsage / CPUSurplusCreditBalance from CloudWatch.
 *
 * On a t-family instance this is the metric that actually decides how many more
 * sites fit, which is why Feature 2 calls it out. When credentials or the
 * instance id are missing this returns nulls and a source of 'unavailable' —
 * the UI then says "CPU credits unknown" instead of showing a made-up number,
 * and the capacity estimator drops CPU from its limiting-factor comparison.
 */
class CpuCreditReader
{
    /** @return array{balance:?float,usage:?float,surplus:?float,source:?string} */
    public function read(): array
    {
        $unavailable = ['balance' => null, 'usage' => null, 'surplus' => null, 'source' => 'unavailable'];

        $instanceId = config('jetgrid.instance_id');

        if (! $instanceId || ! config('jetgrid.cloudwatch_enabled')) {
            return $unavailable;
        }

        try {
            return Cache::remember('jetgrid.cpu_credits', now()->addMinutes(5), function () use ($instanceId) {
                $client = new CloudWatchClient([
                    'region' => config('jetgrid.pricing.region'),
                    'version' => 'latest',
                ]);

                return [
                    'balance' => $this->latest($client, $instanceId, 'CPUCreditBalance'),
                    'usage' => $this->latest($client, $instanceId, 'CPUCreditUsage'),
                    'surplus' => $this->latest($client, $instanceId, 'CPUSurplusCreditBalance'),
                    'source' => 'cloudwatch',
                ];
            });
        } catch (Throwable) {
            // A missing IAM permission must not take the dashboard down.
            return $unavailable;
        }
    }

    private function latest(CloudWatchClient $client, string $instanceId, string $metric): ?float
    {
        $result = $client->getMetricStatistics([
            'Namespace' => 'AWS/EC2',
            'MetricName' => $metric,
            'Dimensions' => [['Name' => 'InstanceId', 'Value' => $instanceId]],
            'StartTime' => now()->subHours(3)->toIso8601String(),
            'EndTime' => now()->toIso8601String(),
            'Period' => 300,
            'Statistics' => ['Average'],
        ]);

        $points = $result['Datapoints'] ?? [];

        if ($points === []) {
            return null;
        }

        usort($points, static fn ($a, $b) => $a['Timestamp'] <=> $b['Timestamp']);

        return (float) end($points)['Average'];
    }
}
