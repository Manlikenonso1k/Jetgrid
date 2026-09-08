<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public marketing page.
 *
 * The pricing assertions matter more than they look: the page reads limits from
 * the plans table so the advertised numbers and the numbers Plan::allows()
 * enforces cannot drift. A test that pinned literal copy would defeat that.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_sees_the_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('seen from above', false)
            ->assertSee('Create an account');
    }

    public function test_an_authenticated_visitor_is_sent_to_the_grid(): void
    {
        $user = User::factory()->create(['role' => Role::Viewer]);

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect('/admin/grid');
    }

    public function test_pricing_is_rendered_from_the_plans_table(): void
    {
        Plan::query()->delete();

        Plan::create([
            'slug' => 'test-tier',
            'name' => 'Test Tier',
            'price_cents' => 1500,
            'max_sites' => 7,
            'max_databases' => null,
            'max_storage_mb' => 2048,
            'max_backups' => 3,
            'monitor_interval_seconds' => 120,
            'sort_order' => 1,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Test Tier')
            ->assertSee('$15.00/mo')
            ->assertSee('7 sites')
            ->assertSee('Unlimited databases')
            ->assertSee('2 GB storage')
            ->assertSee('Checks every 2 min');
    }

    public function test_the_registration_route_is_reachable(): void
    {
        $this->get('/admin/register')->assertOk();
    }
}
