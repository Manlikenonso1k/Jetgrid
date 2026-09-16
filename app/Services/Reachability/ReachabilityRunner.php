<?php

namespace App\Services\Reachability;

use App\Models\Site;
use App\Services\Alerts\AlertDecision;
use App\Services\Alerts\AlertGate;
use App\Services\Alerts\TelegramNotifier;
use Illuminate\Support\Collection;

/**
 * Runs a set of checks across sites, passes every outcome through the alert
 * gate, and sends whatever survives.
 *
 * Commands stay thin on purpose: the scheduling cadence differs per check type
 * but the record → decide → notify pipeline is identical, and duplicating it
 * per command is how the cadences drift apart.
 */
class ReachabilityRunner
{
    public function __construct(
        private readonly AlertGate $gate,
        private readonly TelegramNotifier $telegram,
    ) {}

    /**
     * @param  Collection<int,Site>  $sites
     * @param  callable(Site):list<CheckOutcome>  $checks
     * @return list<AlertDecision>
     */
    public function run(Collection $sites, callable $checks): array
    {
        $decisions = [];

        foreach ($sites as $site) {
            foreach ($checks($site) as $outcome) {
                if ($decision = $this->gate->evaluate($site, $outcome)) {
                    $decisions[] = $decision;
                }
            }
        }

        $this->dispatch($decisions);

        return $decisions;
    }

    /** @param list<AlertDecision> $decisions */
    private function dispatch(array $decisions): void
    {
        if ($decisions === [] || ! $this->telegram->enabled()) {
            return;
        }

        $failures = array_values(array_filter($decisions, fn (AlertDecision $d) => ! $d->isRecovery));
        $threshold = (int) config('jetgrid.alerts.digest_threshold', 3);

        // Several sites breaking at once is usually one cause. One digest reads
        // better than eight separate messages and is far harder to miss.
        if (count($failures) >= $threshold) {
            $this->telegram->send($this->digest($failures));

            foreach ($decisions as $decision) {
                if ($decision->isRecovery) {
                    $this->telegram->send($this->message($decision), $decision->site);
                }
            }

            return;
        }

        foreach ($decisions as $decision) {
            $this->telegram->send($this->message($decision), $decision->site);
        }
    }

    public function message(AlertDecision $decision): string
    {
        $outcome = $decision->outcome;
        $rows = ['Site' => $decision->site->domain, 'Check' => $outcome->type->label()];

        if ($decision->isRecovery) {
            $rows['Status'] = $outcome->summary;

            if ($decision->failingFor !== null) {
                $rows['Was failing for'] = $decision->failingFor;
            }

            return $this->telegram->compose($decision->icon(), 'RECOVERED', $rows);
        }

        $rows['Detail'] = $outcome->summary;

        if ($observed = $this->flatten($outcome->observed)) {
            $rows['Observed'] = $observed;
        }

        if ($expected = $this->flatten($outcome->expected)) {
            $rows['Expected'] = $expected;
        }

        return $this->telegram->compose(
            $decision->icon(),
            strtoupper($decision->severity()).' — '.$outcome->type->label(),
            $rows,
        );
    }

    /** @param list<AlertDecision> $failures */
    private function digest(array $failures): string
    {
        $rows = [];

        foreach ($failures as $decision) {
            $rows[$decision->site->domain] = $decision->outcome->type->label().' — '.$decision->outcome->summary;
        }

        return $this->telegram->compose(
            "\u{1F534}",
            count($failures).' sites failing',
            $rows,
            'Grouped because several checks failed in the same run.',
        );
    }

    /** @param array<string,mixed> $data */
    private function flatten(array $data): ?string
    {
        if ($data === []) {
            return null;
        }

        $parts = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map(
                    fn ($v) => is_array($v) ? json_encode($v) : (string) $v,
                    $value,
                ));
            }

            $parts[] = "{$key}=".(is_scalar($value) || $value === null ? (string) $value : json_encode($value));
        }

        return implode(' · ', $parts);
    }
}
