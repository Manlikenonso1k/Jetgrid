<?php

namespace App\Filament\Pages;

use App\Services\Capacity\CapacityEstimator;
use App\Services\Metrics\MetricsCollector;
use App\Support\KillSwitch;
use Filament\Pages\Page;

/**
 * Feature 1 — the 3D grid, mounted as a Filament page.
 *
 * The page itself is a shell: it renders one div and lets React take over. All
 * data arrives from /jetgrid/api/grid, so nothing about the scene depends on
 * Livewire round-trips.
 */
class GridDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Grid';

    protected static ?string $title = 'Grid';

    protected static ?int $navigationSort = -100;

    protected static ?string $slug = 'grid';

    protected static string $view = 'filament.pages.grid-dashboard';

    public function getSubheading(): ?string
    {
        return app(KillSwitch::class)->explain();
    }

    /** Shown under the canvas so the estimate can be audited, not just trusted. */
    public function capacityBreakdown(): array
    {
        $facts = app(MetricsCollector::class)->collect();
        $estimate = app(CapacityEstimator::class)->estimate($facts);

        return [
            'estimate' => $estimate,
            'facts' => $facts,
        ];
    }
}
