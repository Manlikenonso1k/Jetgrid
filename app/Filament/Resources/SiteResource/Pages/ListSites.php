<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use App\Services\Discovery\DiscoveryService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSites extends ListRecords
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('discover')
                ->label('Scan server')
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                ->modalHeading('Read-only discovery')
                ->modalDescription('JetGrid will read nginx and apache vhosts, systemd units, supervisor programs, PHP-FPM pools, crontabs and the web roots. Anything new is imported as Adopted - Protected. Nothing on the server is modified, and this works even in read-only mode.')
                ->modalSubmitActionLabel('Scan')
                ->requiresConfirmation()
                ->action(function (): void {
                    $report = app(DiscoveryService::class)->run();

                    $lines = [];

                    foreach ($report->countByType() as $type => $count) {
                        $lines[] = "{$type}: {$count}";
                    }

                    Notification::make()
                        ->title(count($report->sitesCreated).' new site(s) imported as protected')
                        ->body(implode(' | ', $lines).($report->drifted !== [] ? "\n".count($report->drifted).' config file(s) changed since the last scan.' : ''))
                        ->success()
                        ->persistent()
                        ->send();
                }),

            Actions\CreateAction::make(),
        ];
    }
}
