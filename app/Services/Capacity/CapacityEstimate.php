<?php

namespace App\Services\Capacity;

/**
 * Feature 2 output. The number is never presented on its own — the limiting
 * factor is named, and the arithmetic behind each constraint is exposed so the
 * estimate can be argued with.
 */
final class CapacityEstimate
{
    /** @param list<CapacityConstraint> $constraints */
    public function __construct(
        public readonly array $constraints,
        public readonly int $slots,
        public readonly ?string $limitingFactor,
        public readonly ?string $limitingLabel,
    ) {}

    /** @param list<CapacityConstraint> $constraints */
    public static function from(array $constraints): self
    {
        $known = array_filter($constraints, static fn (CapacityConstraint $c) => $c->slots !== null);

        if ($known === []) {
            return new self($constraints, 0, null, null);
        }

        $tightest = null;

        foreach ($known as $constraint) {
            if ($tightest === null || $constraint->slots < $tightest->slots) {
                $tightest = $constraint;
            }
        }

        return new self(
            constraints: $constraints,
            slots: max(0, $tightest->slots),
            limitingFactor: $tightest->key,
            limitingLabel: $tightest->boundLabel,
        );
    }

    public function headline(): string
    {
        if ($this->limitingLabel === null) {
            return 'Capacity unknown — JetGrid could not read enough of the server to estimate.';
        }

        return "≈ {$this->slots} more small ".($this->slots === 1 ? 'site' : 'sites')." ({$this->limitingLabel})";
    }

    /** Constraints JetGrid could not evaluate, for the "why" footnote. */
    public function unknownConstraints(): array
    {
        return array_values(array_filter($this->constraints, static fn (CapacityConstraint $c) => $c->slots === null));
    }
}
