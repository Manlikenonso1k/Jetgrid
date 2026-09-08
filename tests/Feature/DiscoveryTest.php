<?php

namespace Tests\Feature;

use App\Enums\BeaconColor;
use App\Enums\ManagementMode;
use App\Models\CronJob;
use App\Models\ProtectedResource;
use App\Models\Site;
use App\Services\Discovery\DiscoveryService;
use App\Support\KillSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Safety constraint #1 — discovery is READ-ONLY.
 *
 * The strongest assertion here is the fixture-hash one: it fingerprints the
 * entire simulated server before the scan and re-checks it afterwards, so any
 * write of any kind to any discovered file fails the test.
 */
class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixtureHashes(): array
    {
        $hashes = [];

        foreach (File::allFiles(config('jetgrid.fixtures_path')) as $file) {
            $hashes[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }

        ksort($hashes);

        return $hashes;
    }

    public function test_discovery_does_not_modify_a_single_byte_of_the_server(): void
    {
        $before = $this->fixtureHashes();

        app(DiscoveryService::class)->run();

        $this->assertSame($before, $this->fixtureHashes(), 'Discovery modified the server. It must be read-only.');
    }

    public function test_discovery_works_while_read_only(): void
    {
        // The kill switch is what actually decides, and it casts defensively;
        // assert on it rather than on the raw config value's type.
        $this->assertTrue(app(KillSwitch::class)->isReadOnly());

        $report = app(DiscoveryService::class)->run();

        $this->assertGreaterThan(0, $report->total());
    }

    public function test_every_discovered_site_is_imported_as_adopted_and_protected(): void
    {
        app(DiscoveryService::class)->run();

        $this->assertGreaterThan(0, Site::count());

        foreach (Site::all() as $site) {
            $this->assertTrue($site->is_protected, "{$site->domain} was not protected on import.");
            $this->assertSame(ManagementMode::AdoptedProtected, $site->management_mode);
            $this->assertSame(BeaconColor::Grey, $site->beacon_color);
            $this->assertSame('discovery', $site->source);
        }
    }

    public function test_the_three_live_projects_are_found_once_each(): void
    {
        app(DiscoveryService::class)->run();

        // Aliases must not become their own houses.
        $this->assertSame(3, Site::count());

        foreach (['iceland.example.com', 'moritho.example.com', 'oceanova.example.com'] as $domain) {
            $this->assertDatabaseHas('sites', ['domain' => $domain, 'is_protected' => true]);
        }

        $this->assertNull(Site::firstWhere('domain', 'www.iceland.example.com'));

        $iceland = Site::firstWhere('domain', 'iceland.example.com');
        $this->assertContains('www.iceland.example.com', $iceland->meta['aliases']);
    }

    public function test_it_finds_units_pools_supervisor_programs_and_crons(): void
    {
        app(DiscoveryService::class)->run();

        $this->assertDatabaseHas('protected_resources', ['type' => 'systemd_unit', 'name' => 'moritho-worker.service']);
        $this->assertDatabaseHas('protected_resources', ['type' => 'php_fpm_pool', 'name' => 'iceland']);
        $this->assertDatabaseHas('protected_resources', ['type' => 'supervisor_program', 'name' => 'iceland-horizon']);

        $this->assertSame(3, CronJob::count());
        $this->assertTrue(CronJob::query()->get()->every(fn (CronJob $c) => $c->is_protected));
    }

    public function test_the_document_root_survives_a_redirect_only_server_block(): void
    {
        app(DiscoveryService::class)->run();

        // iceland.conf has a :80 redirect block with no root, then the real
        // :443 block. The rootless one must not blank out the real value.
        $this->assertSame(
            '/var/www/iceland/public',
            Site::firstWhere('domain', 'iceland.example.com')->document_root,
        );
    }

    public function test_running_discovery_twice_is_idempotent(): void
    {
        app(DiscoveryService::class)->run();

        $sites = Site::count();
        $resources = ProtectedResource::count();

        $second = app(DiscoveryService::class)->run();

        $this->assertSame($sites, Site::count());
        $this->assertSame($resources, ProtectedResource::count());
        $this->assertSame([], $second->sitesCreated);
    }

    public function test_rediscovery_does_not_re_protect_a_site_you_deliberately_adopted(): void
    {
        app(DiscoveryService::class)->run();

        $site = Site::firstWhere('domain', 'moritho.example.com');
        $site->unprotect();

        app(DiscoveryService::class)->run();

        $this->assertFalse(
            $site->fresh()->is_protected,
            'A deliberately un-protected site was silently re-protected by rediscovery.',
        );
    }
}
