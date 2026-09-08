<?php

namespace App\Services\Capacity;

use Aws\Pricing\PricingClient;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Feature 7's data source.
 *
 * Specs and prices are treated differently on purpose. vCPU, RAM, baseline CPU
 * and credits/hour are architectural facts that change rarely. Prices change
 * without notice, so the shipped values are flagged `unverified_seed` and the
 * UI refuses to present them as current until refresh() has replaced them from
 * the AWS Price List API. That is what "say clearly in the UI which approach is
 * live" means here.
 */
class InstanceCatalogue
{
    public static function load(): array
    {
        $override = storage_path('app/jetgrid/ec2-pricing.json');
        $path = File::exists($override) ? $override : config('jetgrid.pricing.file');

        if (! File::exists($path)) {
            return ['instances' => [], 'price_status' => 'missing'];
        }

        return json_decode(File::get($path), true) ?: ['instances' => [], 'price_status' => 'unreadable'];
    }

    public static function pricesAreVerified(): bool
    {
        $data = self::load();

        return ($data['price_status'] ?? null) === 'live'
            && ! empty($data['prices_verified_at']);
    }

    /** One line the UI can print verbatim about where these numbers came from. */
    public static function provenance(): string
    {
        $data = self::load();

        return match ($data['price_status'] ?? 'missing') {
            'live' => 'Prices: live from the AWS Price List API, refreshed '.($data['prices_verified_at'] ?? 'unknown').'.',
            'unverified_seed' => 'Prices: UNVERIFIED seed values shipped with JetGrid on '.($data['seeded_at'] ?? '?').'. They were not read from AWS and may be wrong. Use Refresh before relying on them.',
            'missing' => 'Pricing catalogue file is missing.',
            default => 'Pricing catalogue could not be read.',
        };
    }

    /**
     * Replace the seeded prices with live ones.
     *
     * Writes to storage rather than back over the shipped file, so a failed or
     * partial refresh never corrupts the seed you can fall back to.
     *
     * @return array{ok:bool,message:string,updated:int}
     */
    public static function refresh(): array
    {
        if (! config('jetgrid.pricing.api_enabled')) {
            return [
                'ok' => false,
                'updated' => 0,
                'message' => 'Set JETGRID_PRICING_API=true and give the instance pricing:GetProducts (us-east-1 only) to enable live refresh.',
            ];
        }

        $data = self::load();
        $region = (string) config('jetgrid.pricing.region');
        $updated = 0;

        try {
            // The Price List API is only served from us-east-1 and ap-south-1.
            $client = new PricingClient(['region' => 'us-east-1', 'version' => '2017-10-15']);

            foreach (array_keys($data['instances'] ?? []) as $type) {
                $price = self::fetchOnDemandHourly($client, $type, $region);

                if ($price !== null) {
                    $data['instances'][$type]['on_demand_hourly'] = $price;
                    $updated++;
                }
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'updated' => 0, 'message' => 'AWS Price List API call failed: '.$e->getMessage()];
        }

        if ($updated === 0) {
            return ['ok' => false, 'updated' => 0, 'message' => 'No prices returned; the seeded values were left untouched.'];
        }

        $data['price_status'] = 'live';
        $data['price_source'] = 'aws-price-list-api';
        $data['prices_verified_at'] = now()->toDateTimeString();
        $data['region'] = $region;

        File::ensureDirectoryExists(storage_path('app/jetgrid'));
        File::put(
            storage_path('app/jetgrid/ec2-pricing.json'),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        return ['ok' => true, 'updated' => $updated, 'message' => "Refreshed {$updated} instance prices for {$region}."];
    }

    private static function fetchOnDemandHourly(PricingClient $client, string $type, string $region): ?float
    {
        $result = $client->getProducts([
            'ServiceCode' => 'AmazonEC2',
            'Filters' => [
                ['Type' => 'TERM_MATCH', 'Field' => 'instanceType', 'Value' => $type],
                ['Type' => 'TERM_MATCH', 'Field' => 'regionCode', 'Value' => $region],
                ['Type' => 'TERM_MATCH', 'Field' => 'operatingSystem', 'Value' => 'Linux'],
                ['Type' => 'TERM_MATCH', 'Field' => 'tenancy', 'Value' => 'Shared'],
                ['Type' => 'TERM_MATCH', 'Field' => 'preInstalledSw', 'Value' => 'NA'],
                ['Type' => 'TERM_MATCH', 'Field' => 'capacitystatus', 'Value' => 'Used'],
            ],
            'MaxResults' => 1,
        ]);

        $raw = $result['PriceList'][0] ?? null;

        if ($raw === null) {
            return null;
        }

        $product = json_decode(is_string($raw) ? $raw : json_encode($raw), true);

        foreach ($product['terms']['OnDemand'] ?? [] as $term) {
            foreach ($term['priceDimensions'] ?? [] as $dimension) {
                $usd = $dimension['pricePerUnit']['USD'] ?? null;

                if ($usd !== null && (float) $usd > 0) {
                    return (float) $usd;
                }
            }
        }

        return null;
    }
}
