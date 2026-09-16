<?php

namespace App\Console\Commands;

use App\Enums\CheckStatus;
use App\Models\DomainCheckState;
use App\Models\Site;
use App\Services\Alerts\TelegramNotifier;
use Illuminate\Console\Command;

/**
 * The all-clear.
 *
 * Sent every day whether or not anything is wrong, because silent monitoring
 * and broken monitoring look identical from the outside. If this message stops
 * arriving, that is itself the signal.
 */
class DailySummaryCommand extends Command
{
    protected $signature = 'jetgrid:daily-summary';

    protected $description = 'Send the daily monitoring summary, healthy or not.';

    public function handle(TelegramNotifier $telegram): int
    {
        $sites = Site::query()->orderBy('domain')->get();
        $states = DomainCheckState::query()->get()->groupBy('site_id');

        $failing = [];
        $unknown = [];

        foreach ($sites as $site) {
            foreach ($states->get($site->id, collect()) as $state) {
                $line = $site->domain.' — '.$state->check_type->label();

                if ($state->state === CheckStatus::Failing) {
                    $failing[] = $line.': '.$state->last_summary;
                } elseif ($state->state === CheckStatus::Unknown) {
                    $unknown[] = $line;
                }
            }
        }

        $rows = [
            'Sites monitored' => (string) $sites->count(),
            'Failing checks' => (string) count($failing),
            'Indeterminate' => (string) count($unknown),
        ];

        foreach (array_slice($failing, 0, 15) as $i => $line) {
            $rows['Failing '.($i + 1)] = $line;
        }

        foreach (array_slice($unknown, 0, 10) as $i => $line) {
            $rows['Unknown '.($i + 1)] = $line;
        }

        $healthy = $failing === [] && $unknown === [];

        $message = $telegram->compose(
            $healthy ? "\u{1F7E2}" : "\u{1F7E1}",
            'JetGrid daily summary — '.now()->toDateString(),
            $rows,
            $healthy ? 'All monitored domains resolved and reachable in the last 24h.' : null,
        );

        $telegram->send($message);

        $this->components->info(
            $healthy
                ? "All {$sites->count()} site(s) healthy. Summary sent."
                : count($failing).' failing, '.count($unknown).' unknown. Summary sent.'
        );

        return self::SUCCESS;
    }
}
