<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use App\Models\Site;
use App\Services\Certificates\CertificateService;
use App\Services\Health\DiagnosticsService;
use App\Services\Privilege\CommandRunner;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;

class ViewSite extends ViewRecord
{
    protected static string $resource = SiteResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Protection')
                ->description('Whether JetGrid may write to this site at all.')
                ->schema([
                    TextEntry::make('management_mode')
                        ->label('Mode')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state->label())
                        ->color(fn (Site $record) => $record->isManaged() ? 'primary' : 'gray'),

                    TextEntry::make('protection_note')
                        ->label('')
                        ->state(fn (Site $record): string => $record->isProtectedResource()
                            ? 'JetGrid discovered this site; it did not create it. It will never modify, restart, reconfigure or issue certificates for it. This is a hard gate in the code, not a permission — no role, including god mode, can bypass it.'
                            : 'JetGrid manages this site. Every write is still whitelisted, previewed, validated and audited.')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Configuration')
                ->schema([
                    TextEntry::make('domain')->copyable(),
                    TextEntry::make('document_root')->placeholder('unknown'),
                    TextEntry::make('vhost_path')->label('vhost')->placeholder('unknown'),
                    TextEntry::make('php_version')->placeholder('unknown'),
                    TextEntry::make('server_user')->placeholder('unknown'),
                    TextEntry::make('discovered_at')->dateTime(),
                ])
                ->columns(3),

            Section::make('Health')
                ->schema([
                    TextEntry::make('health_score')->label('Score')->formatStateUsing(fn ($s) => $s.'/100'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('pending_updates')->label('Pending updates'),
                    TextEntry::make('certificate.not_after')
                        ->label('Certificate expires')
                        ->dateTime('Y-m-d')
                        ->placeholder('no certificate found'),
                    TextEntry::make('certificate.is_managed')
                        ->label('Certificate managed by JetGrid')
                        ->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No — read-only')
                        ->placeholder('—'),
                    TextEntry::make('disk_bytes')
                        ->label('Disk footprint')
                        ->formatStateUsing(fn ($state): string => number_format($state / 1048576, 1).' MB'),
                ])
                ->columns(3),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Diagnostics is READ-ONLY, so it is offered for adopted sites too —
            // finding out why someone else's project is down is exactly the case
            // where you least want to be SSHing around.
            Actions\Action::make('diagnose')
                ->label('Run diagnostics')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('gray')
                ->modalHeading(fn (Site $record): string => "Triage for {$record->domain}")
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(function (Site $record): HtmlString {
                    $results = app(DiagnosticsService::class)->run($record);

                    $html = '<div class="space-y-2 text-sm">';

                    foreach ($results as $result) {
                        $colour = match ($result['status']) {
                            'pass' => 'text-primary-600',
                            'warn' => 'text-amber-600',
                            'fail' => 'text-danger-600',
                            default => 'text-gray-500',
                        };

                        $html .= '<div class="rounded-md border border-gray-200 dark:border-white/10 p-2">'
                            .'<div class="font-semibold '.$colour.'">'
                            .strtoupper($result['status']).' — '.e($result['check'])
                            .'</div><div class="text-gray-600 dark:text-gray-300 whitespace-pre-wrap">'
                            .e($result['detail']).'</div></div>';
                    }

                    return new HtmlString($html.'</div>');
                }),

            Actions\Action::make('issueCertificate')
                ->label('Issue / renew certificate')
                ->icon('heroicon-o-lock-closed')
                ->visible(fn (Site $record): bool => auth()->user()?->can('manageCertificates', $record) ?? false)
                ->form([
                    Placeholder::make('preview')
                        ->label('Command that will run')
                        ->content(fn (Site $record): HtmlString => new HtmlString(
                            '<code class="text-xs break-all">'
                            .e(app(CommandRunner::class)->preview('certbot.issue.http01', [
                                'domain' => $record->domain,
                                'email' => auth()->user()->email,
                                'webroot' => config('jetgrid.certificates.webroot'),
                            ]))
                            .'</code>'
                        )),

                    Select::make('challenge')
                        ->options(['http-01' => 'HTTP-01 (needs port 80)', 'dns-01' => 'DNS-01 (wildcards, closed port 80)'])
                        ->default('http-01')
                        ->required(),

                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->default(fn () => auth()->user()->email),

                    // Safety constraint #6: dry run is the default, every time.
                    Toggle::make('dry_run')
                        ->label('Dry run (show the command, execute nothing)')
                        ->default(true)
                        ->helperText('Leave this on until you have read the command above.'),
                ])
                ->action(function (Site $record, array $data): void {
                    $result = app(CertificateService::class)->issue(
                        site: $record,
                        email: $data['email'],
                        challenge: $data['challenge'],
                        dryRun: $data['dry_run'],
                    );

                    Notification::make()
                        ->title($result['ok'] ? 'Certificate request' : 'Refused')
                        ->body($result['message'].($result['preview'] ? "\n\n".$result['preview'] : ''))
                        ->status($result['ok'] ? 'success' : 'danger')
                        ->persistent()
                        ->send();
                }),

            Actions\EditAction::make()
                ->visible(fn (Site $record): bool => auth()->user()?->can('update', $record) ?? false),
        ];
    }
}
