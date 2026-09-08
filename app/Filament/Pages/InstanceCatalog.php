<?php

namespace App\Filament\Pages;

use App\Services\Capacity\InstanceCatalogue;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Feature 7 — instance comparison, and an honest account of where the numbers
 * came from. See InstanceCatalogue for why specs and prices are treated
 * differently.
 */
class InstanceCatalog extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Instance costs';

    protected static ?string $title = 'EC2 instance comparison';

    protected static ?int $navigationSort = -70;

    protected static string $view = 'filament.pages.instance-catalog';

    public function catalogue(): array
    {
        return InstanceCatalogue::load();
    }

    public function currentType(): ?string
    {
        return config('jetgrid.instance_type');
    }

    public function provenance(): string
    {
        return InstanceCatalogue::provenance();
    }

    public function pricesVerified(): bool
    {
        return InstanceCatalogue::pricesAreVerified();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh prices from AWS')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription('Calls the AWS Price List API and caches the result. Needs pricing:GetProducts. Specs are not changed.')
                ->action(function (): void {
                    $result = InstanceCatalogue::refresh();

                    Notification::make()
                        ->title($result['ok'] ? 'Prices refreshed' : 'Refresh not completed')
                        ->body($result['message'])
                        ->status($result['ok'] ? 'success' : 'warning')
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
