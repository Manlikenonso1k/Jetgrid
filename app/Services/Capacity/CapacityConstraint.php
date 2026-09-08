<?php

namespace App\Services\Capacity;

final class CapacityConstraint
{
    /**
     * @param string      $key        ram | disk | cpu_credits
     * @param string      $boundLabel what the UI prints when this is the binding constraint
     * @param int|null    $slots      null when the input could not be read
     * @param list<array{label:string,value:string}> $workings the arithmetic, for display
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $boundLabel,
        public readonly ?int $slots,
        public readonly array $workings = [],
        public readonly ?string $unavailableReason = null,
    ) {
    }

    public static function unavailable(string $key, string $name, string $boundLabel, string $reason): self
    {
        return new self($key, $name, $boundLabel, null, [], $reason);
    }
}
