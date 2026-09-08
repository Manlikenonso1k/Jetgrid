<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\Capacity\CapacityEstimator;
use App\Services\Discovery\DiscoveryService;
use App\Services\Metrics\MetricsCollector;
use App\Services\Metrics\ServerFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GridDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_grid_endpoint_requires_authentication(): void
    {
        $this->getJson('/jetgrid/api/grid')->assertUnauthorized();
    }

    public function test_it_returns_one_house_per_site_with_a_stable_position(): void
    {
        app(DiscoveryService::class)->run();
        $this->actingAs(User::factory()->create(['role' => Role::Viewer]));

        $first = $this->getJson('/jetgrid/api/grid')->assertOk()->json();

        $this->assertCount(3, $first['houses']);

        $positions = collect($first['houses'])->mapWithKeys(fn ($h) => [$h['domain'] => [$h['x'], $h['z']]]);

        // Houses that move between polls make the map unreadable.
        $this->travel(1)->seconds();
        cache()->forget('jetgrid.grid');
        $second = $this->getJson('/jetgrid/api/grid')->assertOk()->json();

        foreach ($second['houses'] as $house) {
            $this->assertSame($positions[$house['domain']], [$house['x'], $house['z']]);
        }
    }

    public function test_adopted_houses_are_grey_and_expose_no_actions(): void
    {
        app(DiscoveryService::class)->run();
        $this->actingAs(User::factory()->create(['role' => Role::GodMode]));

        $payload = $this->getJson('/jetgrid/api/grid')->assertOk()->json();

        foreach ($payload['houses'] as $house) {
            $this->assertTrue($house['protected']);
            $this->assertSame('grey', $house['beacon']);
        }

        $site = \App\Models\Site::firstWhere('domain', 'iceland.example.com');

        // Safety constraint #1: even for god_mode, the detail endpoint offers no
        // actions for a protected site, so the UI has nothing to render.
        $this->getJson("/jetgrid/api/sites/{$site->id}")
            ->assertOk()
            ->assertJsonPath('protected', true)
            ->assertJsonPath('actions', []);
    }

    public function test_a_managed_site_does_expose_actions(): void
    {
        app(DiscoveryService::class)->run();
        $this->actingAs(User::factory()->create(['role' => Role::GodMode]));

        $site = \App\Models\Site::firstWhere('domain', 'iceland.example.com');
        $site->unprotect();

        $actions = $this->getJson("/jetgrid/api/sites/{$site->id}")->assertOk()->json('actions');

        $this->assertContains('deploy', $actions);
        $this->assertContains('certificate', $actions);
    }

    public function test_the_payload_reports_read_only_mode_and_a_poll_interval(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Viewer]));

        $payload = $this->getJson('/jetgrid/api/grid')->assertOk()->json();

        $this->assertTrue($payload['readOnly']);
        $this->assertSame(config('jetgrid.poll.idle_ms'), $payload['pollMs']);
        $this->assertArrayHasKey('capacity', $payload);
        $this->assertArrayHasKey('emptyPlots', $payload);
    }

    public function test_capacity_names_its_limiting_factor(): void
    {
        $facts = app(MetricsCollector::class)->collect();
        $estimate = app(CapacityEstimator::class)->estimate($facts);

        $this->assertNotNull($estimate->limitingFactor);
        $this->assertStringContainsString('more small site', $estimate->headline());
        $this->assertStringContainsString($estimate->limitingLabel, $estimate->headline());
    }

    public function test_capacity_excludes_constraints_it_could_not_measure_rather_than_guessing(): void
    {
        // No CloudWatch configured in tests, so CPU credits are unknown.
        $estimate = app(CapacityEstimator::class)->estimate(app(MetricsCollector::class)->collect());

        $unknown = collect($estimate->unknownConstraints())->pluck('key')->all();

        $this->assertContains('cpu_credits', $unknown);
        $this->assertNotSame('cpu_credits', $estimate->limitingFactor);
    }

    public function test_capacity_is_zero_and_honest_when_nothing_can_be_read(): void
    {
        $estimate = app(CapacityEstimator::class)->estimate(new ServerFacts());

        $this->assertSame(0, $estimate->slots);
        $this->assertNull($estimate->limitingFactor);
        $this->assertStringContainsString('unknown', $estimate->headline());
    }
}
