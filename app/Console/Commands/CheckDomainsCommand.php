<?php

namespace App\Console\Commands;

use App\Enums\CheckStatus;
use App\Models\Site;
use App\Services\Reachability\ReachabilityChecker;
use App\Services\Reachability\ReachabilityRunner;
use Illuminate\Console\Command;

/**
 * DNS, nameserver integrity and the internal/external comparison.
 *
 * Read-only and safe on adopted sites: every result is written to
 * domain_check_* tables keyed by site_id, never to the site row.
 */
class CheckDomainsCommand extends Command
{
    protected $signature = 'jetgrid:check-domains {--site= : Limit to one domain}';

    protected $description = 'Check DNS, nameservers and external reachability. Read-only.';

    public function handle(ReachabilityChecker $checker, ReachabilityRunner $runner): int
    {
        $sites = Site::query()
            ->when($this->option('site'), fn ($q, $domain) => $q->where('domain', $domain))
            ->get();

        if ($sites->isEmpty()) {
            $this->components->warn('No sites to check.');

            return self::SUCCESS;
        }

        $decisions = $runner->run($sites, fn (Site $site) => $checker->runFrequentChecks($site));

        foreach ($sites as $site) {
            $this->components->twoColumnDetail(
                $site->domain.($site->isProtectedResource() ? ' <fg=gray>(protected)</>' : ''),
                $this->verdict($site),
            );
        }

        $this->newLine();
        $this->components->info(
            "Checked {$sites->count()} site(s). ".count($decisions).' alert(s) sent. Nothing was modified.'
        );

        return self::SUCCESS;
    }

    private function verdict(Site $site): string
    {
        $states = $site->checkStates()->get();

        if ($states->isEmpty()) {
            return '<fg=gray>no data</>';
        }

        $failing = $states->where('state', CheckStatus::Failing);

        if ($failing->isNotEmpty()) {
            return '<fg=red>'.$failing->pluck('check_type')->map(fn ($t) => $t->value)->implode(', ').'</>';
        }

        $unknown = $states->where('state', CheckStatus::Unknown);

        if ($unknown->isNotEmpty()) {
            return '<fg=yellow>unknown: '.$unknown->pluck('check_type')->map(fn ($t) => $t->value)->implode(', ').'</>';
        }

        return '<fg=green>reachable</>';
    }
}
