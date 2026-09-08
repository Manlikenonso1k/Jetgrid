<?php

namespace App\Filament\Resources;

use App\Enums\BeaconColor;
use App\Enums\ManagementMode;
use App\Filament\Resources\SiteResource\Pages;
use App\Models\Site;
use App\Services\Privilege\CommandRunner;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?int $navigationSort = -90;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Site')
                ->schema([
                    TextInput::make('domain')
                        ->required()
                        ->maxLength(253)
                        ->rule('regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i')
                        // Renaming an adopted site is refused by the model gate
                        // anyway; disabling it here just avoids a pointless error.
                        ->disabled(fn (?Site $record): bool => $record?->isProtectedResource() ?? false),

                    TextInput::make('display_name')->maxLength(120),

                    Select::make('php_version')
                        ->options(['8.1' => '8.1', '8.2' => '8.2', '8.3' => '8.3', '8.4' => '8.4'])
                        ->disabled(fn (?Site $record): bool => $record?->isProtectedResource() ?? false),

                    TextInput::make('document_root')
                        ->maxLength(255)
                        ->disabled(fn (?Site $record): bool => $record?->isProtectedResource() ?? false),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('domain')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Site $record): ?string => $record->document_root),

                Tables\Columns\TextColumn::make('management_mode')
                    ->label('Mode')
                    ->badge()
                    ->formatStateUsing(fn (ManagementMode $state): string => $state->label())
                    ->color(fn (ManagementMode $state): string => $state === ManagementMode::Managed ? 'primary' : 'gray'),

                Tables\Columns\TextColumn::make('beacon_color')
                    ->label('Beacon')
                    ->badge()
                    ->formatStateUsing(fn (BeaconColor $state): string => ucfirst($state->value))
                    ->color(fn (BeaconColor $state): string => match ($state) {
                        BeaconColor::Green => 'success',
                        BeaconColor::Red => 'danger',
                        BeaconColor::Yellow => 'warning',
                        BeaconColor::Blue => 'info',
                        BeaconColor::Grey => 'gray',
                        BeaconColor::Purple => 'purple',
                    }),

                Tables\Columns\TextColumn::make('health_score')
                    ->label('Health')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => $state.'/100'),

                Tables\Columns\TextColumn::make('certificate.not_after')
                    ->label('Cert expires')
                    ->date('Y-m-d')
                    ->description(fn (Site $record): ?string => $record->certificate?->daysRemaining() !== null
                        ? $record->certificate->daysRemaining().' days'
                        : null)
                    ->color(fn (Site $record): string => match (true) {
                        $record->certificate?->daysRemaining() === null => 'gray',
                        $record->certificate->daysRemaining() <= 0 => 'danger',
                        $record->certificate->daysRemaining() <= 14 => 'warning',
                        default => 'success',
                    }),

                Tables\Columns\TextColumn::make('pending_updates')
                    ->label('Updates')
                    ->badge()
                    ->color(fn ($state): string => $state > 0 ? 'warning' : 'gray'),

                Tables\Columns\TextColumn::make('discovered_at')
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_protected')
                    ->label('Protection')
                    ->trueLabel('Adopted — protected')
                    ->falseLabel('Managed by JetGrid')
                    ->placeholder('All sites'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                /*
                 * Safety constraint #1: for an adopted site these are not
                 * rendered at all. A greyed-out Edit still implies JetGrid edits
                 * this site — it does not, and never will.
                 */
                Tables\Actions\EditAction::make()
                    ->visible(fn (Site $record): bool => $record->isManaged()),

                Tables\Actions\Action::make('unprotect')
                    ->label('Adopt into JetGrid')
                    ->icon('heroicon-o-lock-open')
                    ->color('danger')
                    ->visible(fn (Site $record): bool => auth()->user()?->can('unprotect', $record) ?? false)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Site $record): string => "Hand {$record->domain} over to JetGrid?")
                    ->modalDescription('This is the only way a discovered site becomes writable. After this, JetGrid can edit its vhost, restart its services and manage its certificate. Do this only for a site you are certain JetGrid should own. It is recorded in the audit log.')
                    ->modalSubmitActionLabel('Yes, un-protect it')
                    ->form([
                        TextInput::make('confirm_domain')
                            ->label('Type the domain to confirm')
                            ->required()
                            ->rule(fn (Site $record) => 'in:'.$record->domain),
                    ])
                    ->action(function (Site $record): void {
                        $record->unprotect();

                        Notification::make()
                            ->title("{$record->domain} is now managed by JetGrid")
                            ->body('Write operations are still gated by the kill switch, dry-run and the audit log.')
                            ->warning()
                            ->send();
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('domain');
    }

    /** Protected sites cannot be created or deleted here — only discovered. */
    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Site::class) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('certificate');
    }

    public static function getNavigationBadge(): ?string
    {
        $readOnly = app(CommandRunner::class)->readOnly();

        return $readOnly ? 'read-only' : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'view' => Pages\ViewSite::route('/{record}'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}
