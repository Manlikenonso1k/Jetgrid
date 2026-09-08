<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Safety constraint #7. Visible to god_mode only, and read-only everywhere:
 * there is no create page, no edit page and no delete action, because the model
 * itself refuses all three.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $label = 'Audit log';

    protected static ?string $pluralLabel = 'Audit log';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isGodMode() ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user_email')
                    ->label('Who')
                    ->placeholder('system / scheduler')
                    ->description(fn (AuditLog $record): ?string => $record->user_role)
                    ->searchable(),

                Tables\Columns\TextColumn::make('command_key')
                    ->label('Command')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('target_label')
                    ->label('Target')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\IconColumn::make('dry_run')
                    ->label('Dry run')
                    ->boolean()
                    ->trueIcon('heroicon-o-eye')
                    ->falseIcon('heroicon-o-bolt')
                    ->trueColor('gray')
                    ->falseColor('warning'),

                Tables\Columns\TextColumn::make('outcome')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'dry_run' => 'gray',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('exit_code')->label('Exit')->placeholder('—'),

                Tables\Columns\TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : $state.' ms')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('outcome')
                    ->options([
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'dry_run' => 'Dry run',
                        'pending' => 'Pending',
                    ]),
                Tables\Filters\TernaryFilter::make('dry_run')->label('Dry run'),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('What ran')
                ->schema([
                    TextEntry::make('command_key')->badge()->color('gray'),
                    TextEntry::make('command_string')
                        ->label('Literal command')
                        ->copyable()
                        ->columnSpanFull()
                        ->fontFamily('mono'),
                    TextEntry::make('arguments')
                        ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT) ?: '—')
                        ->fontFamily('mono')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Context')
                ->schema([
                    TextEntry::make('user_email')->label('User')->placeholder('system / scheduler'),
                    TextEntry::make('user_role')->label('Role')->placeholder('—'),
                    TextEntry::make('ip_address')->label('IP')->placeholder('—'),
                    TextEntry::make('driver'),
                    TextEntry::make('target_label')->label('Target')->placeholder('—'),
                    TextEntry::make('dry_run')
                        ->label('Dry run')
                        ->formatStateUsing(fn ($state): string => $state ? 'Yes — nothing executed' : 'No — executed'),
                ])
                ->columns(3),

            Section::make('Result')
                ->schema([
                    TextEntry::make('outcome')->badge(),
                    TextEntry::make('exit_code')->placeholder('—'),
                    TextEntry::make('duration_ms')->label('Duration (ms)')->placeholder('—'),
                    TextEntry::make('stdout')->columnSpanFull()->fontFamily('mono')->placeholder('(empty)'),
                    TextEntry::make('stderr')->columnSpanFull()->fontFamily('mono')->placeholder('(empty)'),
                ])
                ->columns(3),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
