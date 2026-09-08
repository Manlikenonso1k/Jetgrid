<?php

namespace App\Services\Certificates;

use App\Enums\CertificateStatus;
use App\Exceptions\ProtectedResourceException;
use App\Models\Certificate;
use App\Models\Site;
use App\Services\Privilege\CommandRunner;
use App\Support\ProtectedResourceGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Feature 3. The highest-consequence subsystem in the app, so it is also the
 * most gated: nothing here reaches certbot without passing the protected gate,
 * DNS pre-flight and the rate-limit tracker first.
 *
 * Adopted certificates are read (import(), inspect()) but never issued, renewed
 * or revoked.
 */
class CertificateService
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly DnsPreflight $preflight,
        private readonly RateLimitTracker $rateLimits,
        private readonly ProtectedResourceGuard $guard,
    ) {}

    /**
     * Read every certificate on the box, managed or not, and record expiry.
     *
     * Certificates for sites JetGrid did not create are stored with
     * is_managed=false, which is what makes them display-only downstream.
     */
    public function import(): int
    {
        $outcome = $this->runner->run('certbot.list');

        if (! $outcome->ok()) {
            return 0;
        }

        $imported = 0;

        foreach ($this->parseCertbotList($outcome->result->stdout) as $parsed) {
            $site = Site::firstWhere('domain', $parsed['name'])
                ?? Site::query()->whereIn('domain', $parsed['domains'])->first();

            if ($site === null) {
                continue;
            }

            Certificate::updateOrCreate(
                ['site_id' => $site->id, 'domain' => $parsed['name']],
                [
                    'sans' => $parsed['domains'],
                    'not_after' => $parsed['expires'],
                    'cert_path' => $parsed['path'],
                    'issuer' => "Let's Encrypt",
                    // Managed only if JetGrid manages the site it belongs to.
                    'is_managed' => $site->isManaged(),
                    'status' => $this->statusFor($parsed['expires']),
                ],
            );

            $imported++;
        }

        return $imported;
    }

    /**
     * @param  bool  $dryRun  null falls back to config; the UI passes an explicit choice
     * @return array{ok:bool,message:string,preview:string,checks:list<PreflightCheck>}
     */
    public function issue(Site $site, string $email, string $challenge = 'http-01', ?bool $dryRun = null): array
    {
        // 1. Protected gate first — before DNS lookups, before anything.
        $this->guard->assertWritable($site, 'issue a certificate for');

        $domains = $this->domainsFor($site);

        // 2. Rate limit. Refusing here is the whole point: a failed attempt
        //    still consumes one of the five weekly slots.
        if ($this->rateLimits->wouldExceed($domains)) {
            $next = $this->rateLimits->nextSlotAt($domains);

            return [
                'ok' => false,
                'message' => "Blocked: {$site->domain} has used all "
                    .config('jetgrid.certificates.le_duplicate_limit')
                    .' Let\'s Encrypt duplicate-certificate slots this week. Next slot '
                    .($next?->diffForHumans() ?? 'unknown').'. JetGrid refused rather than burning a rate limit.',
                'preview' => '',
                'checks' => [],
            ];
        }

        // 3. DNS pre-flight (Feature 11).
        $checks = $challenge === 'http-01' ? $this->preflight->run($site->domain) : [];

        foreach ($checks as $check) {
            if ($check->blocking()) {
                return [
                    'ok' => false,
                    'message' => "Pre-flight failed ({$check->name}): {$check->message}",
                    'preview' => '',
                    'checks' => $checks,
                ];
            }
        }

        $key = $challenge === 'dns-01' ? 'certbot.issue.dns01' : 'certbot.issue.http01';

        $args = $challenge === 'dns-01'
            ? ['domain' => $site->domain, 'email' => $email]
            : ['domain' => $site->domain, 'email' => $email, 'webroot' => config('jetgrid.certificates.webroot')];

        $preview = $this->runner->preview($key, $args);

        $outcome = $this->runner->run($key, $args, $site, $dryRun);

        if (! $outcome->wasDryRun) {
            $this->rateLimits->record($domains, $outcome->ok());
        }

        if ($outcome->wasDryRun) {
            return [
                'ok' => true,
                'message' => 'Dry run — nothing was executed. This is the exact command that would run.',
                'preview' => $preview,
                'checks' => $checks,
            ];
        }

        if ($outcome->ok()) {
            $this->import();

            return ['ok' => true, 'message' => "Certificate issued for {$site->domain}.", 'preview' => $preview, 'checks' => $checks];
        }

        return [
            'ok' => false,
            'message' => 'certbot failed: '.trim($outcome->result->stderr ?: $outcome->result->stdout),
            'preview' => $preview,
            'checks' => $checks,
        ];
    }

    /** @return array{ok:bool,message:string} */
    public function renew(Certificate $certificate, ?bool $dryRun = null): array
    {
        if ($certificate->isProtectedResource()) {
            throw new ProtectedResourceException($certificate->domain, 'renew');
        }

        $site = $certificate->site;

        $certificate->forceFill(['last_renewal_attempt_at' => now()])->save();

        $outcome = $this->runner->run('certbot.renew', ['domain' => $certificate->domain], $site, $dryRun, timeoutSeconds: 180);

        if ($outcome->wasDryRun) {
            return ['ok' => true, 'message' => 'Dry run: '.$outcome->preview()];
        }

        if ($outcome->ok()) {
            $certificate->forceFill([
                'last_renewed_at' => now(),
                'renewal_failures' => 0,
                'last_error' => null,
            ])->save();

            $this->import();

            return ['ok' => true, 'message' => "Renewed {$certificate->domain}."];
        }

        $certificate->forceFill([
            'renewal_failures' => $certificate->renewal_failures + 1,
            'last_error' => trim($outcome->result->stderr ?: $outcome->result->stdout),
            'status' => CertificateStatus::Failed,
        ])->save();

        return ['ok' => false, 'message' => "Renewal FAILED for {$certificate->domain}: ".$certificate->last_error];
    }

    /** @return array{ok:bool,message:string} */
    public function revoke(Certificate $certificate, ?bool $dryRun = null): array
    {
        if ($certificate->isProtectedResource()) {
            throw new ProtectedResourceException($certificate->domain, 'revoke');
        }

        $outcome = $this->runner->run('certbot.revoke', ['domain' => $certificate->domain], $certificate->site, $dryRun);

        if ($outcome->wasDryRun) {
            return ['ok' => true, 'message' => 'Dry run: '.$outcome->preview()];
        }

        if ($outcome->ok()) {
            $certificate->forceFill(['status' => CertificateStatus::Revoked])->save();

            return ['ok' => true, 'message' => "Revoked {$certificate->domain}."];
        }

        return ['ok' => false, 'message' => 'Revoke failed: '.trim($outcome->result->stderr)];
    }

    /** Certificates due for renewal, managed only. */
    public function dueForRenewal(): Collection
    {
        $days = (int) config('jetgrid.certificates.renew_days_before');
        $maxAttempts = (int) config('jetgrid.certificates.max_renewal_attempts');

        return Certificate::query()
            ->where('is_managed', true)
            ->whereNotNull('not_after')
            ->where('not_after', '<=', now()->addDays($days))
            ->where('renewal_failures', '<', $maxAttempts)
            ->with('site')
            ->get();
    }

    /** @return list<string> */
    private function domainsFor(Site $site): array
    {
        return array_values(array_unique(array_merge(
            [$site->domain],
            $site->meta['aliases'] ?? [],
        )));
    }

    private function statusFor(?Carbon $expires): CertificateStatus
    {
        if ($expires === null) {
            return CertificateStatus::Unknown;
        }

        if ($expires->isPast()) {
            return CertificateStatus::Expired;
        }

        return $expires->lte(now()->addDays((int) config('jetgrid.certificates.warn_days_before')))
            ? CertificateStatus::Expiring
            : CertificateStatus::Valid;
    }

    /**
     * Parse `certbot certificates`.
     *
     * @return list<array{name:string,domains:list<string>,expires:?Carbon,path:?string}>
     */
    private function parseCertbotList(string $output): array
    {
        $certificates = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^Certificate Name:\s*(\S+)/', $line, $m) === 1) {
                if ($current !== null) {
                    $certificates[] = $current;
                }

                $current = ['name' => $m[1], 'domains' => [], 'expires' => null, 'path' => null];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^Domains:\s*(.+)$/', $line, $m) === 1) {
                $current['domains'] = preg_split('/\s+/', trim($m[1])) ?: [];
            } elseif (preg_match('/^Expiry Date:\s*([\d-]+ [\d:]+[^\s(]*)/', $line, $m) === 1) {
                $current['expires'] = $this->parseDate($m[1]);
            } elseif (preg_match('/^Certificate Path:\s*(\S+)/', $line, $m) === 1) {
                $current['path'] = $m[1];
            }
        }

        if ($current !== null) {
            $certificates[] = $current;
        }

        return $certificates;
    }

    private function parseDate(string $raw): ?Carbon
    {
        try {
            return Carbon::parse($raw);
        } catch (RuntimeException|\Exception) {
            return null;
        }
    }
}
