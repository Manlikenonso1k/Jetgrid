@php
    $killSwitch = $this->killSwitch();
    $driver = $this->driver();
    $readOnly = $killSwitch->isReadOnly();
@endphp

<x-filament-panels::page>
    <x-filament::section heading="Kill switch" icon="heroicon-o-shield-check">
        <x-slot name="description">
            Safety constraint #8. Disables every write operation globally.
        </x-slot>

        <div @class([
            'rounded-lg border p-4 jg-readonly-banner',
            'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => $readOnly,
            'border-primary-200 bg-primary-50 dark:border-primary-500/30 dark:bg-primary-500/10' => ! $readOnly,
        ])>
            <p class="text-base font-semibold">
                {{ $readOnly ? 'READ-ONLY — writes are disabled' : 'WRITES ENABLED' }}
            </p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                {{ $killSwitch->explain() }}
            </p>
        </div>

        <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
            <div class="flex justify-between gap-4 border-b border-gray-100 pb-2 dark:border-white/10">
                <dt class="text-gray-500">JETGRID_READONLY (.env)</dt>
                <dd class="font-medium">{{ config('jetgrid.readonly') ? 'true' : 'false' }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-gray-100 pb-2 dark:border-white/10">
                <dt class="text-gray-500">Runtime unlock permitted</dt>
                <dd class="font-medium">{{ $killSwitch->runtimeUnlockPermitted() ? 'yes' : 'no' }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-gray-100 pb-2 dark:border-white/10">
                <dt class="text-gray-500">Dry run default</dt>
                <dd class="font-medium">{{ config('jetgrid.dry_run_default') ? 'on' : 'off' }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-gray-100 pb-2 dark:border-white/10">
                <dt class="text-gray-500">Server driver</dt>
                <dd class="font-medium">
                    {{ $driver->name() }}
                    @if ($driver->isFake())
                        <span class="jg-protected-badge ms-1">executes nothing</span>
                    @endif
                </dd>
            </div>
        </dl>
    </x-filament::section>

    <x-filament::section heading="What this switch does NOT affect" icon="heroicon-o-lock-closed">
        <div class="prose prose-sm dark:prose-invert max-w-none">
            <p>
                Turning writes on does <strong>not</strong> make adopted sites writable. Protection is
                a separate, harder gate: it is checked before the kill switch, it takes no user
                argument, and god mode hits it exactly like every other role. The only way out of it
                is to deliberately un-protect a single site, which is itself god-mode-only, requires
                typing the domain, and is recorded in the audit log.
            </p>
            <p class="mb-0">
                It also does not disable the whitelist, argument validation, the
                <code>nginx -t</code> check before any reload, config backups, or the audit log.
                Those are unconditional.
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
