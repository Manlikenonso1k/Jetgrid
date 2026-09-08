<?php

use App\Http\Controllers\Api\GridDataController;
use App\Http\Controllers\Api\LocalProjectController;
use App\Http\Controllers\Api\SiteDetailController;
use App\Http\Middleware\EnsureLocalMode;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

/*
 * Art-direction sandbox for the 3D scene: hardcoded mock houses, no data layer
 * and no auth to sit behind. Registered only in local so it cannot exist on a
 * production install at all, rather than existing and returning 403.
 */
if (app()->environment('local')) {
    Route::view('grid-preview', 'grid-preview')->name('jetgrid.grid-preview');
}

/*
 * Data for the 3D dashboard. Behind the panel's auth guard — the grid exposes
 * every domain on the box, so it is not public.
 */
Route::middleware(['web', 'auth'])->prefix('jetgrid/api')->group(function () {
    Route::get('grid', GridDataController::class)->name('jetgrid.api.grid');
    Route::get('sites/{site}', SiteDetailController::class)->name('jetgrid.api.site');
});

/*
 * L0. Local discovery and process control.
 *
 * The name prefix is not decoration: LocalModeRouteTest asserts that EVERY route
 * named jetgrid.api.local.* carries the EnsureLocalMode middleware, so a route
 * added to this file without the gate fails the suite. Reads are in here too —
 * the project list discloses every folder on the developer's machine, which is
 * not something a production install should answer.
 */
Route::middleware(['web', 'auth', EnsureLocalMode::ALIAS])
    ->prefix('jetgrid/api/local')
    ->name('jetgrid.api.local.')
    ->group(function () {
        Route::get('projects', [LocalProjectController::class, 'index'])->name('index');
        Route::post('projects/rescan', [LocalProjectController::class, 'rescan'])->name('rescan');
        Route::get('projects/{project}', [LocalProjectController::class, 'show'])->name('show');
        Route::get('projects/{project}/log', [LocalProjectController::class, 'log'])->name('log');
        Route::post('projects/{project}/start', [LocalProjectController::class, 'start'])->name('start');
        Route::post('projects/{project}/stop', [LocalProjectController::class, 'stop'])->name('stop');
        Route::post('projects/{project}/restart', [LocalProjectController::class, 'restart'])->name('restart');
        Route::post('projects/{project}/maintenance', [LocalProjectController::class, 'maintenance'])->name('maintenance');
    });
