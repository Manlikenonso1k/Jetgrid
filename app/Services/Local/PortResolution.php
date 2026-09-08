<?php

namespace App\Services\Local;

/**
 * A resolved port and, just as importantly, where the number came from.
 *
 * A framework default and a value read out of the project's own .env deserve
 * different amounts of trust, and the operator can only tell them apart if the
 * source travels with the number.
 */
final class PortResolution
{
    public const SOURCE_OVERRIDE = 'user override';

    public const SOURCE_ENV = '.env';

    public const SOURCE_SCRIPT = 'package.json dev script';

    public const SOURCE_COMPOSE = 'docker-compose ports';

    public const SOURCE_DEFAULT = 'framework default';

    public function __construct(
        public readonly int $port,
        public readonly string $source,
        public readonly ?string $detail = null,
    ) {}

    public function describe(): string
    {
        return $this->detail === null ? $this->source : $this->source.' ('.$this->detail.')';
    }
}
