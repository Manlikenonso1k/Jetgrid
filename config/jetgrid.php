<?php

return [

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
    'dry_run_default'  => env('JETGRID_DRY_RUN_DEFAULT', true),
    'force_dry_run'    => env('JETGRID_FORCE_DRY_RUN', false),

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

    'fixtures_path' => storage_path('app/jetgrid/fixtures'),

    /*
    |---------------------------------------------------------------------------
    | Isolation  (Safety constraint #2)
    |---------------------------------------------------------------------------
    | JetGrid only ever writes inside these roots. Any path outside is rejected
    | by PathGuard before it reaches the filesystem.
    */
    'own_user'          => env('JETGRID_USER', 'jetgrid'),
    'own_root'          => env('JETGRID_ROOT', '/opt/jetgrid'),
    'managed_sites_root'=> env('JETGRID_SITES_ROOT', '/srv/jetgrid/sites'),
    'managed_vhost_dir' => env('JETGRID_VHOST_DIR', '/etc/nginx/sites-available'),
    'managed_vhost_prefix' => 'jetgrid-',
    'config_backup_dir' => env('JETGRID_CONFIG_BACKUP_DIR', '/var/backups/jetgrid/configs'),

    /*
    |---------------------------------------------------------------------------
    | Discovery  (Safety constraint #1 — READ ONLY)
    |---------------------------------------------------------------------------
    */
    'discovery' => [
        'nginx_dirs'      => ['/etc/nginx/sites-enabled', '/etc/nginx/conf.d'],
        'apache_dirs'     => ['/etc/apache2/sites-enabled'],
        'systemd_dirs'    => ['/etc/systemd/system'],
        'supervisor_dirs' => ['/etc/supervisor/conf.d'],
        'phpfpm_glob'     => '/etc/php/*/fpm/pool.d',
        'webroots'        => ['/var/www', '/srv/www', '/home'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Capacity model  (Feature 2) — all overridable, none guessed silently
    |---------------------------------------------------------------------------
    */
    'capacity' => [
        'os_reserve_mb'          => env('JETGRID_OS_RESERVE_MB', 512),
        'db_buffer_pool_mb'      => env('JETGRID_DB_BUFFER_MB', null), // null => read from server
        'small_site_ram_mb'      => env('JETGRID_SMALL_SITE_RAM_MB', 192),
        'small_site_disk_mb'     => env('JETGRID_SMALL_SITE_DISK_MB', 1024),
        'small_site_cpu_credits' => env('JETGRID_SMALL_SITE_CREDITS', 1.5), // credits/hour
        'disk_reserve_pct'       => 15,
    ],

    /*
    |---------------------------------------------------------------------------
    | Certificates  (Feature 3)
    |---------------------------------------------------------------------------
    */
    'certificates' => [
        'renew_days_before'      => 30,
        'warn_days_before'       => 14,
        'max_renewal_attempts'   => 5,
        'le_duplicate_limit'     => 5,      // Let's Encrypt: 5 duplicate certs / week
        'le_duplicate_window_h'  => 168,
        'certbot_bin'            => '/usr/bin/certbot',
        'webroot'                => '/var/www/letsencrypt',
    ],

    /*
    |---------------------------------------------------------------------------
    | Monitoring / dashboard data pipeline (polling, not websockets — see docs)
    |---------------------------------------------------------------------------
    */
    'poll' => [
        'idle_ms'   => 15000,
        'active_ms' => 3000,   // used while a deployment is in progress
    ],

    'health' => [
        'weights' => [
            'uptime'          => 0.35,
            'certificate'     => 0.25,
            'updates'         => 0.15,
            'error_rate'      => 0.15,
            'backup_freshness'=> 0.10,
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
    'instance_id'         => env('JETGRID_INSTANCE_ID'),
    'instance_type'       => env('JETGRID_INSTANCE_TYPE'),
    'cloudwatch_enabled'  => env('JETGRID_CLOUDWATCH', false),

    'pricing' => [
        // (b) seeded JSON with a visible last-updated date + admin refresh action.
        'source'     => 'seeded-json',
        'file'       => database_path('data/ec2-pricing.json'),
        'region'     => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'api_enabled'=> env('JETGRID_PRICING_API', false),
    ],
];
