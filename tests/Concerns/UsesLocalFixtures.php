<?php

namespace Tests\Concerns;

use App\Services\Local\LocalModeGate;
use App\Services\Local\Platform\PlatformDetector;

trait UsesLocalFixtures
{
    protected function fixture(string $name = ''): string
    {
        $base = base_path('tests'.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'local-projects');

        return $name === '' ? $base : $base.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * Open the L0 gate for the duration of a test.
     *
     * The suite runs with APP_ENV=testing, which fails check 2 by design, so a
     * test that needs local mode has to say so explicitly. The detector is
     * forgotten first because it memoises its workstation verdict — including a
     * network probe that has no business running in a test.
     */
    protected function enableLocalMode(): void
    {
        config([
            'jetgrid.mode' => 'local',
            'jetgrid.local.metadata_probe' => false,
            'jetgrid.local.production_markers' => [],
            // Timeouts, not behaviour: the suite probes ports that are open but
            // silent, and waiting the production 1.5s for each one adds up.
            'jetgrid.local.probe.http_timeout_ms' => 300,
        ]);

        $this->app['env'] = 'local';
        $this->app->forgetInstance(PlatformDetector::class);
        $this->app->forgetInstance(LocalModeGate::class);
    }

    /** Fingerprint a directory tree, to prove a scan did not write to it. */
    protected function treeHashes(string $directory): array
    {
        $hashes = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $hashes[$file->getPathname()] = hash_file('sha256', $file->getPathname());
            }
        }

        ksort($hashes);

        return $hashes;
    }
}
