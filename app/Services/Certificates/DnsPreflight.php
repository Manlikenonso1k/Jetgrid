<?php

namespace App\Services\Certificates;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Feature 11 — tell the operator what is wrong with DNS BEFORE certbot fails.
 *
 * A failed HTTP-01 attempt is not free: it burns one of the five duplicate
 * certificates Let's Encrypt allows per week. Every check here is read-only and
 * runs regardless of JETGRID_READONLY.
 */
class DnsPreflight
{
    /** @return list<PreflightCheck> */
    public function run(string $domain): array
    {
        $checks = [];

        $publicIp = $this->publicIp();
        $records = $this->resolve($domain, DNS_A);

        $checks[] = $this->aRecordCheck($domain, $records, $publicIp);
        $checks[] = $this->aaaaCheck($domain);
        $checks[] = $this->cnameCheck($domain);
        $checks[] = $this->portCheck($domain);
        $checks[] = $this->caaCheck($domain);

        return $checks;
    }

    public function passes(string $domain): bool
    {
        foreach ($this->run($domain) as $check) {
            if ($check->status === PreflightCheck::FAIL) {
                return false;
            }
        }

        return true;
    }

    private function aRecordCheck(string $domain, array $records, ?string $publicIp): PreflightCheck
    {
        $ips = array_column($records, 'ip');

        if ($ips === []) {
            return new PreflightCheck('A record', PreflightCheck::FAIL,
                "No A record for {$domain}. Point it at this server before requesting a certificate.");
        }

        if ($publicIp === null) {
            return new PreflightCheck('A record', PreflightCheck::WARN,
                'Resolves to '.implode(', ', $ips).', but JetGrid could not determine this server public IP to compare.');
        }

        if (! in_array($publicIp, $ips, true)) {
            return new PreflightCheck('A record', PreflightCheck::FAIL,
                "{$domain} resolves to ".implode(', ', $ips)." but this server is {$publicIp}. HTTP-01 will fail.");
        }

        return new PreflightCheck('A record', PreflightCheck::PASS, "{$domain} → {$publicIp} (this server).");
    }

    private function aaaaCheck(string $domain): PreflightCheck
    {
        $records = $this->resolve($domain, DNS_AAAA);

        if ($records === []) {
            return new PreflightCheck('AAAA record', PreflightCheck::PASS, 'No AAAA record — IPv4 only, which is fine.');
        }

        // A stale AAAA is a classic silent HTTP-01 failure: Let's Encrypt
        // prefers IPv6 and never tries the working IPv4 address.
        return new PreflightCheck('AAAA record', PreflightCheck::WARN,
            'AAAA present ('.implode(', ', array_column($records, 'ipv6')).'). Let\'s Encrypt will try IPv6 FIRST and will not fall back. Remove it unless this server really serves on that address.');
    }

    private function cnameCheck(string $domain): PreflightCheck
    {
        $records = $this->resolve($domain, DNS_CNAME);

        if ($records === []) {
            return new PreflightCheck('CNAME', PreflightCheck::PASS, 'No conflicting CNAME.');
        }

        return new PreflightCheck('CNAME', PreflightCheck::WARN,
            'CNAME → '.implode(', ', array_column($records, 'target')).'. Certificates follow the chain; make sure the target is this server.');
    }

    private function caaCheck(string $domain): PreflightCheck
    {
        $records = $this->resolve($domain, DNS_CAA);

        if ($records === []) {
            return new PreflightCheck('CAA', PreflightCheck::PASS, 'No CAA record — any CA may issue.');
        }

        $issuers = array_column($records, 'value');

        foreach ($issuers as $issuer) {
            if (str_contains((string) $issuer, 'letsencrypt.org')) {
                return new PreflightCheck('CAA', PreflightCheck::PASS, 'CAA permits letsencrypt.org.');
            }
        }

        return new PreflightCheck('CAA', PreflightCheck::FAIL,
            'CAA records ('.implode(', ', $issuers).') do not list letsencrypt.org. Issuance will be refused.');
    }

    private function portCheck(string $domain): PreflightCheck
    {
        $connection = @fsockopen($domain, 80, $errno, $errstr, 5);

        if ($connection === false) {
            return new PreflightCheck('Port 80', PreflightCheck::FAIL,
                "Could not reach {$domain}:80 ({$errstr}). HTTP-01 needs port 80 open; use DNS-01 instead if it must stay closed.");
        }

        fclose($connection);

        return new PreflightCheck('Port 80', PreflightCheck::PASS, 'Port 80 reachable.');
    }

    /** @return list<array<string,mixed>> */
    private function resolve(string $domain, int $type): array
    {
        try {
            return @dns_get_record($domain, $type) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function publicIp(): ?string
    {
        try {
            // EC2 IMDSv2. Short timeout: off-EC2 this simply does not answer.
            $token = Http::timeout(2)
                ->withHeaders(['X-aws-ec2-metadata-token-ttl-seconds' => '60'])
                ->put('http://169.254.169.254/latest/api/token');

            if ($token->successful()) {
                $ip = Http::timeout(2)
                    ->withHeaders(['X-aws-ec2-metadata-token' => $token->body()])
                    ->get('http://169.254.169.254/latest/meta-data/public-ipv4');

                if ($ip->successful()) {
                    return trim($ip->body());
                }
            }
        } catch (Throwable) {
            // Not on EC2, or metadata disabled.
        }

        return config('jetgrid.public_ip');
    }
}
