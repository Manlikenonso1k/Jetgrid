<?php

return [

    /*
    |---------------------------------------------------------------------------
    | MODE  (Local module, L0)
    |---------------------------------------------------------------------------
    | 'production' is the default because the failure mode of guessing wrong is
    | JetGrid starting and stopping processes on a live server. Setting this to
    | 'local' is only the FIRST of four independent checks — see
    | App\Services\Local\LocalModeGate.
    */
    'mode' => env('JETGRID_MODE', 'production'),

    /*
    |---------------------------------------------------------------------------
    | KILL SWITCH  (Safety constraint #8)
    |---------------------------------------------------------------------------
    | When true, EVERY privileged write operation is refused at the CommandRunner
    | level. Defaults to TRUE so a fresh install can never write on day one.
    | Only a god_mode user may flip this, and only via the Filament UI.
    */
    'readonly' => env('JETGRID_READONLY', true),

    /*
    | With JETGRID_READONLY=true, the in-app switch is inert unless this is also
    | set. It exists so that "read-only" set over SSH cannot be undone from a
    | hijacked browser session. See App\Support\KillSwitch.
    */
    'allow_runtime_unlock' => env('JETGRID_ALLOW_RUNTIME_UNLOCK', false),

    /*
    |---------------------------------------------------------------------------
    | DRY RUN  (Safety constraint #6)
    |---------------------------------------------------------------------------
    | When true, privileged commands are rendered and audited but never executed.
    | 'force_dry_run' makes dry-run non-overridable regardless of UI choice.
    */
    'dry_run_default' => env('JETGRID_DRY_RUN_DEFAULT', true),
    'force_dry_run' => env('JETGRID_FORCE_DRY_RUN', false),

    /*
    |---------------------------------------------------------------------------
    | Server driver
    |---------------------------------------------------------------------------
    | 'linux' talks to a real host via Symfony Process.
    | 'fake'  reads fixtures from storage/app/jetgrid/fixtures — used for local
    |         development on machines with no nginx/systemd (e.g. Windows) and in
    |         tests. The fake driver CANNOT execute anything.
    */
    'driver' => env('JETGRID_DRIVER', 'fake'),

    // Source data, not runtime state: the fixtures are the simulated machine
    // FakeServerDriver reads, so they live in the repo rather than under
    // storage/ (which is gitignored and wiped on deploy).
    'fixtures_path' => base_path('fixtures'),

    /*
    |---------------------------------------------------------------------------
    | Isolation  (Safety constraint #2)
    |---------------------------------------------------------------------------
    | JetGrid only ever writes inside these roots. Any path outside is rejected
    | by PathGuard before it reaches the filesystem.
    */
    'own_user' => env('JETGRID_USER', 'jetgrid'),
    'own_root' => env('JETGRID_ROOT', '/opt/jetgrid'),
    'managed_sites_root' => env('JETGRID_SITES_ROOT', '/srv/jetgrid/sites'),
    'managed_vhost_dir' => env('JETGRID_VHOST_DIR', '/etc/nginx/sites-available'),
    'managed_vhost_prefix' => 'jetgrid-',
    'config_backup_dir' => env('JETGRID_CONFIG_BACKUP_DIR', '/var/backups/jetgrid/configs'),

    /*
    |---------------------------------------------------------------------------
    | Discovery  (Safety constraint #1 — READ ONLY)
    |---------------------------------------------------------------------------
    */
    'discovery' => [
        'nginx_dirs' => ['/etc/nginx/sites-enabled', '/etc/nginx/conf.d'],
        'apache_dirs' => ['/etc/apache2/sites-enabled'],
        'systemd_dirs' => ['/etc/systemd/system'],
        'supervisor_dirs' => ['/etc/supervisor/conf.d'],
        'phpfpm_glob' => '/etc/php/*/fpm/pool.d',
        'webroots' => ['/var/www', '/srv/www', '/home'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Capacity model  (Feature 2) — all overridable, none guessed silently
    |---------------------------------------------------------------------------
    */
    'capacity' => [
        'os_reserve_mb' => env('JETGRID_OS_RESERVE_MB', 512),
        'db_buffer_pool_mb' => env('JETGRID_DB_BUFFER_MB', null), // null => read from server
        'small_site_ram_mb' => env('JETGRID_SMALL_SITE_RAM_MB', 192),
        'small_site_disk_mb' => env('JETGRID_SMALL_SITE_DISK_MB', 1024),
        'small_site_cpu_credits' => env('JETGRID_SMALL_SITE_CREDITS', 1.5), // credits/hour
        'disk_reserve_pct' => 15,
    ],

    /*
    |---------------------------------------------------------------------------
    | Certificates  (Feature 3)
    |---------------------------------------------------------------------------
    */
    'certificates' => [
        'renew_days_before' => 30,
        'warn_days_before' => 14,
        'max_renewal_attempts' => 5,
        'le_duplicate_limit' => 5,      // Let's Encrypt: 5 duplicate certs / week
        'le_duplicate_window_h' => 168,
        'certbot_bin' => '/usr/bin/certbot',
        'webroot' => '/var/www/letsencrypt',
    ],

    /*
    |---------------------------------------------------------------------------
    | Monitoring / dashboard data pipeline (polling, not websockets — see docs)
    |---------------------------------------------------------------------------
    */
    'poll' => [
        'idle_ms' => 15000,
        'active_ms' => 3000,   // used while a deployment is in progress
    ],

    'health' => [
        'weights' => [
            'uptime' => 0.35,
            'certificate' => 0.25,
            'updates' => 0.15,
            'error_rate' => 0.15,
            'backup_freshness' => 0.10,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | This instance
    |---------------------------------------------------------------------------
    | Needed for CPU credit tracking and for highlighting the current row in the
    | instance comparison chart. Left null, JetGrid says "unknown" rather than
    | guessing which instance it is running on.
    */
    'instance_id' => env('JETGRID_INSTANCE_ID'),
    'instance_type' => env('JETGRID_INSTANCE_TYPE'),
    'cloudwatch_enabled' => env('JETGRID_CLOUDWATCH', false),

    /*
    |---------------------------------------------------------------------------
    | LOCAL PROJECT DISCOVERY & CONTROL  (L0-L9)
    |---------------------------------------------------------------------------
    | Everything under here is inert unless LocalModeGate passes all four of its
    | checks. Nothing in this block can be reached on a production install.
    */
    'local' => [

        // L0 check 3. Touching any of these files on a server permanently
        // disables local mode there, with no deploy and no config change.
        'production_markers' => [
            '/etc/jetgrid/production.lock',
            '/etc/jetgrid/production',
            'C:\ProgramData\JetGrid\production.lock',
        ],

        // L0 check 4. The cloud metadata probe is a 150ms TCP connect to the
        // link-local address; disable it on a workstation whose network stack
        // makes even that slow.
        'metadata_probe' => env('JETGRID_LOCAL_METADATA_PROBE', true),
        'metadata_endpoint' => ['169.254.169.254', 80],

        'scan' => [
            // L2. The default root is derived, not configured: dirname(base_path()).
            // Extra roots are additive and each is toggleable in the UI.
            'extra_roots' => array_values(array_filter(
                explode(',', (string) env('JETGRID_LOCAL_ROOTS', ''))
            )),
            'max_depth' => (int) env('JETGRID_LOCAL_MAX_DEPTH', 2),

            // Never descended into. These are where the walk would otherwise
            // spend all of its time and find nothing.
            'skip' => [
                'node_modules', 'vendor', '.git', '.idea', '.vscode', 'dist',
                'build', 'storage', 'bootstrap/cache', '__pycache__', 'target',
                '.next', '.nuxt', 'Pods',
            ],

            // L2: incremental + cached. The walk is the expensive part, so its
            // result is cached and the dashboard reads the database instead.
            'cache_ttl' => (int) env('JETGRID_LOCAL_CACHE_TTL', 300),
        ],

        'probe' => [
            'tcp_timeout_ms' => 200,      // L5 layer 1
            'http_timeout_ms' => 1500,    // L5 layer 2
            'command_timeout' => 5,       // seconds, L5 layer 3 + 4
            'poll_ms' => (int) env('JETGRID_LOCAL_POLL_MS', 5000),
        ],

        // Framework defaults, used only when nothing more specific is readable.
        'default_ports' => [
            'laravel' => 8000,
            'symfony' => 8000,
            'wordpress' => 8080,
            'php' => 8000,
            'nextjs' => 3000,
            'nuxt' => 3000,
            'vite' => 5173,
            'node' => 3000,
            'django' => 8000,
            'flask' => 5000,
            'rails' => 3000,
            'go' => 8080,
            'rust' => 8080,
            'static' => 8000,
        ],

        // L6. JetGrid's OWN storage — never a discovered project's directory.
        'pid_dir' => storage_path('app'.DIRECTORY_SEPARATOR.'jetgrid'.DIRECTORY_SEPARATOR.'pids'),
        'log_dir' => storage_path('app'.DIRECTORY_SEPARATOR.'jetgrid'.DIRECTORY_SEPARATOR.'logs'),

        // Optional: pick the next free port when the preferred one is taken.
        'auto_port' => env('JETGRID_LOCAL_AUTO_PORT', false),
        'auto_port_range' => 40,

        // How long a spawned process may sit in "starting" before the port is
        // expected to be listening.
        'start_grace_seconds' => 30,
    ],

    'pricing' => [
        // (b) seeded JSON with a visible last-updated date + admin refresh action.
        'source' => 'seeded-json',
        'file' => database_path('data/ec2-pricing.json'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'api_enabled' => env('JETGRID_PRICING_API', false),
    ],
];
