<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/** Feature 8: the audit log is god_mode only, and nobody may alter it. */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isGodMode();
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $user->isGodMode();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $log): bool
    {
        return false;
    }
}
