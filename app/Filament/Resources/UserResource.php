<?php

namespace App\Filament\Resources;

use App\Enums\Role;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'System';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->atLeast(Role::Admin) ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->maxLength(120),

            TextInput::make('email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true),

            TextInput::make('password')
                ->password()
                ->revealable()
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->required(fn (string $operation): bool => $operation === 'create')
                ->minLength(12)
                ->helperText('Minimum 12 characters. Leave blank to keep the current password.'),

            /*
             * Feature 8: only god_mode may change a role. Disabling the field
             * for everyone else is the visible half; UserPolicy::promote() is
             * the half that actually enforces it.
             */
            Select::make('role')
                ->options(collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => $r->label()]))
                ->required()
                ->default(Role::Viewer->value)
                ->disabled(fn (): bool => ! (auth()->user()?->isGodMode() ?? false))
                ->helperText(fn (): ?string => (auth()->user()?->isGodMode() ?? false)
                    ? 'God mode also requires two-factor authentication before the panel will let them in.'
                    : 'Only a god_mode user can change roles.'),

            Select::make('plan_id')
                ->relationship('plan', 'name')
                ->label('Plan')
                ->helperText('Tier limits are enforced by policies on every write, not just hidden in the UI.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable()->copyable(),

                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (Role $state): string => $state->label())
                    ->color(fn (Role $state): string => match ($state) {
                        Role::GodMode => 'danger',
                        Role::Admin => 'warning',
                        Role::Developer => 'primary',
                        Role::Viewer => 'gray',
                    }),

                Tables\Columns\TextColumn::make('plan.name')->label('Plan')->placeholder('—'),

                Tables\Columns\IconColumn::make('two_factor_confirmed_at')
                    ->label('2FA')
                    ->boolean()
                    ->getStateUsing(fn (User $record): bool => $record->hasTwoFactorEnabled())
                    ->trueColor('primary')
                    ->falseColor(fn (User $record): string => $record->requiresTwoFactor() ? 'danger' : 'gray')
                    ->tooltip(fn (User $record): ?string => $record->requiresTwoFactor() && ! $record->hasTwoFactorEnabled()
                        ? 'God mode requires 2FA — this account is locked out of the panel until it is set up.'
                        : null),

                Tables\Columns\TextColumn::make('last_login_at')->dateTime('Y-m-d H:i')->placeholder('never'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (User $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
