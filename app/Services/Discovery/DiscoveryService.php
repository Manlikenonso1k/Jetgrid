<?php

namespace App\Services\Discovery;

use App\Enums\BeaconColor;
use App\Enums\ManagementMode;
use App\Enums\SiteStatus;
use App\Models\CronJob;
use App\Models\ProtectedResource;
use App\Models\Site;
use App\Services\Privilege\CommandRunner;
use App\Services\Server\ServerDriver;
use Illuminate\Support\Str;

/**
 * Safety constraint #1: READ-ONLY discovery.
 *
 * This class reads the server and writes rows to JetGrid's own database. It
 * never writes to the server, and the only commands it runs are the ones the
 * registry marks isWrite=false — so it works unchanged with JETGRID_READONLY=true,
 * which is the whole point.
 *
 * Everything it imports is created as "Adopted — Protected". Re-running is safe:
 * existing sites are touched only on their monitoring columns (see
 * Site::MONITORING_FIELDS), never re-imported or reset.
 */
class DiscoveryService
{
    public function __construct(
        private readonly ServerDriver $driver,
        private readonly CommandRunner $runner,
        private readonly NginxVhostParser $nginxParser,
    ) {
    }

    public function run(): DiscoveryReport
    {
        $report = new DiscoveryReport();

        $this->discoverNginx($report);
        $this->discoverApache($report);
        $this->discoverSystemd($report);
        $this->discoverSupervisor($report);
        $this->discoverPhpFpmPools($report);
        $this->discoverCron($report);
        $this->discoverWebroots($report);

        return $report;
    }

    // ---- nginx ------------------------------------------------------------

    private function discoverNginx(DiscoveryReport $report): void
    {
        foreach ((array) config('jetgrid.discovery.nginx_dirs') as $dir) {
            foreach ($this->driver->listDirectory($dir) as $path) {
                $contents = $this->driver->readFile($path);

                if ($contents === null) {
                    $report->unreadable[] = $path;

                    continue;
                }

                $servers = $this->nginxParser->parse($contents);

                if ($servers === []) {
                    continue;
                }

                $resource = $this->rememberResource(
                    report: $report,
                    type: 'nginx_vhost',
                    name: basename($path),
                    path: $path,
                    contents: $contents,
                    parsed: $servers,
                    detail: count($servers).' server block(s)',
                );

                foreach ($servers as $server) {
                    if ($server['server_names'] === []) {
                        continue;
                    }

                    // The first server_name is the site; the rest are aliases of
                    // it. Treating every name as its own site would put five
                    // houses on the grid for three projects.
                    [$primary, $aliases] = [
                        $server['server_names'][0],
                        array_slice($server['server_names'], 1),
                    ];

                    $site = $this->adoptSite(
                        report: $report,
                        domain: $primary,
                        documentRoot: $server['root'],
                        vhostPath: $path,
                        aliases: $aliases,
                        meta: [
                            'listen' => $server['listen'],
                            'ssl_certificate' => $server['ssl_certificate'],
                            'discovered_from' => 'nginx',
                        ],
                    );

                    if ($site !== null && $resource->site_id === null) {
                        $resource->forceFill(['site_id' => $site->id])->save();
                    }
                }
            }
        }
    }

    private function discoverApache(DiscoveryReport $report): void
    {
        foreach ((array) config('jetgrid.discovery.apache_dirs') as $dir) {
            foreach ($this->driver->listDirectory($dir) as $path) {
                $contents = $this->driver->readFile($path);

                if ($contents === null) {
                    continue;
                }

                preg_match_all('/^\s*(?:ServerName|ServerAlias)\s+(\S+)/mi', $contents, $names);
                preg_match('/^\s*DocumentRoot\s+"?([^"\s]+)"?/mi', $contents, $root);

                if ($names[1] === []) {
                    continue;
                }

                $this->rememberResource(
                    $report, 'apache_vhost', basename($path), $path, $contents,
                    ['server_names' => $names[1]], implode(', ', $names[1]),
                );

                foreach (array_unique($names[1]) as $domain) {
                    $this->adoptSite($report, $domain, $root[1] ?? null, $path, ['discovered_from' => 'apache']);
                }
            }
        }
    }

    // ---- systemd / supervisor --------------------------------------------

    private function discoverSystemd(DiscoveryReport $report): void
    {
        foreach ((array) config('jetgrid.discovery.systemd_dirs') as $dir) {
            foreach ($this->driver->listDirectory($dir) as $path) {
                if (! str_ends_with($path, '.service') && ! str_ends_with($path, '.timer')) {
                    continue;
                }

                $contents = $this->driver->readFile($path);

                if ($contents === null) {
                    continue;
                }

                preg_match('/^\s*ExecStart\s*=\s*(.+)$/mi', $contents, $exec);
                preg_match('/^\s*Description\s*=\s*(.+)$/mi', $contents, $desc);

                $this->rememberResource(
                    $report, 'systemd_unit', basename($path), $path, $contents,
                    ['exec_start' => trim($exec[1] ?? ''), 'description' => trim($desc[1] ?? '')],
                    trim($desc[1] ?? basename($path)),
                );
            }
        }
    }

    private function discoverSupervisor(DiscoveryReport $report): void
    {
        foreach ((array) config('jetgrid.discovery.supervisor_dirs') as $dir) {
            foreach ($this->driver->listDirectory($dir) as $path) {
                $contents = $this->driver->readFile($path);

                if ($contents === null) {
                    continue;
                }

                preg_match_all('/^\[program:([^\]]+)\]/mi', $contents, $programs);

                foreach ($programs[1] as $program) {
                    $this->rememberResource(
                        $report, 'supervisor_program', $program, $path, $contents,
                        ['program' => $program], 'supervisor program',
                    );
                }
            }
        }
    }

    private function discoverPhpFpmPools(DiscoveryReport $report): void
    {
        $glob = (string) config('jetgrid.discovery.phpfpm_glob');

        // The driver has no glob, so expand the single wildcard by listing the
        // parent. Keeps the fake driver and the real one on identical code.
        [$parent, $suffix] = $this->splitGlob($glob);

        foreach ($this->driver->listDirectory($parent) as $versionDir) {
            $poolDir = rtrim($versionDir, '/').$suffix;

            foreach ($this->driver->listDirectory($poolDir) as $path) {
                if (! str_ends_with($path, '.conf')) {
                    continue;
                }

                $contents = $this->driver->readFile($path);

                if ($contents === null) {
                    continue;
                }

                preg_match('/^\[([^\]]+)\]/m', $contents, $pool);
                preg_match('/^\s*pm\.max_children\s*=\s*(\d+)/mi', $contents, $children);
                preg_match('/^\s*user\s*=\s*(\S+)/mi', $contents, $user);

                $this->rememberResource(
                    $report, 'php_fpm_pool', $pool[1] ?? basename($path), $path, $contents,
                    [
                        'max_children' => (int) ($children[1] ?? 0),
                        'user' => $user[1] ?? null,
                        'php_version' => $this->phpVersionFromPath($path),
                    ],
                    'max_children='.($children[1] ?? '?'),
                );
            }
        }
    }

    private function discoverCron(DiscoveryReport $report): void
    {
        $outcome = $this->runner->run('crontab.list', ['user' => 'root']);

        if (! $outcome->ok()) {
            return;
        }

        foreach (preg_split('/\r?\n/', $outcome->result->stdout) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^((?:\S+\s+){5})(.+)$/', $line, $m) !== 1) {
                continue;
            }

            CronJob::firstOrCreate(
                ['command' => trim($m[2]), 'run_as' => 'root'],
                [
                    'schedule' => trim($m[1]),
                    // Everything discovery finds is locked (Feature 10).
                    'is_protected' => true,
                    'source' => 'discovery',
                ],
            );

            $report->record('cron', trim($m[2]), null, trim($m[1]));
        }
    }

    private function discoverWebroots(DiscoveryReport $report): void
    {
        foreach ((array) config('jetgrid.discovery.webroots') as $root) {
            foreach ($this->driver->listDirectory($root) as $path) {
                $name = basename($path);

                if (in_array($name, ['html', 'letsencrypt', 'lost+found'], true)) {
                    continue;
                }

                $this->rememberResource(
                    $report, 'directory', $name, $path, null,
                    ['path' => $path], 'site directory',
                );
            }
        }
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * Insert or refresh a protected record.
     *
     * Refreshing only ever touches ProtectedResource::OBSERVATION_FIELDS, and a
     * changed fingerprint is reported as drift rather than silently overwritten:
     * if a file moved underneath us, that is news, not something to reconcile.
     */
    private function rememberResource(
        DiscoveryReport $report,
        string $type,
        string $name,
        ?string $path,
        ?string $contents,
        array $parsed,
        string $detail,
    ): ProtectedResource {
        $fingerprint = $contents !== null ? hash('sha256', $contents) : null;

        $resource = ProtectedResource::firstOrNew(['type' => $type, 'name' => $name]);

        if ($resource->exists && $resource->hasDrifted($fingerprint)) {
            $report->drifted[] = ['name' => $name, 'path' => $path];
        }

        $resource->fill([
            'path' => $path,
            'fingerprint' => $fingerprint,
            'excerpt' => $contents !== null ? Str::limit($contents, 1200) : null,
            'parsed' => $parsed,
            'last_seen_at' => now(),
        ]);

        if (! $resource->exists) {
            $resource->discovered_at = now();
        }

        $resource->save();

        $report->record($type, $name, $path, $detail);

        return $resource;
    }

    /**
     * Import a site as Adopted — Protected.
     *
     * An existing record is never re-imported: if the site is already known,
     * only its monitoring columns are refreshed, so a site that has been
     * deliberately un-protected does not silently get re-protected by the next
     * discovery run.
     */
    private function adoptSite(
        DiscoveryReport $report,
        string $domain,
        ?string $documentRoot,
        ?string $vhostPath,
        array $aliases = [],
        array $meta = [],
    ): ?Site {
        $domain = $this->normaliseDomain($domain);

        if ($domain === null) {
            return null;
        }

        $aliases = array_values(array_filter(array_map($this->normaliseDomain(...), $aliases)));

        $site = Site::firstWhere('domain', $domain);

        if ($site !== null) {
            $report->sitesSeenAgain[] = $domain;

            // A site often appears twice — once for the :80 redirect and once
            // for the real :443 block. Only the block that actually has a root
            // should get to define it, so nothing is overwritten with null.
            $site->fill(array_filter([
                'document_root' => $documentRoot,
                'vhost_path' => $vhostPath,
            ]));

            $site->meta = array_merge($site->meta ?? [], [
                'aliases' => array_values(array_unique(array_merge(
                    $site->meta['aliases'] ?? [], $aliases
                ))),
            ]);

            $site->save();

            return $site;
        }

        $site = Site::create([
            'domain' => $domain,
            'display_name' => $domain,
            'management_mode' => ManagementMode::AdoptedProtected,
            'is_protected' => true,
            'document_root' => $documentRoot,
            'vhost_path' => $vhostPath,
            'status' => SiteStatus::Unknown,
            'beacon_color' => BeaconColor::Grey,
            'source' => 'discovery',
            'discovered_at' => now(),
            'meta' => array_merge($meta, ['aliases' => $aliases]),
        ]);

        $report->sitesCreated[] = $domain;

        return $site;
    }

    /** Null for anything that is not an addressable host name. */
    private function normaliseDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B;"));

        if ($domain === '' || str_starts_with($domain, '~') || str_contains($domain, '*')) {
            return null;
        }

        return $domain;
    }

    /** @return array{0:string,1:string} */
    private function splitGlob(string $glob): array
    {
        $star = strpos($glob, '*');

        if ($star === false) {
            return [$glob, ''];
        }

        $parent = rtrim(substr($glob, 0, $star), '/');
        $suffix = substr($glob, $star + 1);

        return [$parent, $suffix];
    }

    private function phpVersionFromPath(string $path): ?string
    {
        return preg_match('#/php/(\d+\.\d+)/#', $path, $m) === 1 ? $m[1] : null;
    }
}
