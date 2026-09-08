<?php

use App\Models\Certificate;
use App\Services\Certificates\CertificateService;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| JetGrid schedule
|--------------------------------------------------------------------------
| Everything here is read-only except the renewal task, which is gated by the
| kill switch like any other write: with JETGRID_READONLY=true it will refuse,
| log the refusal to the audit log, and alert. That is the intended behaviour —
| a read-only install should not silently start touching certificates.
*/

Schedule::command('jetgrid:monitor')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('jetgrid:discover')
    ->hourly()
    ->withoutOverlapping();

/*
 * Feature 3: attempt renewal from 30 days out, so there are weeks of retries
 * before anything expires. Managed certificates only — dueForRenewal() filters
 * on is_managed, and CertificateService::renew() refuses a protected one.
 */
Schedule::call(function (): void {
    $service = app(CertificateService::class);
    $service->import();

    foreach ($service->dueForRenewal() as $certificate) {
        $result = $service->renew($certificate, dryRun: false);

        if (! $result['ok']) {
            // Feature 3: "Alert me on renewal failure, loudly."
            app(\App\Services\Alerts\AlertDispatcher::class)->send(
                subject: "Certificate renewal FAILED: {$certificate->domain}",
                body: $result['message']."\n\nAttempt "
                    .($certificate->fresh()->renewal_failures)
                    .' of '.config('jetgrid.certificates.max_renewal_attempts')
                    .". Expires ".($certificate->not_after?->diffForHumans() ?? 'unknown').'.',
                severity: 'critical',
            );
        }
    }
})->dailyAt('03:20')->name('jetgrid:renew-certificates')->withoutOverlapping();

/* Warn while there is still time to act, not on the day it breaks. */
Schedule::call(function (): void {
    $warnDays = (int) config('jetgrid.certificates.warn_days_before');

    $expiring = Certificate::query()
        ->whereNotNull('not_after')
        ->where('not_after', '<=', now()->addDays($warnDays))
        ->where('not_after', '>', now())
        ->get();

    foreach ($expiring as $certificate) {
        app(\App\Services\Alerts\AlertDispatcher::class)->send(
            subject: "Certificate expiring: {$certificate->domain}",
            body: "Expires in {$certificate->daysRemaining()} days."
                .($certificate->is_managed
                    ? ' JetGrid will attempt renewal automatically.'
                    : ' This is an ADOPTED certificate — JetGrid will not renew it. Renew it yourself.'),
            severity: 'warning',
        );
    }
})->dailyAt('08:00')->name('jetgrid:cert-expiry-warnings');
