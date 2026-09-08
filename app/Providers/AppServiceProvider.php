<?php

namespace App\Providers;

use App\Services\Local\Platform\PlatformCommands;
use App\Services\Local\Platform\PlatformDetector;
use App\Services\Local\Platform\ToolLocator;
use App\Services\Server\FakeServerDriver;
use App\Services\Server\LinuxServerDriver;
use App\Services\Server\ServerDriver;
use App\Support\PathGuard;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Which host JetGrid is talking to. The fake driver executes nothing,
        // so pointing dev at it makes an accident physically impossible rather
        // than merely unlikely.
        $this->app->singleton(ServerDriver::class, function ($app) {
            return match (config('jetgrid.driver')) {
                'linux' => new LinuxServerDriver,
                default => new FakeServerDriver(config('jetgrid.fixtures_path')),
            };
        });

        // Safety constraint #2: the only directories JetGrid may write into.
        $this->app->singleton(PathGuard::class, fn () => new PathGuard([
            config('jetgrid.own_root'),
            config('jetgrid.managed_sites_root'),
            config('jetgrid.config_backup_dir'),
        ]));

        // Local mode. Both are singletons because both memoise: ToolLocator
        // caches PATH lookups and PlatformDetector caches the workstation
        // verdict, which includes a network probe that must not run once per
        // project per poll.
        $this->app->singleton(ToolLocator::class);
        $this->app->singleton(PlatformDetector::class);

        // The one place the OS is branched on. Everything downstream depends on
        // the interface, so a fourth platform is one new class and one new arm.
        $this->app->bind(PlatformCommands::class, fn ($app) => $app->make(PlatformDetector::class)->commands());
    }

    public function boot(): void
    {
        //
    }
}
