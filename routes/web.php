<?php

use App\Http\Controllers\Api\GridDataController;
use App\Http\Controllers\Api\SiteDetailController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

/*
 * Data for the 3D dashboard. Behind the panel's auth guard — the grid exposes
 * every domain on the box, so it is not public.
 */
Route::middleware(['web', 'auth'])->prefix('jetgrid/api')->group(function () {
    Route::get('grid', GridDataController::class)->name('jetgrid.api.grid');
    Route::get('sites/{site}', SiteDetailController::class)->name('jetgrid.api.site');
});
