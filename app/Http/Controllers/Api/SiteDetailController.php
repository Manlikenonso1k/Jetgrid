<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Health\HealthScoreService;
use Illuminate\Http\JsonResponse;

/** Side panel content when a house is clicked (Feature 1). */
class SiteDetailController extends Controller
{
    public function __construct(private readonly HealthScoreService $health) {}

    public function __invoke(Site $site): JsonResponse
    {
        $this->authorize('view', $site);

        $score = $this->health->score($site);
        $certificate = $site->certificate;

        $checks = $site->healthChecks()
            ->latest('checked_at')
            ->limit(30)
            ->get(['ok', 'http_status', 'response_ms', 'checked_at']);

        return response()->json([
            'id' => $site->id,
            'domain' => $site->domain,
            'protected' => $site->isProtectedResource(),
            'modeLabel' => $site->management_mode->label(),
            'documentRoot' => $site->document_root,
            'phpVersion' => $site->php_version,
            'vhostPath' => $site->vhost_path,
            'aliases' => $site->meta['aliases'] ?? [],
            'status' => $site->status->value,
            'health' => $score->score,
            'healthBand' => $score->band(),
            'healthNote' => $score->note,
            'components' => $score->components,
            'pendingUpdates' => (int) $site->pending_updates,
            'diskBytes' => (int) $site->disk_bytes,
            'certificate' => $certificate === null ? null : [
                'domain' => $certificate->domain,
                'expiresAt' => $certificate->not_after?->toDateString(),
                'daysRemaining' => $certificate->daysRemaining(),
                'status' => $certificate->status->value,
                'managed' => (bool) $certificate->is_managed,
            ],
            'responseHistory' => $checks->reverse()->values()->map(fn ($c) => [
                'ok' => (bool) $c->ok,
                'status' => $c->http_status,
                'ms' => $c->response_ms,
                'at' => $c->checked_at?->toIso8601String(),
            ]),
            // The UI uses this to decide whether to render action buttons at
            // all. Safety constraint #1: protected sites must not even show them.
            'actions' => $site->isProtectedResource() ? [] : [
                'deploy', 'certificate', 'maintenance', 'diagnostics', 'clone',
            ],
        ]);
    }
}
