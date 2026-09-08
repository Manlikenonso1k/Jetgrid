<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Site;
use App\Models\User;

/**
 * Authorisation for sites.
 *
 * Read this next to Site::booted(). The policy answers "is this person allowed
 * to ask?", the model gate answers "is this resource allowed to change?". Both
 * have to say yes, and only the policy has an opinion about roles — which is
 * why no role, including god_mode, can write to an adopted site.
 */
class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->atLeast(Role::Viewer);
    }

    public function view(User $user, Site $site): bool
    {
        return $user->atLeast(Role::Viewer);
    }

    public function create(User $user): bool
    {
        if (! $user->atLeast(Role::Developer)) {
            return false;
        }

        // Tier limit, checked here rather than by hiding the button.
        return $user->withinLimit('max_sites', $user->siteCount());
    }

    public function update(User $user, Site $site): bool
    {
        return $site->isManaged() && $user->atLeast(Role::Developer);
    }

    public function delete(User $user, Site $site): bool
    {
        return $site->isManaged() && $user->atLeast(Role::Admin);
    }

    /** Deploy, run artisan, edit .env, switch PHP version. */
    public function operate(User $user, Site $site): bool
    {
        return $site->isManaged() && $user->atLeast(Role::Developer);
    }

    /** Issue, renew or revoke a certificate. Never for adopted sites. */
    public function manageCertificates(User $user, Site $site): bool
    {
        return $site->isManaged() && $user->atLeast(Role::Developer);
    }

    /**
     * Hand a discovered site over to JetGrid. Deliberately god_mode only, and
     * deliberately separate from update(): this is the one action that changes
     * whether the other gates apply at all.
     */
    public function unprotect(User $user, Site $site): bool
    {
        return $site->isProtectedResource() && $user->isGodMode();
    }
}
