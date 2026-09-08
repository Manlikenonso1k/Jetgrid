<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature 8: 2FA is mandatory for god mode.
 *
 * Enforced as middleware rather than by hiding pages, so there is no URL a
 * god_mode account without 2FA can reach except the enrolment page itself.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || ! $user->requiresTwoFactor() || $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        $enrolment = route('filament.admin.pages.two-factor');

        // Let them reach the enrolment page and log out; nothing else.
        if ($request->routeIs('filament.admin.pages.two-factor', 'filament.admin.auth.logout')) {
            return $next($request);
        }

        return redirect()->to($enrolment)
            ->with('jetgrid.2fa_required', 'God mode requires two-factor authentication. Set it up to continue.');
    }
}
