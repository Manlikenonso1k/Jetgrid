<?php

namespace App\Services\Health;

final class HealthScore
{
    /** @param array<string,float> $components */
    public function __construct(
        public readonly int $score,
        public readonly array $components = [],
        public readonly ?string $note = null,
    ) {
    }

    public function band(): string
    {
        return match (true) {
            $this->score >= 90 => 'healthy',
            $this->score >= 70 => 'fair',
            $this->score >= 40 => 'poor',
            default => 'critical',
        };
    }
}
