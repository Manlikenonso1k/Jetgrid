<?php

namespace Tests\Unit;

use App\Enums\InstallState;
use App\Enums\LocalProjectType;
use App\Services\Local\ProjectTypeDetector;
use Tests\Concerns\UsesLocalFixtures;
use Tests\TestCase;

/**
 * L3 and L9. Type detection against fixture directory trees, so the whole table
 * is verifiable without a single server being started.
 *
 * The fixtures under tests/fixtures/local-projects are deliberately minimal —
 * just the marker files — because the marker files are the entire contract. If a
 * detector needs more than them to reach an answer, it is reading something it
 * should not be.
 */
class ProjectTypeDetectorTest extends TestCase
{
    use UsesLocalFixtures;

    private ProjectTypeDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new ProjectTypeDetector;
    }

    /**
     * @dataProvider signatures
     */
    public function test_it_identifies_a_project_by_its_marker_files(string $fixture, string $type, string $marker): void
    {
        $signature = $this->detector->detect($this->fixture($fixture));

        $this->assertNotNull($signature, "{$fixture} was not recognised as a project at all.");
        $this->assertSame($type, $signature->type->value, "{$fixture} was detected as {$signature->type->value}.");
        $this->assertSame($marker, $signature->marker, "{$fixture} was decided by the wrong file.");
    }

    public static function signatures(): array
    {
        return [
            'laravel' => ['laravel-app', 'laravel', 'artisan'],
            'symfony' => ['symfony-app', 'symfony', 'bin/console'],
            'wordpress' => ['wordpress-site', 'wordpress', 'wp-config.php'],
            'django' => ['django-app', 'django', 'manage.py'],
            'rails' => ['rails-app', 'rails', 'config/application.rb'],
            'next' => ['nextjs-app', 'nextjs', 'package.json'],
            'nuxt' => ['nuxt-app', 'nuxt', 'nuxt.config.ts'],
            'vite' => ['vite-react', 'vite', 'vite.config.ts'],
            'node' => ['node-express', 'node', 'package.json'],
            'fastapi' => ['fastapi-service', 'flask', 'requirements.txt'],
            'go' => ['go-service', 'go', 'go.mod'],
            'rust' => ['rust-crate', 'rust', 'Cargo.toml'],
            'generic php by composer.json' => ['composer-only', 'php', 'composer.json'],
            'generic php by index.php' => ['php-legacy', 'php', 'index.php'],
            'static' => ['static-site', 'static', 'index.html'],
        ];
    }

    /**
     * The priority order is the point of the table: everything below Laravel in
     * it also matches a Laravel project.
     */
    public function test_a_stronger_signature_wins_over_a_weaker_one(): void
    {
        // laravel-app has composer.json (generic PHP) and vendor/ as well as
        // artisan; wordpress-site has index.php as well as wp-config.php.
        $this->assertSame(LocalProjectType::Laravel, $this->detector->detect($this->fixture('laravel-app'))->type);
        $this->assertSame(LocalProjectType::WordPress, $this->detector->detect($this->fixture('wordpress-site'))->type);

        // nextjs-app would match "package.json with a dev script" too.
        $this->assertSame(LocalProjectType::Nextjs, $this->detector->detect($this->fixture('nextjs-app'))->type);
    }

    public function test_a_folder_with_no_marker_is_not_a_project(): void
    {
        $this->assertNull($this->detector->detect($this->fixture('just-notes')));
    }

    public function test_a_missing_directory_is_not_a_project(): void
    {
        $this->assertNull($this->detector->detect($this->fixture('does-not-exist')));
    }

    /** A half-written manifest is normal on a workstation and must not throw. */
    public function test_an_unparseable_manifest_does_not_break_detection(): void
    {
        $signature = $this->detector->detect($this->fixture('broken-manifest'));

        $this->assertNotNull($signature);
        $this->assertSame(LocalProjectType::Php, $signature->type);
    }

    public function test_docker_is_a_modifier_rather_than_a_type(): void
    {
        $laravel = $this->detector->detect($this->fixture('laravel-dockerised'));

        $this->assertSame(LocalProjectType::Laravel, $laravel->type);
        $this->assertTrue($laravel->hasDocker);

        $this->assertFalse($this->detector->detect($this->fixture('laravel-app'))->hasDocker);
    }

    public function test_it_names_the_framework_behind_vite(): void
    {
        $this->assertSame('React', $this->detector->detect($this->fixture('vite-react'))->framework);
        $this->assertSame('FastAPI', $this->detector->detect($this->fixture('fastapi-service'))->framework);
    }

    public function test_it_reads_a_cheaply_available_version(): void
    {
        $this->assertSame('^12.0', $this->detector->detect($this->fixture('laravel-app'))->version);
        $this->assertSame('14.2.3', $this->detector->detect($this->fixture('nextjs-app'))->version);
        $this->assertSame('1.22', $this->detector->detect($this->fixture('go-service'))->version);
    }

    /** The folder name is the label; the manifest name is kept beside it. */
    public function test_it_labels_a_project_by_its_folder_and_records_the_declared_name(): void
    {
        $signature = $this->detector->detect($this->fixture('nextjs-app'));

        $this->assertSame('nextjs-app', $signature->name);
        $this->assertSame('storefront', $signature->declaredName);
    }

    /**
     * @dataProvider installStates
     */
    public function test_it_detects_why_a_project_cannot_run(string $fixture, InstallState $expected): void
    {
        $this->assertSame($expected, $this->detector->detect($this->fixture($fixture))->installState);
    }

    public static function installStates(): array
    {
        return [
            'everything present' => ['laravel-app', InstallState::Ready],
            'no vendor and no .env' => ['laravel-bare', InstallState::DependenciesMissing],
            'vendor present, .env missing' => ['laravel-unconfigured', InstallState::NotConfigured],
            'node_modules present' => ['nextjs-app', InstallState::Ready],
        ];
    }

    public function test_every_blocker_is_reported_not_just_the_first(): void
    {
        $blockers = $this->detector->detect($this->fixture('laravel-bare'))->blockers;

        $this->assertCount(2, $blockers);
        $this->assertStringContainsString('vendor/', $blockers[0]);
        $this->assertStringContainsString('.env', $blockers[1]);
    }

    public function test_it_reads_the_package_manager_from_the_lockfile(): void
    {
        $this->assertSame('pnpm', $this->detector->detect($this->fixture('vite-react'))->packageManager);
        $this->assertSame('yarn', $this->detector->detect($this->fixture('node-express'))->packageManager);
        $this->assertSame('npm', $this->detector->detect($this->fixture('nextjs-app'))->packageManager);
    }

    public function test_it_prefers_the_dev_script_over_start(): void
    {
        $this->assertSame('dev', $this->detector->detect($this->fixture('nextjs-app'))->devScript);
        $this->assertSame('start', $this->detector->detect($this->fixture('node-express'))->devScript);
    }

    /** Safety: detection reads. It must not create, touch or modify anything. */
    public function test_detection_does_not_write_to_a_discovered_project(): void
    {
        $before = $this->treeHashes($this->fixture());

        foreach (scandir($this->fixture()) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->detector->detect($this->fixture($entry));
            }
        }

        $this->assertSame($before, $this->treeHashes($this->fixture()), 'Type detection modified a project directory.');
    }
}
