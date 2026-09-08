<?php

namespace App\Support;

use App\Exceptions\ProtectedResourceException;

/**
 * Safety constraint #1, enforced architecturally.
 *
 * This guard takes no user and consults no policy. There is no argument you can
 * pass it that means "but I am god_mode". The only way to make a resource
 * writable is to stop it being protected, which is a deliberate, audited,
 * re-discovery-resistant act (see docs/UNPROTECTING.md).
 */
class ProtectedResourceGuard
{
    public function assertWritable(mixed $target, string $attempted = 'write'): void
    {
        if ($target instanceof Protectable && $target->isProtectedResource()) {
            throw new ProtectedResourceException($target->protectionLabel(), $attempted);
        }
    }

    public function isProtected(mixed $target): bool
    {
        return $target instanceof Protectable && $target->isProtectedResource();
    }
}
