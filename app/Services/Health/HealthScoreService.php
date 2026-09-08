<?php

namespace App\Services\Health;

use App\Enums\BeaconColor;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Metrics\ServerFacts;

/**
 * Feature 13 — a composite score, and Feature 1's beacon colour derived from it.
 *
 * The score is a weighted average of five components, each normalised to 0..1.
 * A component with nothing to say (no checks yet, no backups configured) is
 * dropped and the remaining weights are renormalised, so a brand-new site does
 * not read as unhealthy just because JetGrid has not watched it for long.
 */
class HealthScoreService
{
    public function score(Site $site): HealthScore
    {
        $weights = (array) config('jetgrid.health.weights');
        $components = [];

        if (($uptime = $this->uptimeComponent($site)) !== null) {
            $components['uptime'] = $uptime;
        }

        if (($cert = $this->certificateComponent($site)) !== null) {
            $components['certificate'] = $cert;
        }

        $components['updates'] = $this->updatesComponent($site);

        if (($errors = $this->errorRateComponent($site)) !== null) {
            $components['error_rate'] = $errors;
        }

        if (($backup = $this->backupComponent($site)) !== null) {
            $components['backup_freshness'] = $backup;
        }

        $totalWeight = array_sum(array_intersect_key($weights, $components));

        if ($totalWeight <= 0) {
            return new HealthScore(0, [], 'No data yet.');
        }

        $sum = 0.0;

        foreach ($components as $key => $value) {
            $sum += $value * ($weights[$key] ?? 0);
        }

        return new HealthScore(
            score: (int) round(($sum / $totalWeight) * 100),
            components: $components,
            note: count($components) < count($weights)
                ? 'Scored on '.count($components).' of '.count($weights).' components; the rest have no data yet.'
                : null,
        );
    }

    /**
     * Feature 1's beacon. Order matters: protection is checked first because an
     * adopted site is grey no matter how healthy or broken it is — JetGrid is
     * not managing it and should not imply otherwise.
     */
    public function beacon(Site $site, ?ServerFacts $facts = null): BeaconColor
    {
        if ($site->isProtectedResource()) {
            return BeaconColor::Grey;
        }

        if ($site->hasDeploymentInProgress()) {
            return BeaconColor::Blue;
        }

        if ($site->status === SiteStatus::Down || $this->serverOverloaded($facts)) {
            return BeaconColor::Red;
        }

        $certificate = $site->certificate;

        if ($certificate?->not_after !== null && $certificate->not_after->isPast()) {
            return BeaconColor::Red;
        }

        $warnDays = (int) config('jetgrid.certificates.warn_days_before');

        if ($site->pending_updates > 0 || $certificate?->expiresWithinDays($warnDays)) {
            return BeaconColor::Yellow;
        }

        if ($site->status === SiteStatus::Degraded) {
            return BeaconColor::Yellow;
        }

        return BeaconColor::Green;
    }

    /** Load average above 1.0 per core is the overload signal. */
    private function serverOverloaded(?ServerFacts $facts): bool
    {
        if ($facts?->load5 === null) {
            return false;
        }

        $cores = max(1, (int) (config('jetgrid.instance_vcpu') ?? 2));

        return $facts->load5 > $cores * 1.5;
    }

    private function uptimeComponent(Site $site): ?float
    {
        $checks = $site->healthChecks()
            ->where('checked_at', '>=', now()->subDay())
            ->get(['ok']);

        if ($checks->isEmpty()) {
            return null;
        }

        return $checks->avg(static fn ($c) => $c->ok ? 1.0 : 0.0);
    }

    private function certificateComponent(Site $site): ?float
    {
        $certificate = $site->certificate;

        if ($certificate === null || $certificate->not_after === null) {
            return null;
        }

        $days = $certificate->daysRemaining() ?? 0;

        return match (true) {
            $days <= 0 => 0.0,
            $days < 7 => 0.25,
            $days < 14 => 0.5,
            $days < 30 => 0.8,
            default => 1.0,
        };
    }

    private function updatesComponent(Site $site): float
    {
        return match (true) {
            $site->pending_updates === 0 => 1.0,
            $site->pending_updates <= 5 => 0.7,
            $site->pending_updates <= 20 => 0.4,
            default => 0.1,
        };
    }

    private function errorRateComponent(Site $site): ?float
    {
        $checks = $site->healthChecks()
            ->where('checked_at', '>=', now()->subDay())
            ->whereNotNull('http_status')
            ->get(['http_status']);

        if ($checks->isEmpty()) {
            return null;
        }

        $errors = $checks->filter(static fn ($c) => $c->http_status >= 500)->count();

        return 1.0 - ($errors / $checks->count());
    }

    private function backupComponent(Site $site): ?float
    {
        $latest = $site->backups()
            ->whereNotNull('verified_at')
            ->where('verify_result', 'passed')
            ->latest('verified_at')
            ->first();

        if ($latest === null) {
            // No verified backup at all is only a penalty once the site is
            // managed — an adopted site is not JetGrid's to back up.
            return $site->isManaged() ? 0.0 : null;
        }

        $age = $latest->verified_at->diffInDays(now());

        return match (true) {
            $age <= 1 => 1.0,
            $age <= 7 => 0.8,
            $age <= 30 => 0.4,
            default => 0.1,
        };
    }
}
