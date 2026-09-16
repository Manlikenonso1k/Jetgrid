<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Reachability\ReachabilityChecker;
use App\Services\Reachability\ReachabilityRunner;
use Illuminate\Console\Command;

/**
 * Registrar status, once per domain per day.
 *
 * Scheduled hourly but staggered by site id, so a hundred domains do not all
 * hit the registry in the same minute. The 24h snapshot cache is the real
 * guard; the stagger just spreads the load underneath it.
 */
class CheckWhoisCommand extends Command
{
    protected $signature = 'jetgrid:check-whois
                            {--site= : Limit to one domain}
                            {--all : Ignore the hourly stagger and check every site}
                            {--force : Bypass the 24h cache (use sparingly — registries rate limit)}';

    protected $description = 'Check registrar/WHOIS status and registry expiry. Read-only.';

    public function handle(ReachabilityChecker $checker, ReachabilityRunner $runner): int
    {
        $sites = Site::query()
            ->when($this->option('site'), fn ($q, $domain) => $q->where('domain', $domain))
            ->get();

        if (! $this->option('site') && ! $this->option('all')) {
            $hour = (int) now()->format('G');
            $sites = $sites->filter(fn (Site $site) => $site->id % 24 === $hour)->values();
        }

        if ($sites->isEmpty()) {
            $this->components->info('No domains scheduled for a WHOIS lookup this hour.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $decisions = $runner->run($sites, fn (Site $site) => [$checker->checkWhois($site, $force)]);

        $this->components->info(
            "Looked up {$sites->count()} domain(s). ".count($decisions).' alert(s) sent.'
        );

        return self::SUCCESS;
    }
}
