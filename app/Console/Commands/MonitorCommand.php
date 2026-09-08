<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Models\HealthCheck;
use App\Models\Site;
use App\Services\Health\HealthScoreService;
use App\Services\Metrics\MetricsCollector;
use Illuminate\Console\Command;

/**
 * Feature 6 — the monitoring tick.
 *
 * Read-only throughout: it runs unchanged with JETGRID_READONLY=true and it
 * monitors adopted sites exactly as it monitors managed ones. The only writes
 * are to JetGrid's own database.
 */
class MonitorCommand extends Command
{
    protected $signature = 'jetgrid:monitor {--site= : Limit to one domain}';

    protected $description = 'Collect server metrics and check every site. Read-only.';

    public function handle(MetricsCollector $metrics, HealthScoreService $health): int
    {
        $facts = $metrics->collect();
        $metrics->record($facts);

        $sites = Site::query()
            ->when($this->option('site'), fn ($q, $domain) => $q->where('domain', $domain))
            ->get();

        foreach ($sites as $site) {
            $check = $this->check($site);

            HealthCheck::create([
                'site_id' => $site->id,
                'http_status' => $check['status'],
                'response_ms' => $check['ms'],
                'ok' => $check['ok'],
                'error' => $check['error'],
                'checked_at' => now(),
            ]);

            $metrics->measureSite($site);
            $site->refresh();

            // Only monitoring columns are touched, so this is legal for adopted
            // sites too — see Site::MONITORING_FIELDS.
            $site->update([
                'status' => $check['ok'] ? SiteStatus::Healthy : SiteStatus::Down,
                'pending_updates' => $facts->pendingUpdates,
            ]);

            $site->refresh();

            $site->update([
                'health_score' => $health->score($site)->score,
                'beacon_color' => $health->beacon($site, $facts),
            ]);

            $this->components->twoColumnDetail(
                $site->domain.($site->isProtectedResource() ? ' <fg=gray>(protected)</>' : ''),
                ($check['ok'] ? '<fg=green>up</>' : '<fg=red>down</>')
                    .' · '.$site->fresh()->beacon_color->value
                    .' · '.$site->fresh()->health_score.'/100',
            );
        }

        $this->newLine();
        $this->components->info("Checked {$sites->count()} site(s). Nothing on the server was modified.");

        return self::SUCCESS;
    }

    /** @return array{ok:bool,status:?int,ms:?int,error:?string} */
    private function check(Site $site): array
    {
        $start = microtime(true);

        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => 10,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
        ]);

        $headers = @get_headers("https://{$site->domain}/", context: $context);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($headers === false) {
            return ['ok' => false, 'status' => null, 'ms' => $ms, 'error' => 'no response'];
        }

        $status = preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $m) === 1 ? (int) $m[1] : null;

        return [
            'ok' => $status !== null && $status < 400,
            'status' => $status,
            'ms' => $ms,
            'error' => null,
        ];
    }
}
