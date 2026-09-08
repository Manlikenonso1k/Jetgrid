<?php

namespace App\Services\Certificates;

use App\Models\CertificateIssuance;
use Illuminate\Support\Carbon;

/**
 * Feature 3: "track and block, don't just fail".
 *
 * Let's Encrypt permits 5 certificates with an identical set of names per week.
 * Hitting that limit locks the domain out for seven days, so JetGrid counts its
 * own attempts and refuses the sixth itself.
 */
class RateLimitTracker
{
    /** @param list<string> $domains */
    public function hash(array $domains): string
    {
        $normalised = array_map('strtolower', $domains);
        sort($normalised);

        return hash('sha256', implode(',', $normalised));
    }

    /** @param list<string> $domains */
    public function recentCount(array $domains): int
    {
        return CertificateIssuance::query()
            ->where('domain_set_hash', $this->hash($domains))
            ->where('attempted_at', '>=', now()->subHours((int) config('jetgrid.certificates.le_duplicate_window_h')))
            ->count();
    }

    /** @param list<string> $domains */
    public function wouldExceed(array $domains): bool
    {
        return $this->recentCount($domains) >= (int) config('jetgrid.certificates.le_duplicate_limit');
    }

    /** @param list<string> $domains */
    public function remaining(array $domains): int
    {
        return max(0, (int) config('jetgrid.certificates.le_duplicate_limit') - $this->recentCount($domains));
    }

    /** When the oldest attempt in the window ages out, unblocking the domain. */
    public function nextSlotAt(array $domains): ?Carbon
    {
        $oldest = CertificateIssuance::query()
            ->where('domain_set_hash', $this->hash($domains))
            ->where('attempted_at', '>=', now()->subHours((int) config('jetgrid.certificates.le_duplicate_window_h')))
            ->orderBy('attempted_at')
            ->first();

        return $oldest?->attempted_at?->addHours((int) config('jetgrid.certificates.le_duplicate_window_h'));
    }

    /**
     * Record an attempt. Called BEFORE issuance, because a request that was
     * sent and then failed still counted against the limit at the CA.
     *
     * @param  list<string>  $domains
     */
    public function record(array $domains, bool $succeeded = false): void
    {
        CertificateIssuance::create([
            'domain_set_hash' => $this->hash($domains),
            'domain' => $domains[0] ?? '',
            'succeeded' => $succeeded,
            'attempted_at' => now(),
        ]);
    }
}
