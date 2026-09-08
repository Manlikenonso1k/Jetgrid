<?php

namespace App\Enums;

/**
 * How JetGrid relates to a site.
 *
 * Adopted sites were found by read-only discovery on a server that was already
 * running them. JetGrid renders monitoring for them and nothing else — see
 * ProtectedResourceGuard, which enforces this below the permission layer.
 */
enum ManagementMode: string
{
    case Managed = 'managed';
    case AdoptedProtected = 'adopted_protected';

    public function isProtected(): bool
    {
        return $this === self::AdoptedProtected;
    }

    public function label(): string
    {
        return match ($this) {
            self::Managed => 'Managed by JetGrid',
            self::AdoptedProtected => 'Adopted — Protected',
        };
    }
}
