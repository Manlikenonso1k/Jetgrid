@php
    $killSwitch = app(\App\Support\KillSwitch::class);
    $readOnly = $killSwitch->isReadOnly();
    $driver = app(\App\Services\Server\ServerDriver::class);
@endphp

{{-- Always visible in the topbar. Knowing which mode JetGrid is in should never
     require navigating anywhere. --}}
<div class="flex items-center gap-2 pe-2">
    @if ($driver->isFake())
        <span
            class="jg-protected-badge"
            title="The fake driver reads fixtures and cannot execute anything on a real host."
        >
            FAKE SERVER
        </span>
    @endif

    <span
        @class([
            'inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold ring-1 ring-inset',
            'bg-gray-50 text-gray-600 ring-gray-300 dark:bg-gray-500/10 dark:text-gray-300 dark:ring-gray-500/30' => $readOnly,
            'bg-primary-50 text-primary-700 ring-primary-600/30 dark:bg-primary-500/10 dark:text-primary-300' => ! $readOnly,
        ])
        title="{{ $killSwitch->explain() }}"
    >
        <span @class([
            'h-1.5 w-1.5 rounded-full',
            'bg-gray-400' => $readOnly,
            'bg-primary-500' => ! $readOnly,
        ])></span>
        {{ $readOnly ? 'READ-ONLY' : 'WRITES ENABLED' }}
    </span>
</div>
