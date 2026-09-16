<?php

namespace App\Services\Reachability;

use App\Enums\CheckStatus;
use App\Enums\CheckType;

/**
 * The result of one check against one domain.
 *
 * `observed` and `expected` are carried all the way into the alert message on
 * purpose: "DNS check failed" is not actionable, "resolves to 203.0.113.9,
 * expected 198.51.100.4" is.
 */
final class CheckOutcome
{
    /**
     * @param  array<string,mixed>  $observed
     * @param  array<string,mixed>  $expected
     */
    public function __construct(
        public readonly CheckType $type,
        public readonly CheckStatus $status,
        public readonly string $summary,
        public readonly string $severity = 'info',
        public readonly array $observed = [],
        public readonly array $expected = [],
    ) {}

    /** @param array<string,mixed> $observed */
    public static function ok(CheckType $type, string $summary, array $observed = []): self
    {
        return new self($type, CheckStatus::Ok, $summary, 'info', $observed);
    }

    /**
     * @param  array<string,mixed>  $observed
     * @param  array<string,mixed>  $expected
     */
    public static function failing(
        CheckType $type,
        string $summary,
        string $severity = 'warning',
        array $observed = [],
        array $expected = [],
    ): self {
        return new self($type, CheckStatus::Failing, $summary, $severity, $observed, $expected);
    }

    /** @param array<string,mixed> $observed */
    public static function unknown(CheckType $type, string $summary, array $observed = []): self
    {
        return new self($type, CheckStatus::Unknown, $summary, 'warning', $observed);
    }

    public function isFailing(): bool
    {
        return $this->status === CheckStatus::Failing;
    }
}
