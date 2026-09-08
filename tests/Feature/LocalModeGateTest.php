<?php

namespace Tests\Feature;

use App\Enums\LocalProjectType;
use App\Exceptions\LocalModeDisabledException;
use App\Http\Middleware\EnsureLocalMode;
use App\Models\LocalProject;
use App\Models\User;
use App\Services\Local\LocalCommandRunner;
use App\Services\Local\LocalModeGate;
use App\Services\Local\ProcessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\UsesLocalFixtures;
use Tests\TestCase;

/**
 * L0. The gate, and the guarantee that nothing can get past it.
 *
 * The route test is the one the spec asks for by name: it walks the router
 * rather than a hand-written list, so a local-control route added tomorrow
 * without the middleware fails this suite instead of shipping.
 */
class LocalModeGateTest extends TestCase
{
    use RefreshDatabase, UsesLocalFixtures;

    /** The gate is closed unless every one of the four checks passes. */
    public function test_all_four_checks_must_pass(): void
    {
        $this->enableLocalMode();
        $this->assertTrue(app(LocalModeGate::class)->passes());

        // 1. mode
        config(['jetgrid.mode' => 'production']);
        $this->assertFalse(app(LocalModeGate::class)->passes());
        $this->assertContains(LocalModeGate::CHECK_MODE, app(LocalModeGate::class)->failures());

        // 2. environment
        $this->enableLocalMode();
        $this->app['env'] = 'production';
        $this->assertFalse(app(LocalModeGate::class)->passes());
        $this->assertContains(LocalModeGate::CHECK_ENVIRONMENT, app(LocalModeGate::class)->failures());

        // 3. production marker file
        $this->enableLocalMode();
        config(['jetgrid.local.production_markers' => [base_path('composer.json')]]);
        $this->assertFalse(app(LocalModeGate::class)->passes());
        $this->assertContains(LocalModeGate::CHECK_MARKER, app(LocalModeGate::class)->failures());
    }

    /**
     * The default is production, so an install that never heard of this module
     * is gated by omission.
     *
     * Asserted against the config file's source because the running config has
     * already been resolved against this machine's .env, which says local — and
     * the value under test is precisely the one that applies when it does not.
     */
    public function test_the_shipped_default_is_production(): void
    {
        $this->assertStringContainsString(
            "'mode' => env('JETGRID_MODE', 'production')",
            file_get_contents(config_path('jetgrid.php')),
        );
    }

    public function test_a_failing_gate_names_every_check_that_failed(): void
    {
        config(['jetgrid.mode' => 'production']);
        $this->app['env'] = 'production';

        $failures = app(LocalModeGate::class)->failures();

        $this->assertContains(LocalModeGate::CHECK_MODE, $failures);
        $this->assertContains(LocalModeGate::CHECK_ENVIRONMENT, $failures);
    }

    /**
     * REQUIRED BY L0: every local-control route is behind EnsureLocalMode.
     *
     * Discovered from the route table, not asserted against a list — a list
     * would have to be updated by the same person who forgot the middleware.
     */
    public function test_every_local_control_route_is_behind_the_middleware(): void
    {
        $local = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'jetgrid.api.local.')
                || str_contains($route->uri(), 'jetgrid/api/local'));

        $this->assertGreaterThan(0, $local->count(), 'No local-control routes were found — this test would pass vacuously.');

        foreach ($local as $route) {
            $this->assertContains(
                EnsureLocalMode::ALIAS,
                $route->gatherMiddleware(),
                'Route ['.$route->uri().'] is not behind EnsureLocalMode.',
            );
        }
    }

    /** Not a silent no-op: 403, with the reason, and a log line. */
    public function test_a_gated_request_is_refused_with_403_and_logged(): void
    {
        config(['jetgrid.mode' => 'production']);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'refused a local-control request')
                && $context['failed_checks'] !== []);

        $this->actingAs(User::factory()->create())
            ->getJson(route('jetgrid.api.local.index'))
            ->assertForbidden()
            ->assertJson(['refused' => true])
            ->assertJsonStructure(['refused', 'reason', 'failed_checks']);
    }

    public function test_a_start_request_is_refused_when_the_gate_is_closed(): void
    {
        config(['jetgrid.mode' => 'production']);

        $project = $this->project();

        $this->actingAs(User::factory()->create())
            ->postJson(route('jetgrid.api.local.start', $project))
            ->assertForbidden()
            ->assertJson(['refused' => true]);
    }

    /**
     * The middleware is not the only guard. The artisan commands reach the same
     * services without passing through it, so the service layer checks too.
     */
    public function test_the_service_layer_refuses_even_without_the_middleware(): void
    {
        config(['jetgrid.mode' => 'production']);

        $this->expectException(LocalModeDisabledException::class);

        app(ProcessController::class)->start($this->project());
    }

    public function test_even_a_read_only_probe_command_is_gated(): void
    {
        config(['jetgrid.mode' => 'production']);

        $this->expectException(LocalModeDisabledException::class);

        app(LocalCommandRunner::class)->run('windows.port.owner');
    }

    public function test_the_scan_command_refuses_and_says_why(): void
    {
        config(['jetgrid.mode' => 'production']);

        $this->artisan('jetgrid:scan --dry-run')
            ->expectsOutputToContain('Local mode is off')
            ->assertFailed();
    }

    /**
     * The doctor is the tool an operator reaches for when the gate has refused
     * them, so it has to keep working — and keep reporting — while it is closed.
     */
    public function test_the_doctor_reports_a_closed_gate_without_refusing_to_run(): void
    {
        config(['jetgrid.mode' => 'production']);

        $this->artisan('jetgrid:doctor')
            ->expectsOutputToContain('DISABLED')
            ->assertFailed();
    }

    public function test_the_doctor_reports_the_platform_and_the_missing_tools(): void
    {
        $this->enableLocalMode();

        $this->artisan('jetgrid:doctor')
            ->expectsOutputToContain('Detected family')
            ->expectsOutputToContain('ENABLED')
            ->assertSuccessful();
    }

    private function project(): LocalProject
    {
        $path = $this->fixture('laravel-app');

        return LocalProject::create([
            'path' => $path,
            'path_key' => LocalProject::keyForPath($path),
            'name' => 'laravel-app',
            'scan_root' => dirname($path),
            'type' => LocalProjectType::Laravel,
            'type_marker' => 'artisan',
            'port' => 8123,
        ]);
    }
}
