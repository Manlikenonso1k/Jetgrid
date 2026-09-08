@php
    $catalogue = $this->catalogue();
    $instances = $catalogue['instances'] ?? [];
    $current = $this->currentType();
    $verified = $this->pricesVerified();
    $currentSpec = $current ? ($instances[$current] ?? null) : null;
    $hoursPerMonth = 730;
@endphp

<x-filament-panels::page>
    {{-- The prompt asks for this to be unambiguous, so it is the first thing on
         the page rather than a footnote. --}}
    <div @class([
        'rounded-lg border p-4',
        'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10' => ! $verified,
        'border-primary-200 bg-primary-50 dark:border-primary-500/30 dark:bg-primary-500/10' => $verified,
    ])>
        <p class="text-sm font-semibold">
            {{ $verified ? 'Live pricing' : 'Unverified pricing — do not quote these numbers' }}
        </p>
        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $this->provenance() }}</p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Specs (vCPU, RAM, baseline CPU, credits/hour) come from
            {{ $catalogue['specs_source'] ?? 'the EC2 documentation' }} and are independent of pricing.
            Region: <strong>{{ $catalogue['region'] ?? 'unknown' }}</strong>.
        </p>
    </div>

    @if (! $current)
        <div class="rounded-lg border border-gray-200 p-4 text-sm dark:border-white/10">
            Set <code>JETGRID_INSTANCE_TYPE</code> in <code>.env</code> to highlight your instance here
            and to enable CPU-credit capacity estimates.
        </div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left dark:bg-white/5">
                <tr>
                    <th class="px-3 py-2 font-semibold">Type</th>
                    <th class="px-3 py-2 font-semibold">vCPU</th>
                    <th class="px-3 py-2 font-semibold">RAM</th>
                    <th class="px-3 py-2 font-semibold">Baseline CPU</th>
                    <th class="px-3 py-2 font-semibold">Credits/hr</th>
                    <th class="px-3 py-2 font-semibold">Network</th>
                    <th class="px-3 py-2 font-semibold text-right">Hourly</th>
                    <th class="px-3 py-2 font-semibold text-right">Monthly (730h)</th>
                    <th class="px-3 py-2 font-semibold text-right">Δ vs yours</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($instances as $type => $spec)
                    @php
                        $monthly = $spec['on_demand_hourly'] ? $spec['on_demand_hourly'] * $hoursPerMonth : null;
                        $currentMonthly = $currentSpec['on_demand_hourly'] ?? null;
                        $delta = ($monthly !== null && $currentMonthly !== null)
                            ? $monthly - ($currentMonthly * $hoursPerMonth)
                            : null;
                        $isCurrent = $type === $current;
                    @endphp
                    <tr @class(['bg-primary-50/60 dark:bg-primary-500/10' => $isCurrent])>
                        <td class="px-3 py-2 font-medium">
                            {{ $type }}
                            @if ($isCurrent)
                                <span class="jg-protected-badge ms-1">yours</span>
                            @endif
                            @if (! empty($spec['note']))
                                <div class="text-xs text-gray-500">{{ $spec['note'] }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2">{{ $spec['vcpu'] }}</td>
                        <td class="px-3 py-2">{{ $spec['ram_gib'] }} GiB</td>
                        <td class="px-3 py-2">{{ $spec['baseline_cpu_pct'] }}%</td>
                        <td class="px-3 py-2">{{ $spec['credits_per_hour'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs">{{ $spec['network'] }}</td>
                        <td @class(['px-3 py-2 text-right tabular-nums', 'line-through opacity-60' => ! $verified])>
                            ${{ number_format($spec['on_demand_hourly'], 4) }}
                        </td>
                        <td @class(['px-3 py-2 text-right tabular-nums font-medium', 'line-through opacity-60' => ! $verified])>
                            {{ $monthly !== null ? '$'.number_format($monthly, 2) : '—' }}
                        </td>
                        <td @class([
                            'px-3 py-2 text-right tabular-nums',
                            'opacity-60 line-through' => ! $verified,
                            'text-danger-600' => $delta > 0,
                            'text-primary-600' => $delta < 0,
                        ])>
                            @if ($delta === null || $isCurrent)
                                —
                            @else
                                {{ $delta > 0 ? '+' : '' }}${{ number_format($delta, 2) }}/mo
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <x-filament::section heading="Reading this table" collapsible collapsed>
        <div class="prose prose-sm dark:prose-invert max-w-none">
            <p>
                <strong>Baseline CPU</strong> is what a burstable instance can sustain indefinitely.
                Above it you spend credits; when the balance hits zero you are either throttled to
                baseline or billed for surplus credits, depending on whether unlimited mode is on.
            </p>
            <p>
                <strong>Credits/hour</strong> is the earn rate. If the Grid page says you are burning
                credits faster than you earn them, more sites will not fit no matter how much RAM is
                free &mdash; that is what the CPU-credit-bound label on the capacity estimate means.
            </p>
            <p class="mb-0">
                The <code>m7i</code> and <code>c7i</code> rows are fixed-performance instances: no
                credits, no cliff, higher floor price. They are here because "stop thinking about
                credits" is a legitimate answer to a credit problem.
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
