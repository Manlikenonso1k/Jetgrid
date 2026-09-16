<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Reachability\ReachabilityChecker;
use App\Services\Reachability\ReachabilityRunner;
use Illuminate\Console\Command;

/**
 * Certificate expiry read from the externally resolved host.
 *
 * Adopted sites are included: reading an expiry date is monitoring, and the
 * certificate is never touched. Only managed certificates are ever renewed, and
 * that happens in a different scheduled task entirely.
 */
class CheckTlsCommand extends Command
{
    protected $signature = 'jetgrid:check-tls {--site= : Limit to one domain}';

    protected $description = 'Check TLS certificate expiry from the outside. Read-only.';

    public function handle(ReachabilityChecker $checker, ReachabilityRunner $runner): int
    {
        $sites = Site::query()
            ->when($this->option('site'), fn ($q, $domain) => $q->where('domain', $domain))
            ->get();

        $decisions = $runner->run($sites, fn (Site $site) => [$checker->checkTls($site)]);

        $this->components->info(
            "Inspected {$sites->count()} certificate(s). ".count($decisions).' alert(s) sent.'
        );

        return self::SUCCESS;
    }
}
