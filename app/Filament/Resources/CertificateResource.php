<?php

namespace App\Filament\Resources;

use App\Enums\CertificateStatus;
use App\Filament\Resources\CertificateResource\Pages;
use App\Models\Certificate;
use App\Services\Certificates\CertificateService;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CertificateResource extends Resource
{
    protected static ?string $model = Certificate::class;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?int $navigationSort = -80;

    public static function canCreate(): bool
    {
        // Certificates are issued from a site, where the domain and the
        // protected gate are unambiguous.
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('domain')->searchable()->sortable()->weight('medium'),

                Tables\Columns\IconColumn::make('is_managed')
                    ->label('Managed')
                    ->boolean()
                    ->trueIcon('heroicon-o-pencil-square')
                    ->falseIcon('heroicon-o-eye')
                    ->trueColor('primary')
                    ->falseColor('gray')
                    ->tooltip(fn (Certificate $record): string => $record->is_managed
                        ? 'JetGrid issued this and may renew or revoke it.'
                        : 'Adopted certificate — read from disk for display only. JetGrid will never touch it.'),

                Tables\Columns\TextColumn::make('not_after')
                    ->label('Expires')
                    ->date('Y-m-d')
                    ->sortable()
                    ->description(fn (Certificate $record): string => $record->daysRemaining() === null
                        ? 'unknown'
                        : $record->daysRemaining().' days'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (CertificateStatus $state): string => match ($state) {
                        CertificateStatus::Valid => 'success',
                        CertificateStatus::Expiring => 'warning',
                        CertificateStatus::Expired, CertificateStatus::Failed => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('renewal_failures')
                    ->label('Failures')
                    ->badge()
                    ->color(fn ($state): string => $state > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('last_renewed_at')->dateTime('Y-m-d H:i')->placeholder('never'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_managed')
                    ->label('Managed by JetGrid')
                    ->trueLabel('Managed')
                    ->falseLabel('Adopted — read only')
                    ->placeholder('All'),
            ])
            ->actions([
                // Only ever rendered for managed certificates.
                Tables\Actions\Action::make('renew')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Certificate $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->form([
                        Toggle::make('dry_run')
                            ->label('Dry run (show the command, execute nothing)')
                            ->default(true),
                    ])
                    ->action(function (Certificate $record, array $data): void {
                        $result = app(CertificateService::class)->renew($record, $data['dry_run']);

                        Notification::make()
                            ->title($result['ok'] ? 'Renewal' : 'Renewal failed')
                            ->body($result['message'])
                            ->status($result['ok'] ? 'success' : 'danger')
                            ->persistent()
                            ->send();
                    }),

                Tables\Actions\Action::make('revoke')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Revoking is immediate and cannot be undone. The site will serve an invalid certificate until a new one is issued.')
                    ->visible(fn (Certificate $record): bool => auth()->user()?->can('revoke', $record) ?? false)
                    ->form([
                        Toggle::make('dry_run')->label('Dry run')->default(true),
                    ])
                    ->action(function (Certificate $record, array $data): void {
                        $result = app(CertificateService::class)->revoke($record, $data['dry_run']);

                        Notification::make()
                            ->title($result['ok'] ? 'Revoke' : 'Revoke failed')
                            ->body($result['message'])
                            ->status($result['ok'] ? 'success' : 'danger')
                            ->send();
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('not_after');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCertificates::route('/'),
        ];
    }
}
