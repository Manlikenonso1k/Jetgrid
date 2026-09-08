<?php

namespace App\Filament\Resources\CertificateResource\Pages;

use App\Filament\Resources\CertificateResource;
use App\Services\Certificates\CertificateService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCertificates extends ListRecords
{
    protected static string $resource = CertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import')
                ->label('Re-read certificates')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function (): void {
                    $count = app(CertificateService::class)->import();

                    Notification::make()
                        ->title("Read {$count} certificates")
                        ->body('Read-only: expiry dates were refreshed from disk. No certificate was modified.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Certificates for adopted sites are listed so their expiry is visible, and can never be renewed or revoked from here.';
    }
}
