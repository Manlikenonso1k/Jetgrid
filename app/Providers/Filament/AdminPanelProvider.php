<?php

namespace App\Providers\Filament;

use App\Http\Middleware\RequireTwoFactor;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('JetGrid')
            ->favicon(asset('favicon.ico'))
            /*
             * White base, neon-green accent. The palette is generated from
             * #17800F, not from #39FF14: the true neon fails contrast badly on
             * white (about 1.4:1), so it is reserved for the dark 3D canvas and
             * the UI uses the readable variant. Both are defined as tokens in
             * resources/css/jetgrid.css.
             */
            ->colors([
                'primary' => Color::hex('#17800F'),
                'danger' => Color::Red,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
                'gray' => Color::Slate,
                // BeaconColor::Purple (a project running under Docker) is shown
                // as a badge. Filament resolves badge colours through this
                // registry, so a name that is not registered here renders with no
                // styling at all rather than failing loudly.
                'purple' => Color::Purple,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render("@vite('resources/css/jetgrid.css')"),
            )
            /*
             * A permanent, panel-wide reminder of what mode JetGrid is in.
             * Safety constraint #8 is useless if you cannot tell at a glance
             * whether it is on.
             */
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => Blade::render('<x-jetgrid.mode-indicator />'),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // Feature 8: 2FA is mandatory for god_mode, enforced here rather
                // than by hiding pages.
                RequireTwoFactor::class,
            ]);
    }
}
