<?php

namespace Tests\Feature;

use App\Enums\DetectionLayer;
use App\Enums\InstallState;
use App\Enums\LocalProjectType;
use App\Enums\RunState;
use App\Models\LocalProject;
use App\Services\Local\ProcessController;
use App\Services\Local\RunStateProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesLocalFixtures;
use Tests\TestCase;

/**
 * L5 and L6, without starting a single dev server.
 *
 * Layer 1 is exercised against a socket this test opens itself, which is the
 * cheapest honest way to have a port that is genuinely listening. Everything
 * else is asserted through the refusals, because the refusals are the part that
 * has to be right: a bug in "start" wastes a minute, a bug in "stop" kills
 * something that was not ours.
 */
class LocalProcessControlTest extends TestCase
{
    use RefreshDatabase, UsesLocalFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableLocalMode();
    }

    private function project(string $fixture = 'laravel-app', array $attributes = []): LocalProject
    {
        $path = $this->fixture($fixture);

        return LocalProject::create(array_merge([
            'path' => $path,
            'path_key' => LocalProject::keyForPath($path),
            'name' => $fixture,
            'scan_root' => dirname($path),
            'type' => LocalProjectType::Laravel,
            'type_marker' => 'artisan',
            'install_state' => InstallState::Ready,
            'port' => 8123,
        ], $attributes));
    }

    /** A socket this test owns, so "listening" is a fact rather than a mock. */
    private function listener(): array
    {
        for ($port = 49500; $port < 49600; $port++) {
            $server = @stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $errstr);

            if ($server !== false) {
                return [$server, $port];
            }
        }

        $this->markTestSkipped('No free loopback port in the test range.');
    }

    // ---- L5 -----------------------------------------------------------------

    public function test_layer_one_sees_an_open_port_and_a_closed_one(): void
    {
        [$server, $port] = $this->listener();

        try {
            $this->assertTrue(app(RunStateProbe::class)->tcpProbe($port)[0]);
        } finally {
            fclose($server);
        }

        $this->assertFalse(app(RunStateProbe::class)->tcpProbe($port)[0]);
    }

    public function test_a_closed_port_reports_stopped_and_says_no_layer_answered(): void
    {
        [$server, $port] = $this->listener();
        fclose($server);

        $status = app(RunStateProbe::class)->probe($this->fixture('laravel-app'), $port);

        $this->assertSame(RunState::Stopped, $status->state);
        $this->assertSame(DetectionLayer::None, $status->layer);
        $this->assertFalse($status->isRunning());
    }

    /**
     * Something listening that is not this project must never be reported as
     * this project. The test's own socket speaks no HTTP and has no relationship
     * to the fixture, which is exactly the dangerous case.
     */
    public function test_a_port_held_by_something_else_is_never_attributed_to_the_project(): void
    {
        [$server, $port] = $this->listener();

        try {
            $status = app(RunStateProbe::class)->probe($this->fixture('laravel-app'), $port);

            $this->assertNotSame(true, $status->attributed);
            $this->assertNotSame(RunState::Running, $status->state);
            $this->assertContains($status->layer, [DetectionLayer::Tcp, DetectionLayer::Http, DetectionLayer::Process]);
        } finally {
            fclose($server);
        }
    }

    public function test_a_project_jetgrid_started_is_starting_until_its_port_opens(): void
    {
        [$server, $port] = $this->listener();
        fclose($server);

        $status = app(RunStateProbe::class)->probe(
            $this->fixture('laravel-app'),
            $port,
            managedPid: 999999,
            startedAt: time(),
        );

        $this->assertSame(RunState::Starting, $status->state);
    }

    /** Past the grace period it has failed, and saying so is the useful answer. */
    public function test_a_project_that_never_opened_its_port_is_reported_as_failed(): void
    {
        [$server, $port] = $this->listener();
        fclose($server);

        $status = app(RunStateProbe::class)->probe(
            $this->fixture('laravel-app'),
            $port,
            managedPid: 999999,
            startedAt: time() - 3600,
        );

        $this->assertSame(RunState::Failed, $status->state);
        $this->assertStringContainsString('never opened port', (string) $status->note);
    }

    // ---- L6 -----------------------------------------------------------------

    public function test_a_project_with_missing_dependencies_is_not_started(): void
    {
        $project = $this->project('laravel-bare', [
            'install_state' => InstallState::DependenciesMissing,
            'blockers' => ['vendor/ is missing — composer install has not been run.'],
        ]);

        $outcome = app(ProcessController::class)->start($project);

        $this->assertFalse($outcome->ok);
        $this->assertStringContainsString('vendor/', $outcome->message);
    }

    /** A refusal has to name the holder — that is the point of layer 3. */
    public function test_a_port_collision_refuses_and_says_what_is_holding_the_port(): void
    {
        [$server, $port] = $this->listener();

        try {
            $project = $this->project(attributes: ['port' => $port]);

            $outcome = app(ProcessController::class)->start($project);

            $this->assertFalse($outcome->ok);
            $this->assertStringContainsString('Port '.$port.' is already held by', $outcome->message);
            $this->assertArrayHasKey('pid', $outcome->context);
        } finally {
            fclose($server);
        }
    }

    /** THE rule: JetGrid does not kill processes it cannot prove are its own. */
    public function test_stop_refuses_a_process_it_cannot_attribute(): void
    {
        [$server, $port] = $this->listener();

        try {
            $project = $this->project(attributes: ['port' => $port]);

            $outcome = app(ProcessController::class)->stop($project);

            $this->assertFalse($outcome->ok);
            $this->assertStringContainsString('did not start it', $outcome->message);
        } finally {
            fclose($server);
        }
    }

    /**
     * A project that was not running is a legitimate restart target, so restart
     * must carry on past a stop it could not perform and reach the start.
     */
    public function test_restart_of_a_stopped_project_falls_through_to_start(): void
    {
        [$server, $port] = $this->listener();
        fclose($server);

        $project = $this->project('laravel-bare', [
            'install_state' => InstallState::DependenciesMissing,
            'blockers' => ['vendor/ is missing — composer install has not been run.'],
            'port' => $port,
        ]);

        $outcome = app(ProcessController::class)->restart($project);

        // The start is what refused, not the stop: proof the restart got there.
        $this->assertFalse($outcome->ok);
        $this->assertStringContainsString('vendor/', $outcome->message);
        $this->assertStringNotContainsString('Restart aborted', $outcome->message);
    }

    public function test_the_start_command_is_previewable_before_it_runs(): void
    {
        $preview = app(ProcessController::class)->previewStart($this->project());

        $this->assertSame('php artisan serve --host=127.0.0.1 --port=8123', $preview);
    }

    public function test_the_preview_follows_the_package_manager_and_dev_script(): void
    {
        $project = $this->project('vite-react', [
            'type' => LocalProjectType::Vite,
            'type_marker' => 'vite.config.ts',
            'package_manager' => 'pnpm',
            'dev_script' => 'dev',
            'port' => 5180,
        ]);

        $this->assertSame('pnpm run dev', app(ProcessController::class)->previewStart($project));
    }

    // ---- L6 hard rules ------------------------------------------------------

    public function test_an_install_is_refused_without_the_literal_command_echoed_back(): void
    {
        $project = $this->project();

        $outcome = app(ProcessController::class)->maintenance($project, 'install.composer', 'yes');

        $this->assertFalse($outcome->ok);
        $this->assertSame('composer install', $outcome->command);
        $this->assertStringContainsString('Not confirmed', $outcome->message);
    }

    public function test_an_unoffered_maintenance_action_is_refused(): void
    {
        $outcome = app(ProcessController::class)->maintenance($this->project(), 'start.laravel', 'php artisan serve');

        $this->assertFalse($outcome->ok);
        $this->assertStringContainsString('not an offered maintenance action', $outcome->message);
    }

    /**
     * L6: every file this module creates lives in JetGrid's own storage. The
     * paths are asserted rather than the writes, because a path that is right
     * cannot produce a write that is wrong.
     */
    public function test_pid_and_log_files_are_inside_jetgrid_storage(): void
    {
        $project = $this->project();

        foreach ([$project->logPath(), $project->pidPath()] as $path) {
            $this->assertStringStartsWith(
                rtrim(storage_path('app'.DIRECTORY_SEPARATOR.'jetgrid'), '\\/'),
                $path,
            );
            $this->assertStringNotContainsString($project->path, $path);
        }
    }

    /** Two projects with the same folder name must not share a log file. */
    public function test_two_projects_with_the_same_name_get_different_logs(): void
    {
        $a = $this->project();
        $b = $this->project('laravel-bare', ['name' => 'laravel-app']);

        $this->assertNotSame($a->logPath(), $b->logPath());
    }

    public function test_nothing_in_a_project_directory_is_written_by_a_refused_operation(): void
    {
        $before = $this->treeHashes($this->fixture());

        [$server, $port] = $this->listener();

        try {
            $controller = app(ProcessController::class);
            $project = $this->project(attributes: ['port' => $port]);

            $controller->start($project);
            $controller->stop($project);
            $controller->maintenance($project, 'install.composer', 'no');
        } finally {
            fclose($server);
        }

        $this->assertSame($before, $this->treeHashes($this->fixture()));
    }
}
