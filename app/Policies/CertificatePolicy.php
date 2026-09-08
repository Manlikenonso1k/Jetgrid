<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Certificate;
use App\Models\User;

class CertificatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->atLeast(Role::Viewer);
    }

    /** Adopted certs are readable — that is how their expiry gets displayed. */
    public function view(User $user, Certificate $certificate): bool
    {
        return $user->atLeast(Role::Viewer);
    }

    public function create(User $user): bool
    {
        return $user->atLeast(Role::Developer);
    }

    public function update(User $user, Certificate $certificate): bool
    {
        return $certificate->is_managed && $user->atLeast(Role::Developer);
    }

    public function delete(User $user, Certificate $certificate): bool
    {
        return $certificate->is_managed && $user->atLeast(Role::Admin);
    }

    public function revoke(User $user, Certificate $certificate): bool
    {
        return $certificate->is_managed && $user->atLeast(Role::Admin);
    }
}
