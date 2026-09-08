@php
    $breakdown = $this->capacityBreakdown();
    $estimate = $breakdown['estimate'];
@endphp

<x-filament-panels::page>
    {{-- The React root. Everything inside the canvas is driven by the JSON
         endpoints below; this page never re-renders it via Livewire. --}}
    {{--
        wire:ignore is load-bearing, not defensive. This page is a Livewire
        component, and any re-render — a notification, a poll, a property change —
        makes Livewire diff the DOM. Without this it rips out or duplicates the
        React-mounted node and the canvas goes blank or ghosts a second context.
    --}}
    <div wire:ignore class="jg-scene-host">
        <div
            data-jetgrid-scene
            data-grid-endpoint="{{ route('jetgrid.api.grid') }}"
            data-site-endpoint="{{ url('jetgrid/api/sites') }}"
        >
            {{-- Replaced when React mounts; still visible means the bundle never ran. --}}
            <div style="padding:24px;color:#8a97a0;font:13px/1.6 ui-monospace,Menlo,Consolas,monospace">
                Booting the 3D grid&hellip; if this text remains, the JavaScript bundle did not execute.
            </div>
        </div>
    </div>

    @vite('resources/js/grid/main.jsx')

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3 mt-6">
        @foreach ($estimate->constraints as $constraint)
            <x-filament::section :heading="$constraint->name">
                <x-slot name="description">
                    @if ($constraint->slots === null)
                        Not evaluated
                    @elseif ($constraint->key === $estimate->limitingFactor)
                        <span class="font-semibold text-primary-600">
                            Limiting factor — {{ $constraint->slots }} site(s)
                        </span>
                    @else
                        Allows {{ $constraint->slots }} more site(s)
                    @endif
                </x-slot>

                @if ($constraint->unavailableReason)
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $constraint->unavailableReason }}
                    </p>
                @else
                    <dl class="text-sm divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($constraint->workings as $row)
                            <div class="flex justify-between gap-4 py-1.5">
                                <dt class="text-gray-500 dark:text-gray-400">{{ $row['label'] }}</dt>
                                <dd class="font-medium tabular-nums">{{ $row['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="How this estimate is built" collapsible collapsed class="mt-4">
        <div class="prose prose-sm dark:prose-invert max-w-none">
            <p>
                Each constraint is computed independently from numbers read off this server, and the
                tightest one is reported as the limiting factor. A constraint whose inputs could not
                be read is excluded rather than defaulted &mdash; the estimate is never propped up by
                a guess.
            </p>
            <p class="mb-0">
                <strong>Current answer:</strong> {{ $estimate->headline() }}
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
