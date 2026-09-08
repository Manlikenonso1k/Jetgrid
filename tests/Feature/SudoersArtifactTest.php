<?php

namespace Tests\Feature;

use App\Services\Privilege\CommandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Safety constraint #3, applied to the artifact itself.
 *
 * The sudoers file is the defence that survives a compromise of the PHP layer,
 * so it gets tested like one. In sudoers, a wildcard in a command ARGUMENT
 * matches the whole argument including slashes — which is why an unadorned `*`
 * after `systemctl restart` would be a hole big enough to restart nginx.
 */
class SudoersArtifactTest extends TestCase
{
    use RefreshDatabase;

    private function artifact(): string
    {
        $path = base_path('artifacts/jetgrid-sudoers-test');
        $this->artisan('jetgrid:sudoers', ['--output' => 'artifacts/jetgrid-sudoers-test'])->run();

        $contents = file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    /** @return list<string> */
    private function rules(string $artifact): array
    {
        return array_values(array_filter(
            preg_split('/\r?\n/', $artifact) ?: [],
            static fn (string $line): bool => str_starts_with($line, 'jetgrid ALL='),
        ));
    }

    public function test_it_never_grants_blanket_root(): void
    {
        $artifact = $this->artifact();

        foreach ($this->rules($artifact) as $rule) {
            $this->assertStringNotContainsString('NOPASSWD: ALL', $rule);
            $this->assertStringNotContainsString('NOPASSWD:ALL', $rule);
        }
    }

    public function test_no_rule_grants_a_shell_interpreter_editor_or_package_manager(): void
    {
        $forbidden = ['/bin/sh', '/bin/bash', '/usr/bin/php', '/usr/bin/python', '/usr/bin/perl',
            '/usr/bin/vi', '/usr/bin/vim', 'apt-get install', '/usr/bin/dpkg', '/bin/chown', '/bin/chmod'];

        foreach ($this->rules($this->artifact()) as $rule) {
            foreach ($forbidden as $binary) {
                $this->assertStringNotContainsString($binary, $rule, "Rule grants {$binary}: {$rule}");
            }
        }
    }

    public function test_service_control_is_confined_to_jetgrid_units(): void
    {
        foreach ($this->rules($this->artifact()) as $rule) {
            if (! str_contains($rule, 'systemctl restart')) {
                continue;
            }

            // `systemctl restart *` would permit `systemctl restart nginx` and
            // take a live project down.
            $this->assertStringNotContainsString(
                'systemctl restart *',
                $rule,
                'Unqualified wildcard after `systemctl restart` — this would allow restarting ANY unit.',
            );

            $this->assertStringContainsString('jetgrid-', $rule);
        }
    }

    public function test_vhost_and_user_rules_keep_their_jetgrid_prefix(): void
    {
        $rules = implode("\n", $this->rules($this->artifact()));

        $this->assertStringContainsString('/etc/nginx/sites-enabled/jetgrid-*', $rules);
        $this->assertStringContainsString('/etc/nginx/sites-available/jetgrid-*', $rules);
        $this->assertStringContainsString('nologin jetgrid-*', $rules);

        // The bare forms would let JetGrid name any site on the box.
        $this->assertStringNotContainsString('/etc/nginx/sites-enabled/*', $rules);
        $this->assertStringNotContainsString('nologin *', $rules);
    }

    public function test_it_never_grants_bare_certbot_renew(): void
    {
        // `certbot renew` with no --cert-name would sweep up the adopted
        // certificates belonging to the live projects.
        foreach ($this->rules($this->artifact()) as $rule) {
            if (str_contains($rule, 'certbot renew')) {
                $this->assertStringContainsString('--cert-name', $rule);
            }
        }
    }

    public function test_every_root_command_in_the_registry_appears_in_the_artifact(): void
    {
        $artifact = $this->artifact();

        foreach (app(CommandRegistry::class)->requiringRoot() as $command) {
            $this->assertStringContainsString(
                '['.$command->key.']',
                $artifact,
                "Root command [{$command->key}] is missing from the generated sudoers file.",
            );
        }
    }

    public function test_broad_arguments_are_disclosed_rather_than_hidden(): void
    {
        $artifact = $this->artifact();

        $this->assertStringContainsString('RESIDUAL RISK', $artifact);
        // The certbot domain argument genuinely cannot be narrowed by sudo; the
        // artifact has to say so out loud.
        $this->assertStringContainsString('[certbot.renew] {domain}', $artifact);
    }
}
