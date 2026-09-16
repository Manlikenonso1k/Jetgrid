<?php

namespace App\Services\Reachability;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads the certificate the world is served, by connecting to the externally
 * resolved address with SNI set to the domain.
 *
 * Deliberately not localhost: a box can serve a perfectly valid certificate to
 * itself while DNS points the domain somewhere else entirely.
 */
class TlsInspector
{
    /**
     * @return array{ok:bool,not_after:?Carbon,issuer:?string,subject:?string,error:?string}
     */
    public function inspect(string $domain, ?string $ip = null, int $port = 443): array
    {
        $target = $ip ?? $domain;
        $timeout = (int) config('jetgrid.reachability.http_timeout', 10);

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'SNI_enabled' => true,
                'peer_name' => $domain,
                // We are reading the expiry date, not asserting trust. A cert
                // that fails verification still has a date worth reporting, and
                // verification failure is surfaced by the HTTP check instead.
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $client = @stream_socket_client(
            "ssl://{$target}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            return $this->failure($errstr !== '' ? $errstr : "connect failed ({$errno})");
        }

        try {
            $params = stream_context_get_params($client);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;

            if ($cert === null) {
                return $this->failure('No peer certificate presented');
            }

            $parsed = openssl_x509_parse($cert);

            if ($parsed === false || ! isset($parsed['validTo_time_t'])) {
                return $this->failure('Certificate could not be parsed');
            }

            return [
                'ok' => true,
                'not_after' => Carbon::createFromTimestampUTC((int) $parsed['validTo_time_t']),
                'issuer' => $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? null,
                'subject' => $parsed['subject']['CN'] ?? null,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        } finally {
            fclose($client);
        }
    }

    /** @return array{ok:bool,not_after:?Carbon,issuer:?string,subject:?string,error:?string} */
    private function failure(string $error): array
    {
        return ['ok' => false, 'not_after' => null, 'issuer' => null, 'subject' => null, 'error' => $error];
    }
}
