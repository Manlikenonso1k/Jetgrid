<?php

namespace App\Filament\Resources;

use App\Enums\Role;
use App\Filament\Resources\PlanResource\Pages;
use App\Models\Plan;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'System';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->atLeast(Role::Admin) ?? false;
    }

    public static function form(Form $form): Form
    {
        $unlimited = 'Leave blank for unlimited.';

        return $form->schema([
            TextInput::make('name')->required()->maxLength(60),
            TextInput::make('slug')->required()->alphaDash()->unique(ignoreRecord: true),
            TextInput::make('price_cents')->numeric()->default(0)->label('Price (cents)')->required(),
            TextInput::make('max_sites')->numeric()->minValue(0)->helperText($unlimited),
            TextInput::make('max_databases')->numeric()->minValue(0)->helperText($unlimited),
            TextInput::make('max_storage_mb')->numeric()->minValue(0)->label('Storage (MB)')->helperText($unlimited),
            TextInput::make('max_backups')->numeric()->minValue(0)->helperText($unlimited),
            TextInput::make('monitor_interval_seconds')->numeric()->default(300)->required()
                ->helperText('How often uptime checks run for sites on this plan.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        $limit = fn ($state): string => $state === null ? 'unlimited' : (string) $state;

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->sortable(),
                Tables\Columns\TextColumn::make('price_cents')
                    ->label('Price')
                    ->formatStateUsing(fn (Plan $record): string => $record->priceLabel()),
                Tables\Columns\TextColumn::make('max_sites')->label('Sites')->formatStateUsing($limit),
                Tables\Columns\TextColumn::make('max_databases')->label('DBs')->formatStateUsing($limit),
                Tables\Columns\TextColumn::make('max_storage_mb')->label('Storage MB')->formatStateUsing($limit),
                Tables\Columns\TextColumn::make('max_backups')->label('Backups')->formatStateUsing($limit),
                Tables\Columns\TextColumn::make('monitor_interval_seconds')
                    ->label('Monitor every')
                    ->formatStateUsing(fn ($state): string => $state.'s'),
                Tables\Columns\TextColumn::make('users_count')->counts('users')->label('Users'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
