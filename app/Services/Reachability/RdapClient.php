<?php

namespace App\Services\Reachability;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Registrar status over RDAP — the structured, HTTPS replacement for port-43
 * WHOIS. It returns EPP status codes and the registry expiry date as JSON, so
 * there is no free-text scraping and no new privileged command.
 *
 * A lookup that fails returns ok=false and is recorded as UNKNOWN by the
 * caller. Never as healthy: "we could not ask" and "the answer was fine" are
 * different facts, and conflating them is what let a suspended domain look
 * green.
 */
class RdapClient
{
    /**
     * @return array{ok:bool,source:?string,statuses:list<string>,nameservers:list<string>,expires_at:?Carbon,error:?string}
     */
    public function lookup(string $domain): array
    {
        $registrable = $this->registrableDomain($domain);
        $endpoint = rtrim((string) config('jetgrid.reachability.rdap_endpoint'), '/').'/'.$registrable;

        try {
            $response = Http::timeout((int) config('jetgrid.reachability.http_timeout', 10))
                ->withHeaders(['Accept' => 'application/rdap+json'])
                ->retry(2, 300, throw: false)
                ->get($endpoint);

            if ($response->status() === 404) {
                return $this->result(ok: true, source: 'rdap', error: null) + ['statuses' => ['notfound']];
            }

            if (! $response->successful()) {
                return $this->result(false, error: "RDAP HTTP {$response->status()}");
            }

            $json = $response->json();

            if (! is_array($json)) {
                return $this->result(false, error: 'Malformed RDAP response');
            }

            return [
                'ok' => true,
                'source' => 'rdap',
                'statuses' => $this->statuses($json),
                'nameservers' => $this->nameservers($json),
                'expires_at' => $this->expiry($json),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return $this->result(false, error: $e->getMessage());
        }
    }

    /**
     * @return array{ok:bool,source:?string,statuses:list<string>,nameservers:list<string>,expires_at:?Carbon,error:?string}
     */
    private function result(bool $ok, ?string $source = null, ?string $error = null): array
    {
        return [
            'ok' => $ok,
            'source' => $source,
            'statuses' => [],
            'nameservers' => [],
            'expires_at' => null,
            'error' => $error,
        ];
    }

    /**
     * RDAP spells them "client hold"; EPP spells them "clientHold". Normalising
     * both to "clienthold" means the configured pattern list matches either.
     *
     * @param  array<string,mixed>  $json
     * @return list<string>
     */
    private function statuses(array $json): array
    {
        $statuses = [];

        foreach ($json['status'] ?? [] as $status) {
            $statuses[] = strtolower(str_replace([' ', '-', '_'], '', (string) $status));
        }

        return array_values(array_unique($statuses));
    }

    /**
     * @param  array<string,mixed>  $json
     * @return list<string>
     */
    private function nameservers(array $json): array
    {
        $servers = [];

        foreach ($json['nameservers'] ?? [] as $ns) {
            if ($name = $ns['ldhName'] ?? null) {
                $servers[] = strtolower(rtrim((string) $name, '.'));
            }
        }

        sort($servers);

        return array_values(array_unique($servers));
    }

    /** @param array<string,mixed> $json */
    private function expiry(array $json): ?Carbon
    {
        foreach ($json['events'] ?? [] as $event) {
            if (($event['eventAction'] ?? null) !== 'expiration') {
                continue;
            }

            try {
                return Carbon::parse((string) $event['eventDate']);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * RDAP is queried per registrable domain, not per host: asking about
     * "www.example.com" 404s where "example.com" answers.
     */
    public function registrableDomain(string $domain): string
    {
        $parts = explode('.', strtolower(trim($domain, '.')));

        if (count($parts) <= 2) {
            return implode('.', $parts);
        }

        // Handles the common two-label public suffixes (co.uk, com.ng, com.au)
        // without shipping a full PSL. Anything longer is a subdomain.
        $tail = array_slice($parts, -3);

        if (in_array($tail[1], ['co', 'com', 'net', 'org', 'gov', 'ac', 'edu'], true)
            && strlen($tail[2]) === 2) {
            return implode('.', $tail);
        }

        return implode('.', array_slice($parts, -2));
    }
}
