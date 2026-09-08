<?php

namespace App\Console\Commands;

use App\Services\Discovery\DiscoveryService;
use App\Services\Server\ServerDriver;
use Illuminate\Console\Command;

class DiscoverCommand extends Command
{
    protected $signature = 'jetgrid:discover';

    protected $description = 'Read-only scan of this server. Imports what it finds as Adopted — Protected.';

    public function handle(DiscoveryService $discovery, ServerDriver $driver): int
    {
        $this->components->info('Read-only discovery — driver: '.$driver->name());

        if ($driver->isFake()) {
            $this->components->warn('Fake driver: reading fixtures, not a real server.');
        }

        $report = $discovery->run();

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Resource type</>', '<fg=gray>Found</>');

        foreach ($report->countByType() as $type => $count) {
            $this->components->twoColumnDetail($type, (string) $count);
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Sites imported (new)</>', (string) count($report->sitesCreated));

        foreach ($report->sitesCreated as $domain) {
            $this->line("    <fg=green>+</> {$domain} <fg=gray>— Adopted, Protected</>");
        }

        if ($report->sitesSeenAgain !== []) {
            $this->newLine();
            $this->components->twoColumnDetail('Sites already known', (string) count($report->sitesSeenAgain));
        }

        if ($report->drifted !== []) {
            $this->newLine();
            $this->components->warn('Config files changed since JetGrid last saw them:');

            foreach ($report->drifted as $item) {
                $this->line("    <fg=yellow>~</> {$item['name']} ({$item['path']})");
            }
        }

        if ($report->unreadable !== []) {
            $this->newLine();
            $this->components->warn('Unreadable (skipped, not an error):');

            foreach ($report->unreadable as $path) {
                $this->line("    <fg=gray>?</> {$path}");
            }
        }

        $this->newLine();
        $this->components->info('Nothing on the server was modified. Every record above is monitoring-only.');

        return self::SUCCESS;
    }
}
