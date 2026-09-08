<?php

namespace Tests\Feature;

use App\Models\LocalProject;
use App\Services\Local\DiscoveredProject;
use App\Services\Local\LocalProjectImporter;
use App\Services\Local\ProjectScanner;
use App\Support\CanonicalPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\UsesLocalFixtures;
use Tests\TestCase;

/**
 * L1 and L2. The walk itself: what it finds, what it refuses to find, and the
 * guarantee that it changes nothing.
 *
 * The fixture root is used as the scan root so the test does not depend on what
 * happens to be sitting next to the checkout.
 */
class LocalScanTest extends TestCase
{
    use RefreshDatabase, UsesLocalFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableLocalMode();

        config([
            'jetgrid.local.scan.extra_roots' => [$this->fixture()],
            'jetgrid.local.scan.cache_ttl' => 0,
        ]);
    }

    private function scan(): array
    {
        $report = app(ProjectScanner::class)->scan(fresh: true);

        return collect($report->projects)
            ->mapWithKeys(fn (DiscoveredProject $p): array => [basename($p->path) => $p])
            ->all();
    }

    public function test_it_finds_every_project_under_a_root(): void
    {
        $found = $this->scan();

        foreach (['laravel-app', 'nextjs-app', 'django-app', 'go-service', 'static-site'] as $expected) {
            $this->assertArrayHasKey($expected, $found);
        }
    }

    public function test_a_folder_with_no_marker_is_not_imported(): void
    {
        $this->assertArrayNotHasKey('just-notes', $this->scan());
    }

    /** L2, stated explicitly: JetGrid never appears in its own results. */
    public function test_jetgrid_excludes_itself(): void
    {
        config(['jetgrid.local.scan.extra_roots' => [dirname(base_path())]]);

        $paths = array_map(
            static fn (DiscoveredProject $p): string => CanonicalPath::comparable($p->path),
            app(ProjectScanner::class)->scan(fresh: true)->projects,
        );

        $this->assertNotContains(CanonicalPath::comparable(base_path()), $paths);
    }

    /** The default root is derived from base_path, never configured. */
    public function test_the_parent_of_base_path_is_always_a_root(): void
    {
        $roots = array_map(
            static fn (string $r): string => CanonicalPath::comparable($r),
            app(ProjectScanner::class)->roots(),
        );

        $this->assertContains(CanonicalPath::comparable(dirname(base_path())), $roots);
    }

    /**
     * Once a project is identified its subtree is not descended, so the vendor
     * and node_modules directories inside the fixtures never become results.
     */
    public function test_a_projects_subtree_is_not_walked(): void
    {
        $found = $this->scan();

        $this->assertArrayNotHasKey('vendor', $found);
        $this->assertArrayNotHasKey('node_modules', $found);
    }

    public function test_depth_is_limited(): void
    {
        config(['jetgrid.local.scan.max_depth' => 1]);

        // At depth 1 the fixture root's own children are still found...
        $this->assertArrayHasKey('laravel-app', $this->scan());

        // ...but a project one level further down is not.
        config([
            'jetgrid.local.scan.max_depth' => 1,
            'jetgrid.local.scan.extra_roots' => [dirname($this->fixture())],
        ]);

        $this->assertArrayNotHasKey('laravel-app', $this->scan());
    }

    /** L1: an unreadable or unresolvable entry is collected, never fatal. */
    public function test_the_scan_survives_a_root_that_does_not_exist(): void
    {
        $missing = base_path('no-such-directory-'.uniqid());

        config(['jetgrid.local.scan.extra_roots' => [$this->fixture(), $missing]]);

        $report = app(ProjectScanner::class)->scan(fresh: true);

        $this->assertGreaterThan(0, $report->total(), 'A bad root took the whole scan down with it.');
        $this->assertNotContains($missing, $report->roots, 'A root that does not exist should be dropped, not scanned.');
    }

    /** SAFETY: discovery is read-only. Nothing under a scanned root may change. */
    public function test_a_scan_does_not_modify_a_single_byte(): void
    {
        $before = $this->treeHashes($this->fixture());

        app(LocalProjectImporter::class)->import(app(ProjectScanner::class)->scan(fresh: true), withRepoInfo: false);

        $this->assertSame($before, $this->treeHashes($this->fixture()), 'The scan wrote inside a discovered project.');
    }

    public function test_the_walk_is_cached_and_rescan_bypasses_the_cache(): void
    {
        config(['jetgrid.local.scan.cache_ttl' => 300]);
        Cache::flush();

        $scanner = app(ProjectScanner::class);

        $this->assertFalse($scanner->scan()->fromCache);
        $this->assertTrue($scanner->scan()->fromCache);
        $this->assertFalse($scanner->scan(fresh: true)->fromCache, 'Rescan must re-walk the tree.');
    }

    public function test_importing_twice_does_not_duplicate_a_project(): void
    {
        $importer = app(LocalProjectImporter::class);
        $scanner = app(ProjectScanner::class);

        $importer->import($scanner->scan(fresh: true), withRepoInfo: false);
        $first = LocalProject::count();

        $importer->import($scanner->scan(fresh: true), withRepoInfo: false);

        $this->assertSame($first, LocalProject::count());
    }

    /** The operator's decisions survive a rescan; JetGrid's observations do not. */
    public function test_a_rescan_keeps_a_port_override(): void
    {
        $importer = app(LocalProjectImporter::class);
        $scanner = app(ProjectScanner::class);

        $importer->import($scanner->scan(fresh: true), withRepoInfo: false);

        $project = LocalProject::where('name', 'laravel-app')->firstOrFail();
        $project->update(['port_override' => 9999, 'grid_x' => 4, 'grid_z' => 7]);

        $importer->import($scanner->scan(fresh: true), withRepoInfo: false);

        $project->refresh();

        $this->assertSame(9999, $project->port_override);
        $this->assertSame(9999, $project->effectivePort());
        $this->assertSame(4, $project->grid_x);
    }

    public function test_the_dry_run_command_writes_nothing_to_the_database(): void
    {
        $this->artisan('jetgrid:scan --dry-run --fresh --no-probe')
            ->expectsOutputToContain('nothing was written to the database')
            ->assertSuccessful();

        $this->assertSame(0, LocalProject::count());
    }

    public function test_the_scan_command_imports_when_not_dry_running(): void
    {
        $this->artisan('jetgrid:scan --fresh --no-probe')->assertSuccessful();

        $this->assertGreaterThan(0, LocalProject::count());
        $this->assertDatabaseHas('local_projects', ['name' => 'laravel-app', 'type' => 'laravel']);
    }
}
