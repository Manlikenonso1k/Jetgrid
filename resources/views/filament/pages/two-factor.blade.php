<x-filament-panels::page>
    @if (session('jetgrid.2fa_required'))
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm dark:border-danger-500/40 dark:bg-danger-500/10">
            {{ session('jetgrid.2fa_required') }}
        </div>
    @endif

    @if (session('jetgrid.recovery_codes'))
        <x-filament::section heading="Recovery codes — shown once">
            <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">
                Each code works once, if you lose your authenticator. Store them somewhere that is not
                this server.
            </p>
            <div class="grid grid-cols-2 gap-2 font-mono text-sm sm:grid-cols-4">
                @foreach (session('jetgrid.recovery_codes') as $code)
                    <span class="rounded border border-gray-200 px-2 py-1 dark:border-white/10">{{ $code }}</span>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if ($this->isEnabled())
        <x-filament::section heading="Enabled">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Two-factor authentication is active on this account.
            </p>
        </x-filament::section>
    @else
        <x-filament::section heading="Set up two-factor authentication">
            <x-slot name="description">
                @if ($this->isRequired())
                    God mode requires 2FA. The rest of the panel stays locked until this is finished.
                @else
                    Recommended for every account that can change this server.
                @endif
            </x-slot>

            <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
                <div class="shrink-0 rounded-lg bg-white p-3 ring-1 ring-gray-200 dark:ring-white/10">
                    {{ $this->qrCode() }}
                </div>

                <div class="flex-1 space-y-4">
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            Scan the code, or enter this secret manually:
                        </p>
                        <p class="mt-1 select-all break-all font-mono text-sm">{{ $this->pendingSecret() }}</p>
                    </div>

                    <form wire:submit="confirm" class="space-y-4">
                        {{ $this->form }}

                        <x-filament::button type="submit">
                            Verify and enable
                        </x-filament::button>
                    </form>

                    <p class="text-xs text-gray-500">
                        The secret is not saved to your account until a code from your app verifies
                        against it, so an abandoned setup cannot lock you out.
                    </p>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
