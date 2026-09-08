<?php

namespace Tests\Unit;

use App\Services\Local\PortResolution;
use App\Services\Local\PortResolver;
use App\Services\Local\ProjectTypeDetector;
use Tests\Concerns\UsesLocalFixtures;
use Tests\TestCase;

/** L4. Resolving a port from fixtures, without starting anything. */
class PortResolverTest extends TestCase
{
    use UsesLocalFixtures;

    private PortResolver $resolver;

    private ProjectTypeDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new PortResolver;
        $this->detector = new ProjectTypeDetector;
    }

    private function resolve(string $fixture, ?int $override = null): PortResolution
    {
        $path = $this->fixture($fixture);

        return $this->resolver->resolve($path, $this->detector->detect($path), $override);
    }

    public function test_laravel_takes_its_port_from_app_url(): void
    {
        $resolution = $this->resolve('laravel-app');

        $this->assertSame(8123, $resolution->port);
        $this->assertSame(PortResolution::SOURCE_ENV, $resolution->source);
    }

    public function test_a_node_project_takes_its_port_from_env_local(): void
    {
        $resolution = $this->resolve('node-express');

        $this->assertSame(4321, $resolution->port);
        $this->assertSame(PortResolution::SOURCE_ENV, $resolution->source);
    }

    public function test_a_port_in_the_dev_script_is_found_when_there_is_no_env(): void
    {
        $vite = $this->resolve('vite-react');

        $this->assertSame(5180, $vite->port);
        $this->assertSame(PortResolution::SOURCE_SCRIPT, $vite->source);

        $next = $this->resolve('nextjs-app');

        $this->assertSame(3001, $next->port);
        $this->assertSame(PortResolution::SOURCE_SCRIPT, $next->source);
    }

    public function test_compose_host_ports_are_used_when_nothing_more_specific_exists(): void
    {
        $resolution = $this->resolve('compose-only');

        $this->assertSame(9090, $resolution->port);
        $this->assertSame(PortResolution::SOURCE_COMPOSE, $resolution->source);
    }

    /**
     * A compose file with a bound address and a range must still yield the host
     * side of the mapping, not the container side.
     */
    public function test_the_host_side_of_a_compose_mapping_is_the_one_that_counts(): void
    {
        $ports = $this->resolver->composeHostPorts($this->fixture('laravel-dockerised'));

        $this->assertSame([8081, 33061], $ports);
    }

    /** APP_URL with no port must not be read as "port 80". */
    public function test_a_url_without_a_port_falls_through_to_the_default(): void
    {
        $resolution = $this->resolve('laravel-dockerised');

        // The compose file is the next-best evidence, and it publishes 8081.
        $this->assertSame(8081, $resolution->port);
        $this->assertSame(PortResolution::SOURCE_COMPOSE, $resolution->source);
    }

    /**
     * @dataProvider frameworkDefaults
     */
    public function test_the_framework_default_is_the_last_resort(string $fixture, int $port): void
    {
        $resolution = $this->resolve($fixture);

        $this->assertSame($port, $resolution->port);
        $this->assertSame(PortResolution::SOURCE_DEFAULT, $resolution->source);
    }

    public static function frameworkDefaults(): array
    {
        return [
            'django' => ['django-app', 8000],
            'rails' => ['rails-app', 3000],
            'nuxt' => ['nuxt-app', 3000],
            'static' => ['static-site', 8000],
            'symfony' => ['symfony-app', 8000],
        ];
    }

    public function test_a_user_override_beats_every_other_source(): void
    {
        $resolution = $this->resolve('laravel-app', override: 9001);

        $this->assertSame(9001, $resolution->port);
        $this->assertSame(PortResolution::SOURCE_OVERRIDE, $resolution->source);
    }

    public function test_a_nonsense_override_is_ignored_rather_than_used(): void
    {
        $this->assertSame(8123, $this->resolve('laravel-app', override: 70000)->port);
    }

    public function test_the_resolution_always_says_where_the_number_came_from(): void
    {
        foreach (['laravel-app', 'node-express', 'vite-react', 'django-app', 'compose-only'] as $fixture) {
            $this->assertNotSame('', $this->resolve($fixture)->describe(), $fixture.' resolved a port with no stated source.');
        }
    }

    public function test_resolving_does_not_write_to_a_project(): void
    {
        $before = $this->treeHashes($this->fixture());

        foreach (['laravel-app', 'node-express', 'compose-only', 'laravel-dockerised'] as $fixture) {
            $this->resolve($fixture);
        }

        $this->assertSame($before, $this->treeHashes($this->fixture()), 'Port resolution modified a project directory.');
    }
}
