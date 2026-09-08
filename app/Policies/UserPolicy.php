<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->atLeast(Role::Admin);
    }

    public function view(User $user, User $target): bool
    {
        return $user->atLeast(Role::Admin) || $user->is($target);
    }

    public function create(User $user): bool
    {
        return $user->atLeast(Role::Admin);
    }

    public function update(User $user, User $target): bool
    {
        return $user->atLeast(Role::Admin) || $user->is($target);
    }

    public function delete(User $user, User $target): bool
    {
        // Nobody deletes themselves out of the last god_mode account.
        return $user->isGodMode() && ! $user->is($target);
    }

    /** Feature 8: only god_mode may change anyone else's role. */
    public function promote(User $user, User $target): bool
    {
        return $user->isGodMode();
    }

    /** Feature 8: only god_mode may flip the kill switch. */
    public function toggleReadOnly(User $user): bool
    {
        return $user->isGodMode();
    }
}
