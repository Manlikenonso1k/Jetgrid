<?php

namespace App\Services\Alerts;

use App\Models\Site;
use App\Services\Reachability\CheckOutcome;

final class AlertDecision
{
    public function __construct(
        public readonly Site $site,
        public readonly CheckOutcome $outcome,
        public readonly bool $isRecovery,
        public readonly ?string $failingFor = null,
    ) {}

    public function severity(): string
    {
        return $this->isRecovery ? 'recovered' : $this->outcome->severity;
    }

    public function icon(): string
    {
        return match ($this->severity()) {
            'recovered' => "\u{2705}",
            'critical' => "\u{1F534}",
            'warning' => "\u{1F7E1}",
            default => "\u{2139}",
        };
    }
}
