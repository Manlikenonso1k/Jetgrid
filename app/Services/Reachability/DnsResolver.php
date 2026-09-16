<?php

namespace App\Services\Reachability;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolves a domain against multiple public resolvers over DNS-over-HTTPS.
 *
 * DoH rather than dig: it queries the named resolvers genuinely independently,
 * behaves identically on Windows and Linux, and adds no privileged command to
 * the whitelist. PHP's own dns_get_record() cannot be pointed at a specific
 * resolver, which is the whole point of checking more than one.
 */
class DnsResolver
{
    private const TYPES = ['A' => 1, 'AAAA' => 28, 'NS' => 2];

    /**
     * Query every configured resolver for one record type.
     *
     * @return list<DnsAnswer>
     */
    public function resolveAll(string $domain, string $type = 'A'): array
    {
        $answers = [];

        foreach ((array) config('jetgrid.reachability.resolvers') as $resolver) {
            $answers[] = $this->query($resolver['name'], $resolver['url'], $domain, $type);
        }

        return $answers;
    }

    public function query(string $name, string $url, string $domain, string $type): DnsAnswer
    {
        try {
            $response = Http::timeout((int) config('jetgrid.reachability.http_timeout', 10))
                ->withHeaders(['Accept' => 'application/dns-json'])
                ->retry(2, 200, throw: false)
                ->get($url, ['name' => $domain, 'type' => $type]);

            if (! $response->successful()) {
                return DnsAnswer::failed($name, "HTTP {$response->status()} from resolver");
            }

            $json = $response->json();

            if (! is_array($json) || ! array_key_exists('Status', $json)) {
                return DnsAnswer::failed($name, 'Malformed DoH response');
            }

            return new DnsAnswer(
                resolver: $name,
                rcode: (int) $json['Status'],
                records: $this->extract($json, $type),
            );
        } catch (Throwable $e) {
            // A resolver we cannot reach tells us nothing about the domain.
            return DnsAnswer::failed($name, $e->getMessage());
        }
    }

    /**
     * @param  array<string,mixed>  $json
     * @return list<string>
     */
    private function extract(array $json, string $type): array
    {
        $wanted = self::TYPES[$type] ?? null;
        $records = [];

        foreach ($json['Answer'] ?? [] as $answer) {
            if ($wanted !== null && (int) ($answer['type'] ?? 0) !== $wanted) {
                continue;
            }

            if ($data = $answer['data'] ?? null) {
                $records[] = rtrim((string) $data, '.');
            }
        }

        sort($records);

        return array_values(array_unique($records));
    }

    /** RFC1918, loopback and link-local — a public domain must never resolve here. */
    public function isNonPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
