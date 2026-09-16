<?php

namespace App\Services\Reachability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * This server's own public address, used to answer "does the domain still point
 * here?". Cached, because it changes roughly never and every site checked in a
 * run would otherwise re-fetch it.
 */
class PublicIpResolver
{
    public function get(): ?string
    {
        if ($configured = config('jetgrid.reachability.public_ip')) {
            return (string) $configured;
        }

        return Cache::remember(
            'jetgrid:public-ip',
            (int) config('jetgrid.reachability.public_ip_cache_ttl', 3600),
            fn () => $this->discover(),
        );
    }

    private function discover(): ?string
    {
        foreach ((array) config('jetgrid.reachability.public_ip_services') as $url) {
            try {
                $body = trim(Http::timeout(5)->get($url)->body());

                if (filter_var($body, FILTER_VALIDATE_IP) !== false) {
                    return $body;
                }
            } catch (Throwable) {
                // Try the next service; an unknown IP is handled by the caller.
            }
        }

        return null;
    }
}
