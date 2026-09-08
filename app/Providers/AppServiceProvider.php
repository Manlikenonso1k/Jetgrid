<?php

namespace App\Providers;

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
    }

    public function boot(): void
    {
        //
    }
}
