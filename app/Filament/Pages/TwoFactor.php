<?php

namespace App\Filament\Pages;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Feature 5/8 — TOTP enrolment.
 *
 * god_mode accounts are redirected here by RequireTwoFactor and cannot reach any
 * other page until they finish. The secret is only persisted once a code from
 * the authenticator has verified against it, so a half-finished enrolment can
 * never lock an account out.
 */
class TwoFactor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $title = 'Two-factor authentication';

    protected static ?string $slug = 'two-factor';

    protected static string $view = 'filament.pages.two-factor';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label('6-digit code from your authenticator')
                    ->required()
                    ->numeric()
                    ->length(6),
            ])
            ->statePath('data');
    }

    public function isEnabled(): bool
    {
        return auth()->user()->hasTwoFactorEnabled();
    }

    public function isRequired(): bool
    {
        return auth()->user()->requiresTwoFactor();
    }

    /** Held in the session until a code proves the user has actually stored it. */
    public function pendingSecret(): string
    {
        return Session::remember('jetgrid.2fa.pending', fn () => app(Google2FA::class)->generateSecretKey(32));
    }

    public function qrCode(): HtmlString
    {
        $url = app(Google2FA::class)->getQRCodeUrl(
            config('app.name'),
            auth()->user()->email,
            $this->pendingSecret(),
        );

        $writer = new Writer(new ImageRenderer(new RendererStyle(200), new SvgImageBackEnd));

        return new HtmlString($writer->writeString($url));
    }

    public function confirm(): void
    {
        $code = $this->form->getState()['code'];
        $secret = $this->pendingSecret();

        if (! app(Google2FA::class)->verifyKey($secret, $code)) {
            Notification::make()
                ->title('That code did not match')
                ->body('Check your device clock is accurate, then try the next code.')
                ->danger()
                ->send();

            return;
        }

        $recoveryCodes = collect(range(1, 8))
            ->map(fn (): string => Str::upper(Str::random(5).'-'.Str::random(5)))
            ->all();

        $user = auth()->user();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        Session::forget('jetgrid.2fa.pending');
        Session::flash('jetgrid.recovery_codes', $recoveryCodes);

        Notification::make()
            ->title('Two-factor authentication enabled')
            ->body('Store your recovery codes now — they are shown once.')
            ->success()
            ->persistent()
            ->send();

        $this->form->fill();
    }
}
