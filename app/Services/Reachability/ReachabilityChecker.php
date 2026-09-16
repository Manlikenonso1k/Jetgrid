<?php

namespace App\Services\Reachability;

use App\Enums\CheckType;
use App\Models\DomainBaseline;
use App\Models\HealthCheck;
use App\Models\Site;
use App\Models\WhoisSnapshot;

/**
 * Runs the external checks for one site and reports what it saw.
 *
 * Nothing here writes to the Site row. Results land in domain_check_results and
 * the supporting tables, all keyed by site_id, so an Adopted — Protected site
 * is monitored exactly like a managed one without the model's protection gate
 * ever being involved.
 */
class ReachabilityChecker
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly PublicIpResolver $publicIp,
        private readonly RdapClient $rdap,
        private readonly TlsInspector $tls,
    ) {}

    /**
     * The five-minute pass: DNS, nameserver integrity, and the internal/external
     * comparison that depends on both.
     *
     * @return list<CheckOutcome>
     */
    public function runFrequentChecks(Site $site): array
    {
        $dns = $this->checkDns($site);

        return [
            $dns,
            $this->checkNameservers($site),
            $this->checkExternalReachability($site, $dns),
        ];
    }

    public function checkDns(Site $site): CheckOutcome
    {
        $answers = $this->dns->resolveAll($site->domain, 'A');
        $expected = $this->publicIp->get();

        $reachable = array_values(array_filter($answers, fn (DnsAnswer $a) => $a->error === null));

        // Every resolver unreachable means our network is broken, not the
        // domain. Unknown, so it is never mistaken for healthy.
        if ($reachable === []) {
            return CheckOutcome::unknown(
                CheckType::Dns,
                'No configured resolver could be reached — DNS status unknown.',
                ['resolvers' => array_map(fn (DnsAnswer $a) => [$a->resolver => $a->error], $answers)],
            );
        }

        foreach ($reachable as $answer) {
            if (! $answer->answered()) {
                return CheckOutcome::failing(
                    CheckType::Dns,
                    "{$answer->resolver} returned {$answer->rcodeName()} for {$site->domain}.",
                    'critical',
                    ['resolver' => $answer->resolver, 'rcode' => $answer->rcodeName()],
                    ['rcode' => 'NOERROR'],
                );
            }

            if ($answer->isEmpty()) {
                return CheckOutcome::failing(
                    CheckType::Dns,
                    "{$answer->resolver} returned an empty answer for {$site->domain} — no A record.",
                    'critical',
                    ['resolver' => $answer->resolver, 'records' => []],
                    ['records' => $expected ? [$expected] : ['at least one A record']],
                );
            }
        }

        $observed = [];

        foreach ($reachable as $answer) {
            $observed = array_merge($observed, $answer->records);
        }

        $observed = array_values(array_unique($observed));

        foreach ($observed as $ip) {
            if ($this->dns->isNonPublic($ip)) {
                return CheckOutcome::failing(
                    CheckType::Dns,
                    "{$site->domain} resolves to the non-public address {$ip} — possible sinkhole or misconfigured record.",
                    'critical',
                    ['records' => $observed],
                    ['records' => $expected ? [$expected] : ['a public address']],
                );
            }
        }

        if ($expected !== null && ! in_array($expected, $observed, true)) {
            return CheckOutcome::failing(
                CheckType::Dns,
                "{$site->domain} resolves to ".implode(', ', $observed)." — this server is {$expected}.",
                'critical',
                ['records' => $observed],
                ['records' => [$expected]],
            );
        }

        return CheckOutcome::ok(
            CheckType::Dns,
            "{$site->domain} resolves to ".implode(', ', $observed).'.',
            ['records' => $observed, 'resolvers' => array_map(fn (DnsAnswer $a) => $a->resolver, $reachable)],
        );
    }

    public function checkNameservers(Site $site): CheckOutcome
    {
        $answers = $this->dns->resolveAll($this->rdap->registrableDomain($site->domain), 'NS');
        $usable = array_values(array_filter(
            $answers,
            fn (DnsAnswer $a) => $a->error === null && $a->answered() && ! $a->isEmpty(),
        ));

        if ($usable === []) {
            return CheckOutcome::unknown(
                CheckType::Nameserver,
                "Nameservers for {$site->domain} could not be read — status unknown.",
                ['resolvers' => array_map(fn (DnsAnswer $a) => [$a->resolver => $a->error ?? $a->rcodeName()], $answers)],
            );
        }

        $observed = [];

        foreach ($usable as $answer) {
            foreach ($answer->records as $record) {
                $observed[] = strtolower($record);
            }
        }

        sort($observed);
        $observed = array_values(array_unique($observed));

        // The signal that was missed: the registrar replaced the nameservers
        // with a hold host while the server itself stayed perfectly healthy.
        if ($hit = $this->matchesSuspension($observed)) {
            return CheckOutcome::failing(
                CheckType::Nameserver,
                "{$site->domain} nameservers indicate a REGISTRAR HOLD ({$hit}). The domain is suspended — the server being up is irrelevant.",
                'critical',
                ['nameservers' => $observed, 'matched' => $hit],
                ['nameservers' => $this->baselineFor($site)?->nameservers ?? ['the registered nameservers']],
            );
        }

        $baseline = $this->baselineFor($site);

        if ($baseline === null) {
            DomainBaseline::create([
                'site_id' => $site->id,
                'nameservers' => $observed,
                'recorded_at' => now(),
            ]);

            return CheckOutcome::ok(
                CheckType::Nameserver,
                'Nameserver baseline recorded: '.implode(', ', $observed).'.',
                ['nameservers' => $observed, 'baseline_recorded' => true],
            );
        }

        if ($baseline->nameservers !== $observed) {
            return CheckOutcome::failing(
                CheckType::Nameserver,
                "{$site->domain} nameservers CHANGED to ".implode(', ', $observed).'.',
                'critical',
                ['nameservers' => $observed],
                ['nameservers' => $baseline->nameservers],
            );
        }

        return CheckOutcome::ok(
            CheckType::Nameserver,
            'Nameservers unchanged: '.implode(', ', $observed).'.',
            ['nameservers' => $observed],
        );
    }

    /**
     * The check this whole feature exists for.
     *
     * The server answering its own request proves only that the server is up. If
     * the internal check passes while DNS is failing, the site is serving
     * nobody — and that is strictly worse than a plain outage, because every
     * localhost-based check reports green throughout.
     */
    public function checkExternalReachability(Site $site, CheckOutcome $dns): CheckOutcome
    {
        $internalOk = $this->lastInternalCheckPassed($site);

        if ($internalOk && $dns->isFailing()) {
            return CheckOutcome::failing(
                CheckType::ExternalHttp,
                "{$site->domain} is reachable LOCALLY but unreachable EXTERNALLY. The server returns HTTP 200 to itself while DNS fails: {$dns->summary}",
                'critical',
                ['internal_http' => 'passing', 'dns' => $dns->summary],
                ['internal_http' => 'passing', 'dns' => 'passing'],
            );
        }

        if ($dns->isFailing()) {
            return CheckOutcome::failing(
                CheckType::ExternalHttp,
                "{$site->domain} is unreachable externally and the internal check is also failing.",
                'critical',
                ['internal_http' => 'failing', 'dns' => $dns->summary],
                ['internal_http' => 'passing', 'dns' => 'passing'],
            );
        }

        if (! $internalOk) {
            return CheckOutcome::failing(
                CheckType::ExternalHttp,
                "{$site->domain} resolves correctly but the internal HTTP check is failing — the server is not serving it.",
                'warning',
                ['internal_http' => 'failing', 'dns' => 'passing'],
                ['internal_http' => 'passing'],
            );
        }

        return CheckOutcome::ok(
            CheckType::ExternalHttp,
            "{$site->domain} resolves publicly and answers internally.",
            ['internal_http' => 'passing', 'dns' => 'passing'],
        );
    }

    public function checkTls(Site $site): CheckOutcome
    {
        $answers = $this->dns->resolveAll($site->domain, 'A');
        $ip = null;

        foreach ($answers as $answer) {
            if ($answer->error === null && $answer->answered() && ! $answer->isEmpty()) {
                $ip = $answer->records[0];
                break;
            }
        }

        if ($ip === null) {
            return CheckOutcome::unknown(
                CheckType::Tls,
                "Cannot read the certificate for {$site->domain}: the domain does not resolve.",
            );
        }

        $cert = $this->tls->inspect($site->domain, $ip);

        if (! $cert['ok']) {
            return CheckOutcome::unknown(
                CheckType::Tls,
                "TLS handshake with {$site->domain} ({$ip}) failed: {$cert['error']}",
                ['ip' => $ip, 'error' => $cert['error']],
            );
        }

        $days = (int) floor(now()->diffInDays($cert['not_after'], false));
        $thresholds = (array) config('jetgrid.reachability.tls_warn_days', [21, 14, 7, 3]);

        if ($days < 0) {
            return CheckOutcome::failing(
                CheckType::Tls,
                "{$site->domain} certificate EXPIRED ".abs($days).' day(s) ago.',
                'critical',
                ['not_after' => $cert['not_after']->toDateString(), 'days' => $days],
            );
        }

        if ($days <= max($thresholds)) {
            return CheckOutcome::failing(
                CheckType::Tls,
                "{$site->domain} certificate expires in {$days} day(s) (".$cert['not_after']->toDateString().').',
                $days <= min($thresholds) ? 'critical' : 'warning',
                ['not_after' => $cert['not_after']->toDateString(), 'days' => $days, 'issuer' => $cert['issuer']],
            );
        }

        return CheckOutcome::ok(
            CheckType::Tls,
            "{$site->domain} certificate valid for {$days} more day(s).",
            ['not_after' => $cert['not_after']->toDateString(), 'days' => $days, 'issuer' => $cert['issuer']],
        );
    }

    /**
     * WHOIS/RDAP, rate limited to once per domain per interval. A cached answer
     * is re-evaluated rather than re-fetched, so the alert logic still runs on
     * every tick without hammering the registry.
     */
    public function checkWhois(Site $site, bool $force = false): CheckOutcome
    {
        $interval = (int) config('jetgrid.reachability.whois_interval_hours', 24);
        $snapshot = WhoisSnapshot::firstWhere('site_id', $site->id);

        if ($force || $snapshot === null || ! $snapshot->isFresh($interval)) {
            $snapshot = $this->refreshWhois($site, $snapshot);
        }

        if (! $snapshot->lookup_ok) {
            return CheckOutcome::unknown(
                CheckType::Whois,
                "Registrar status for {$site->domain} is unknown: {$snapshot->error}",
                ['error' => $snapshot->error, 'attempts' => $snapshot->consecutive_failures],
            );
        }

        $critical = (array) config('jetgrid.reachability.critical_epp_statuses');
        $held = array_values(array_intersect($snapshot->statuses ?? [], $critical));

        if ($held !== []) {
            return CheckOutcome::failing(
                CheckType::Whois,
                "{$site->domain} has registrar status ".implode(', ', $held).' — the domain is not fully live.',
                'critical',
                ['statuses' => $snapshot->statuses],
                ['statuses' => 'no hold or deletion status'],
            );
        }

        if ($snapshot->expires_at !== null) {
            $days = (int) floor(now()->diffInDays($snapshot->expires_at, false));
            $warnAt = (array) config('jetgrid.reachability.registry_expiry_warn_days', [30, 14, 7, 1]);

            if ($days < 0) {
                return CheckOutcome::failing(
                    CheckType::Whois,
                    "{$site->domain} registry registration EXPIRED ".abs($days).' day(s) ago.',
                    'critical',
                    ['expires_at' => $snapshot->expires_at->toDateString(), 'days' => $days],
                );
            }

            if ($days <= max($warnAt)) {
                return CheckOutcome::failing(
                    CheckType::Whois,
                    "{$site->domain} registration expires in {$days} day(s) (".$snapshot->expires_at->toDateString().').',
                    $days <= 7 ? 'critical' : 'warning',
                    ['expires_at' => $snapshot->expires_at->toDateString(), 'days' => $days],
                );
            }
        }

        return CheckOutcome::ok(
            CheckType::Whois,
            "{$site->domain} registrar status is clean".
                ($snapshot->expires_at ? ', expires '.$snapshot->expires_at->toDateString() : '').'.',
            ['statuses' => $snapshot->statuses, 'expires_at' => $snapshot->expires_at?->toDateString()],
        );
    }

    private function refreshWhois(Site $site, ?WhoisSnapshot $existing): WhoisSnapshot
    {
        $result = $this->rdap->lookup($site->domain);

        return WhoisSnapshot::updateOrCreate(
            ['site_id' => $site->id],
            [
                'lookup_ok' => $result['ok'],
                'source' => $result['source'],
                'expires_at' => $result['expires_at'],
                'statuses' => $result['statuses'],
                'nameservers' => $result['nameservers'],
                'error' => $result['error'],
                'consecutive_failures' => $result['ok'] ? 0 : (($existing->consecutive_failures ?? 0) + 1),
                'fetched_at' => now(),
            ],
        );
    }

    private function baselineFor(Site $site): ?DomainBaseline
    {
        return DomainBaseline::firstWhere('site_id', $site->id);
    }

    private function matchesSuspension(array $nameservers): ?string
    {
        foreach ($nameservers as $ns) {
            foreach ((array) config('jetgrid.reachability.suspension_patterns') as $pattern) {
                if (str_contains($ns, strtolower($pattern))) {
                    return $ns;
                }
            }
        }

        return null;
    }

    /** The existing on-server check, used only as the "internal" half of the comparison. */
    private function lastInternalCheckPassed(Site $site): bool
    {
        return (bool) HealthCheck::query()
            ->where('site_id', $site->id)
            ->latest('checked_at')
            ->value('ok');
    }
}
