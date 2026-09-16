<?php

namespace App\Services\Alerts;

use App\Enums\CheckStatus;
use App\Models\DomainCheckResult;
use App\Models\DomainCheckState;
use App\Models\Site;
use App\Services\Reachability\CheckOutcome;

/**
 * Decides whether an observation deserves a message.
 *
 * This is the part that makes the difference between monitoring and noise. A
 * check running every five minutes sees the same failure 288 times a day; the
 * operator must be told once. So alerts fire on TRANSITIONS only —
 * healthy→failing and failing→healthy — and a transition into failure must be
 * confirmed by N consecutive observations before it counts, so a single DNS
 * blip stays silent.
 */
class AlertGate
{
    /**
     * Record the outcome and return a decision if — and only if — a human needs
     * to hear about it right now.
     */
    public function evaluate(Site $site, CheckOutcome $outcome): ?AlertDecision
    {
        DomainCheckResult::create([
            'site_id' => $site->id,
            'check_type' => $outcome->type,
            'status' => $outcome->status,
            'severity' => $outcome->severity,
            'summary' => $outcome->summary,
            'observed' => $outcome->observed,
            'expected' => $outcome->expected,
            'checked_at' => now(),
        ]);

        $state = DomainCheckState::firstOrNew([
            'site_id' => $site->id,
            'check_type' => $outcome->type,
        ]);

        return match ($outcome->status) {
            CheckStatus::Failing => $this->onFailing($site, $outcome, $state),
            CheckStatus::Ok => $this->onOk($site, $outcome, $state),
            // "We could not tell" must neither raise an alarm nor clear one.
            CheckStatus::Unknown => $this->onUnknown($outcome, $state),
        };
    }

    private function onFailing(Site $site, CheckOutcome $outcome, DomainCheckState $state): ?AlertDecision
    {
        $alreadyFailing = $state->state === CheckStatus::Failing;

        $state->fill([
            'state' => CheckStatus::Failing,
            'consecutive_failures' => $alreadyFailing ? $state->consecutive_failures + 1 : 1,
            'failing_since' => $alreadyFailing ? $state->failing_since : now(),
            'last_summary' => $outcome->summary,
        ]);

        $threshold = max(1, (int) config('jetgrid.alerts.failures_before_alert', 2));

        // Not confirmed yet — record the observation, stay quiet.
        if ($state->consecutive_failures < $threshold) {
            $state->save();

            return null;
        }

        // Already told them. Silence until the state changes back.
        if ($state->last_alerted_state === CheckStatus::Failing->value) {
            $state->save();

            return null;
        }

        if ($this->rateLimited($state)) {
            $state->save();

            return null;
        }

        $state->fill([
            'last_alerted_state' => CheckStatus::Failing->value,
            'last_alerted_at' => now(),
        ])->save();

        return new AlertDecision($site, $outcome, isRecovery: false);
    }

    private function onOk(Site $site, CheckOutcome $outcome, DomainCheckState $state): ?AlertDecision
    {
        $wasAlerted = $state->last_alerted_state === CheckStatus::Failing->value;
        $downFor = $wasAlerted && $state->failing_since
            ? $state->failing_since->diffForHumans(now(), short: false, syntax: true)
            : null;

        $state->fill([
            'state' => CheckStatus::Ok,
            'consecutive_failures' => 0,
            'failing_since' => null,
            'last_summary' => $outcome->summary,
        ]);

        if (! $wasAlerted) {
            $state->save();

            return null;
        }

        // Recovery always goes out, rate limit or not: the message that closes
        // an incident is never noise.
        $state->fill([
            'last_alerted_state' => CheckStatus::Ok->value,
            'last_alerted_at' => now(),
        ])->save();

        return new AlertDecision($site, $outcome, isRecovery: true, failingFor: $downFor);
    }

    private function onUnknown(CheckOutcome $outcome, DomainCheckState $state): ?AlertDecision
    {
        // Leave consecutive_failures and failing_since untouched: an
        // indeterminate result neither confirms a failure nor resolves one.
        $state->fill([
            'state' => CheckStatus::Unknown,
            'last_summary' => $outcome->summary,
        ])->save();

        return null;
    }

    private function rateLimited(DomainCheckState $state): bool
    {
        $minutes = (int) config('jetgrid.alerts.rate_limit_minutes', 60);

        return $state->last_alerted_at !== null
            && $state->last_alerted_at->gt(now()->subMinutes($minutes));
    }
}
