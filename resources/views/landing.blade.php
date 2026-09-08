<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>JetGrid — your server, seen from above</title>
    <meta name="description" content="JetGrid shows every site on one box as a city seen from a jet. Certificates, capacity and health at a glance — read-only until you say otherwise.">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">

    @vite('resources/css/app.css')

    <style>
        /*
         * The hero visual is SVG and CSS only — no three.js on the landing page.
         * The 3D bundle measures 683 kB for three plus 293 kB for the drei chunk
         * before any scene code, which is not a defensible cost for a page whose
         * only job is to load fast and explain itself.
         */
        @keyframes jg-strobe {
            0%, 4% { opacity: 1; r: 7; }
            14%    { opacity: 1; r: 6; }
            34%    { opacity: 0.05; r: 4; }
            100%   { opacity: 0.05; r: 4; }
        }

        .jg-beacon { animation: jg-strobe var(--period, 3s) linear infinite; transform-origin: center; }

        @media (prefers-reduced-motion: reduce) {
            .jg-beacon { animation: none; opacity: 1; }
        }
    </style>
</head>
<body class="bg-white text-slate-900 antialiased">

<header class="border-b border-slate-200">
    <nav class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
        <a href="{{ route('landing') }}" class="flex items-center gap-2 font-semibold tracking-tight">
            <span class="inline-block h-2.5 w-2.5 rounded-full bg-[#17800F]"></span>
            JetGrid
        </a>

        <div class="flex items-center gap-3 text-sm">
            <a href="{{ \Filament\Facades\Filament::getLoginUrl() }}"
               class="rounded-md px-3 py-2 font-medium text-slate-600 hover:text-slate-900">
                Log in
            </a>
            <a href="{{ \Filament\Facades\Filament::getRegistrationUrl() }}"
               class="rounded-md bg-[#17800F] px-4 py-2 font-medium text-white hover:bg-[#136a0c]">
                Get started
            </a>
        </div>
    </nav>
</header>

{{-- ── Hero ─────────────────────────────────────────────────────────────── --}}
<section class="mx-auto max-w-6xl px-6 pt-16 pb-12 md:pt-24">
    <div class="grid items-center gap-12 md:grid-cols-2">
        <div>
            <h1 class="text-4xl font-semibold leading-tight tracking-tight md:text-5xl">
                Your server,<br>seen from above.
            </h1>
            <p class="mt-5 max-w-md text-lg text-slate-600">
                Every site on one box, rendered as a city viewed from a jet. Health, certificates
                and remaining capacity readable in a single glance — no tailing six log files to
                find out what broke.
            </p>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                <a href="{{ \Filament\Facades\Filament::getRegistrationUrl() }}"
                   class="rounded-md bg-[#17800F] px-5 py-3 font-medium text-white hover:bg-[#136a0c]">
                    Create an account
                </a>
                <a href="#features" class="rounded-md border border-slate-300 px-5 py-3 font-medium hover:border-slate-400">
                    See what it does
                </a>
            </div>

            <p class="mt-5 text-sm text-slate-500">
                Installs read-only. It cannot change anything on your server until you explicitly
                turn writes on.
            </p>
        </div>

        {{-- Dark panel: the one place the true neon is legible. --}}
        <div class="overflow-hidden rounded-xl bg-[#0a0e0a] p-4 shadow-xl ring-1 ring-slate-900/10">
            <svg viewBox="0 0 640 400" class="w-full" role="img"
                 aria-label="Illustration of sites on a server rendered as lit houses on a dark grid">
                <defs>
                    <linearGradient id="jg-fade" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#39FF14" stop-opacity="0.30"/>
                        <stop offset="100%" stop-color="#39FF14" stop-opacity="0.03"/>
                    </linearGradient>
                </defs>

                <rect width="640" height="400" fill="#0a0e0a"/>

                {{-- Perspective floor: lines converging toward a high horizon. --}}
                <g stroke="url(#jg-fade)" stroke-width="1" fill="none">
                    @for ($i = 0; $i <= 12; $i++)
                        <line x1="{{ -260 + $i * 100 }}" y1="400" x2="{{ 200 + $i * 20 }}" y2="150"/>
                    @endfor
                    @for ($i = 0; $i <= 7; $i++)
                        @php $y = 150 + pow($i, 1.9) * 6.5; @endphp
                        <line x1="0" y1="{{ $y }}" x2="640" y2="{{ $y }}"/>
                    @endfor
                </g>

                @php
                    // Mirrors BeaconColor: hex plus the blink period that encodes urgency.
                    $houses = [
                        ['x' => 96,  'y' => 250, 'w' => 52, 'h' => 40, 'c' => '#39FF14', 'p' => '3s'],
                        ['x' => 196, 'y' => 286, 'w' => 62, 'h' => 48, 'c' => '#FF3B30', 'p' => '0.7s'],
                        ['x' => 312, 'y' => 244, 'w' => 48, 'h' => 36, 'c' => '#FFD60A', 'p' => '1.4s'],
                        ['x' => 404, 'y' => 300, 'w' => 66, 'h' => 50, 'c' => '#3B9DFF', 'p' => '1s'],
                        ['x' => 516, 'y' => 252, 'w' => 50, 'h' => 38, 'c' => '#B15BFF', 'p' => '2s'],
                        ['x' => 250, 'y' => 206, 'w' => 38, 'h' => 28, 'c' => null,      'p' => null],
                        ['x' => 430, 'y' => 202, 'w' => 36, 'h' => 26, 'c' => null,      'p' => null],
                    ];
                @endphp

                @foreach ($houses as $h)
                    @php
                        $lit = $h['c'] !== null;
                        $body = $lit ? '#252c2e' : '#14181a';
                        $roof = $lit ? '#39464a' : '#1c2022';
                        $apexY = $h['y'] - $h['h'] * 0.55;
                    @endphp

                    <polygon points="{{ $h['x'] - 4 }},{{ $h['y'] }} {{ $h['x'] + $h['w'] / 2 }},{{ $apexY }} {{ $h['x'] + $h['w'] + 4 }},{{ $h['y'] }}"
                             fill="{{ $roof }}"/>
                    <rect x="{{ $h['x'] }}" y="{{ $h['y'] }}" width="{{ $h['w'] }}" height="{{ $h['h'] }}" fill="{{ $body }}"/>

                    @if ($lit)
                        <circle class="jg-beacon" style="--period: {{ $h['p'] }}"
                                cx="{{ $h['x'] + $h['w'] / 2 }}" cy="{{ $apexY - 9 }}" r="7"
                                fill="{{ $h['c'] }}" opacity="0.9"/>
                        <circle cx="{{ $h['x'] + $h['w'] / 2 }}" cy="{{ $apexY - 9 }}" r="2.4" fill="#ffffff" opacity="0.85"/>
                    @endif
                @endforeach
            </svg>

            <div class="flex flex-wrap gap-x-4 gap-y-1 px-2 pt-3 pb-1 font-mono text-[11px] text-slate-400">
                <span><span class="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-[#39FF14]"></span>healthy</span>
                <span><span class="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-[#FF3B30]"></span>down</span>
                <span><span class="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-[#FFD60A]"></span>cert expiring</span>
                <span><span class="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-[#3B9DFF]"></span>deploying</span>
                <span><span class="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-[#B15BFF]"></span>docker</span>
            </div>
        </div>
    </div>
</section>

{{-- ── The problem ──────────────────────────────────────────────────────── --}}
<section class="border-y border-slate-200 bg-slate-50">
    <div class="mx-auto max-w-6xl px-6 py-16">
        <h2 class="text-2xl font-semibold tracking-tight">One box. Eleven sites. No idea.</h2>
        <p class="mt-3 max-w-2xl text-slate-600">
            Cheap hardware means everything lands on the same server, and the tools stop scaling
            long before the box does.
        </p>

        <div class="mt-10 grid gap-8 md:grid-cols-3">
            @foreach ([
                ['A certificate expires quietly', 'You find out when a customer screenshots the browser warning, not when the renewal failed three weeks earlier.'],
                ['Nothing tells you the ceiling', 'Is there room for one more site? The honest answer is usually a guess about RAM you have never measured.'],
                ['The panel can break the box', 'Most control panels want root and full write access on day one, on a server already running things you cannot afford to lose.'],
            ] as [$title, $body])
                <div>
                    <h3 class="font-medium">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $body }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ── Features ─────────────────────────────────────────────────────────── --}}
<section id="features" class="mx-auto max-w-6xl px-6 py-16">
    <h2 class="text-2xl font-semibold tracking-tight">What you get</h2>

    <div class="mt-10 grid gap-x-10 gap-y-10 md:grid-cols-2 lg:grid-cols-3">
        @foreach ([
            ['◉', 'The grid', 'Every site is a house. Rooftop strobes blink faster the more urgent the problem, so a failing site is visible from across the room.'],
            ['⛨', 'HTTPS that renews itself', 'Issuance and auto-renewal, with expiry surfaced on the grid long before anything lapses.'],
            ['▤', 'Capacity you can audit', 'Each constraint is computed from real numbers off your server, and the tightest one is reported. Every estimate shows its working.'],
            ['⛉', 'Protected sites', 'Sites already on the box are adopted read-only. JetGrid monitors them and refuses to modify them, permanently, until you unprotect one by hand.'],
            ['⌂', 'Local dev mode', 'Point it at your own machine and it finds your projects, tells you which are running, and starts or stops them — local only, never production.'],
            ['◐', 'Read-only by default', 'It ships unable to change anything. Writes are a decision you make, not a default you discover.'],
        ] as [$glyph, $title, $body])
            <div>
                <div class="flex h-9 w-9 items-center justify-center rounded-md bg-[#17800F]/10 text-[#17800F]">{{ $glyph }}</div>
                <h3 class="mt-4 font-medium">{{ $title }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $body }}</p>
            </div>
        @endforeach
    </div>
</section>

{{-- ── Pricing ──────────────────────────────────────────────────────────── --}}
<section id="pricing" class="border-t border-slate-200 bg-slate-50">
    <div class="mx-auto max-w-6xl px-6 py-16">
        <h2 class="text-2xl font-semibold tracking-tight">Pricing</h2>
        <p class="mt-3 text-slate-600">Every limit below is the one the server actually enforces.</p>

        <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @forelse ($plans as $plan)
                @php $featured = $plan->slug === 'pro'; @endphp

                <div @class([
                    'flex flex-col rounded-xl border bg-white p-6',
                    'border-[#17800F] ring-1 ring-[#17800F]' => $featured,
                    'border-slate-200' => ! $featured,
                ])>
                    @if ($featured)
                        <span class="mb-3 self-start rounded-full bg-[#17800F]/10 px-2 py-0.5 text-xs font-medium text-[#17800F]">
                            Most popular
                        </span>
                    @endif

                    <h3 class="font-semibold">{{ $plan->name }}</h3>
                    <p class="mt-2 text-2xl font-semibold tracking-tight">{{ $plan->priceLabel() }}</p>

                    <ul class="mt-5 space-y-2 text-sm text-slate-600">
                        <li>{{ $plan->max_sites === null ? 'Unlimited sites' : $plan->max_sites.' '.\Illuminate\Support\Str::plural('site', $plan->max_sites) }}</li>
                        <li>{{ $plan->max_databases === null ? 'Unlimited databases' : $plan->max_databases.' '.\Illuminate\Support\Str::plural('database', $plan->max_databases) }}</li>
                        <li>
                            @if ($plan->max_storage_mb === null)
                                Unlimited storage
                            @elseif ($plan->max_storage_mb >= 1024)
                                {{ round($plan->max_storage_mb / 1024, 1) }} GB storage
                            @else
                                {{ $plan->max_storage_mb }} MB storage
                            @endif
                        </li>
                        <li>{{ $plan->max_backups === null ? 'Unlimited backups' : $plan->max_backups.' '.\Illuminate\Support\Str::plural('backup', $plan->max_backups) }}</li>
                        <li>
                            @php $i = $plan->monitor_interval_seconds; @endphp
                            Checks every {{ $i >= 60 ? round($i / 60).' min' : $i.'s' }}
                        </li>
                    </ul>

                    <a href="{{ \Filament\Facades\Filament::getRegistrationUrl() }}"
                       @class([
                           'mt-6 rounded-md px-4 py-2 text-center text-sm font-medium',
                           'bg-[#17800F] text-white hover:bg-[#136a0c]' => $featured,
                           'border border-slate-300 hover:border-slate-400' => ! $featured,
                       ])>
                        Choose {{ $plan->name }}
                    </a>
                </div>
            @empty
                <p class="text-sm text-slate-500">
                    No plans configured yet — run <code class="rounded bg-slate-200 px-1">php artisan db:seed</code>.
                </p>
            @endforelse
        </div>
    </div>
</section>

<footer class="border-t border-slate-200">
    <div class="mx-auto flex max-w-6xl flex-col gap-4 px-6 py-10 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
        <p>&copy; {{ date('Y') }} JetGrid</p>
        <div class="flex flex-wrap gap-5">
            <a href="https://github.com/Manlikenonso1k/Jetgrid" class="hover:text-slate-900">Repository</a>
            <a href="https://github.com/Manlikenonso1k/Jetgrid#readme" class="hover:text-slate-900">Docs</a>
            <a href="{{ \Filament\Facades\Filament::getLoginUrl() }}" class="hover:text-slate-900">Log in</a>
        </div>
    </div>
</footer>

</body>
</html>
