<?php

namespace Tests\Feature;

use App\Enums\BeaconColor;
use App\Enums\Role;
use App\Models\User;
use App\Services\Discovery\DiscoveryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke tests for the panel screens.
 *
 * These exist because a Filament resource can be perfectly valid PHP, pass every
 * unit test, and still throw or render unstyled the first time someone opens the
 * page — a badge mapped to a colour that was never registered does exactly that,
 * and nothing else in the suite would notice.
 */
class PanelRendersTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($user);

        return $user;
    }

    public function test_every_beacon_colour_maps_to_a_colour_the_panel_has_registered(): void
    {
        $registered = array_keys(Filament::getPanel('admin')->getColors());

        // The mapping lives in SiteResource; this asserts the contract it depends
        // on, so adding a BeaconColor without registering its colour fails here
        // rather than in front of the user.
        $mapping = [
            BeaconColor::Green->value => 'success',
            BeaconColor::Red->value => 'danger',
            BeaconColor::Yellow->value => 'warning',
            BeaconColor::Blue->value => 'info',
            BeaconColor::Grey->value => 'gray',
            BeaconColor::Purple->value => 'purple',
        ];

        foreach (BeaconColor::cases() as $case) {
            $this->assertArrayHasKey($case->value, $mapping, "BeaconColor::{$case->name} has no colour mapping.");

            $colour = $mapping[$case->value];

            // 'info' is a Filament built-in and is not listed by getColors().
            if ($colour === 'info') {
                continue;
            }

            $this->assertContains(
                $colour,
                $registered,
                "BeaconColor::{$case->name} maps to '{$colour}', which the admin panel does not register.",
            );
        }
    }

    public function test_the_sites_list_renders_with_discovered_records(): void
    {
        app(DiscoveryService::class)->run();
        $this->actingAsAdmin();

        $this->get('/admin/sites')->assertOk();
    }

    public function test_the_grid_page_renders(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/grid')->assertOk();
    }

    public function test_the_instance_cost_page_renders(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/instance-catalog')->assertOk();
    }

    public function test_the_certificates_list_renders(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/certificates')->assertOk();
    }

    /** god_mode only — an admin must be refused, not shown an empty page. */
    public function test_the_audit_log_is_god_mode_only(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/audit-logs')->assertForbidden();

        $this->actingAs(User::factory()->godMode()->create());
        $this->get('/admin/audit-logs')->assertOk();
    }
}
