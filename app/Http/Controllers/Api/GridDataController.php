<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Capacity\CapacityEstimator;
use App\Services\Health\GridLayout;
use App\Services\Health\HealthScoreService;
use App\Services\Metrics\MetricsCollector;
use App\Support\KillSwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The 3D dashboard's data pipeline.
 *
 * POLLING, not websockets — deliberately. Reverb/soketi would mean another
 * long-lived PHP process and another ~80-150MB of RAM on an instance whose
 * whole problem is that it is already hosting three projects. The scene needs
 * fresh data every few seconds, not every few milliseconds, so polling a cached
 * JSON document costs almost nothing and adds no moving parts.
 *
 * The server-side work is cached for a few seconds so that several open tabs
 * cannot multiply into real load.
 */
class GridDataController extends Controller
{
    public function __construct(
        private readonly HealthScoreService $health,
        private readonly CapacityEstimator $capacity,
        private readonly MetricsCollector $metrics,
        private readonly GridLayout $layout,
        private readonly KillSwitch $killSwitch,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $payload = Cache::remember('jetgrid.grid', now()->addSeconds(5), fn () => $this->build());

        return response()->json($payload);
    }

    private function build(): array
    {
        $facts = $this->metrics->collect();
        $estimate = $this->capacity->estimate($facts);

        $sites = Site::query()->with(['certificate'])->get();
        $this->layout->assign($sites);
        $sites = $sites->fresh(['certificate']);

        $anyDeploying = false;

        $houses = $sites->map(function (Site $site) use ($facts, &$anyDeploying) {
            $beacon = $this->health->beacon($site, $facts);
            $score = $this->health->score($site);
            $certificate = $site->certificate;

            if ($beacon->value === 'blue') {
                $anyDeploying = true;
            }

            return [
                'id' => $site->id,
                'domain' => $site->domain,
                'name' => $site->display_name ?? $site->domain,
                'x' => $site->grid_x ?? 0,
                'z' => $site->grid_z ?? 0,
                'protected' => $site->isProtectedResource(),
                'mode' => $site->management_mode->value,
                'status' => $site->status->value,
                'beacon' => $beacon->value,
                'beaconHex' => $beacon->hex(),
                'blinkHz' => $beacon->blinkHz(),
                'health' => $score->score,
                'healthBand' => $score->band(),
                // House size: footprint drives the geometry, clamped so one big
                // site cannot squash everything else into invisibility.
                'scale' => $this->houseScale($site),
                'diskBytes' => (int) $site->disk_bytes,
                'ramMb' => (int) $site->ram_mb,
                'traffic' => (int) $site->requests_per_minute,
                'pendingUpdates' => (int) $site->pending_updates,
                'certDays' => $certificate?->daysRemaining(),
                'certStatus' => $certificate?->status->value,
                'maintenance' => (bool) $site->maintenance_mode,
            ];
        })->values();

        return [
            'generatedAt' => now()->toIso8601String(),
            'readOnly' => $this->killSwitch->isReadOnly(),
            'readOnlyReason' => $this->killSwitch->explain(),
            'houses' => $houses,
            'emptyPlots' => $this->layout->emptyPlots($sites, min($estimate->slots, 48)),
            'capacity' => [
                'slots' => $estimate->slots,
                'headline' => $estimate->headline(),
                'limitingFactor' => $estimate->limitingFactor,
                'limitingLabel' => $estimate->limitingLabel,
                'burningCredits' => $this->capacity->burningCredits($facts),
            ],
            'server' => [
                'ramPercent' => $facts->ramUsedPercent(),
                'diskPercent' => $facts->diskUsedPercent(),
                'load1' => $facts->load1,
                'load5' => $facts->load5,
                'creditBalance' => $facts->cpuCreditBalance,
                'creditSource' => $facts->creditSource,
                'pendingUpdates' => $facts->pendingUpdates,
            ],
            // Poll faster while something is building, so the blue beacon and
            // the deploy log feel live without a websocket.
            'pollMs' => $anyDeploying
                ? (int) config('jetgrid.poll.active_ms')
                : (int) config('jetgrid.poll.idle_ms'),
        ];
    }

    /** Map disk footprint onto a 0.6–1.8 house scale on a log curve. */
    private function houseScale(Site $site): float
    {
        $bytes = max(1, (int) $site->disk_bytes);
        $gib = $bytes / 1073741824;

        // Log so a 40GB site is visibly bigger than a 4GB one without being
        // ten times the size and hiding its neighbours.
        $scale = 0.6 + (log10(max(0.1, $gib)) + 1) * 0.4;

        return round(max(0.6, min(1.8, $scale)), 3);
    }
}
