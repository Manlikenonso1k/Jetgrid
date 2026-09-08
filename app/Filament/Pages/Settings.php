<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\Server\ServerDriver;
use App\Support\KillSwitch;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Safety constraint #8 — the kill switch, and only a god_mode user can see it.
 */
class Settings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $title = 'Safety settings';

    protected static string $view = 'filament.pages.settings';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isGodMode() ?? false;
    }

    public function mount(): void
    {
        abort_unless(auth()->user()?->isGodMode() ?? false, 403);
    }

    public function killSwitch(): KillSwitch
    {
        return app(KillSwitch::class);
    }

    public function driver(): ServerDriver
    {
        return app(ServerDriver::class);
    }

    protected function getHeaderActions(): array
    {
        $killSwitch = $this->killSwitch();

        return [
            Action::make('enableWrites')
                ->label('Enable write operations')
                ->icon('heroicon-o-lock-open')
                ->color('danger')
                ->visible(fn (): bool => $killSwitch->isReadOnly() && $killSwitch->runtimeUnlockPermitted())
                ->requiresConfirmation()
                ->modalHeading('Enable write operations')
                ->modalDescription(new HtmlString(
                    'This lets JetGrid run the whitelisted write commands against this server. '
                    .'Adopted sites stay protected — that gate is not affected by this switch and cannot be lifted. '
                    .'Dry-run remains the default for every action.'
                ))
                ->form([
                    TextInput::make('confirm')
                        ->label('Type ENABLE WRITES to confirm')
                        ->required()
                        ->rule('in:ENABLE WRITES'),
                ])
                ->action(function (): void {
                    Setting::put(KillSwitch::SETTING, false, auth()->user());

                    Notification::make()
                        ->title('Write operations enabled')
                        ->body('Adopted sites remain read-only. Every command is still whitelisted, previewed and audited.')
                        ->warning()
                        ->persistent()
                        ->send();
                }),

            Action::make('disableWrites')
                ->label('Go read-only')
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->visible(fn (): bool => ! $killSwitch->isReadOnly())
                ->requiresConfirmation()
                ->modalDescription('Takes effect on the next request. Any queued write job will refuse when it runs.')
                ->action(function (): void {
                    Setting::put(KillSwitch::SETTING, true, auth()->user());

                    Notification::make()->title('JetGrid is now read-only')->success()->send();
                }),
        ];
    }
}
