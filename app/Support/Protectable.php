<?php

namespace App\Support;

/**
 * Implemented by anything discovery can adopt. The guard checks this interface
 * rather than a role, which is why protection is not a permission.
 */
interface Protectable
{
    public function isProtectedResource(): bool;

    /** Name shown in the refusal message. */
    public function protectionLabel(): string;
}
