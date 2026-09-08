<?php

namespace Tests\Feature;

use App\Exceptions\CommandNotWhitelistedException;
use App\Exceptions\InvalidCommandArgumentException;
use App\Exceptions\PathEscapeException;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Privilege\CommandRegistry;
use App\Services\Privilege\CommandRunner;
use App\Support\KillSwitch;
use App\Support\PathGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Safety constraints #3, #6 and #7. */
class CommandWhitelistTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_command_is_refused(): void
    {
        $this->expectException(CommandNotWhitelistedException::class);

        app(CommandRunner::class)->run('rm.rf.slash');
    }

    /**
     * @dataProvider injectionAttempts
     */
    public function test_arguments_that_do_not_match_their_pattern_are_refused(string $value): void
    {
        $this->expectException(InvalidCommandArgumentException::class);

        app(CommandRunner::class)->run('certbot.renew', ['domain' => $value]);
    }

    public static function injectionAttempts(): array
    {
        return [
            'shell chaining' => ['example.com; rm -rf /'],
            'command substitution' => ['$(whoami).example.com'],
            'backticks' => ['`id`.example.com'],
            'newline' => ["example.com\nrm -rf /"],
            'pipe' => ['example.com | cat /etc/shadow'],
            'flag injection' => ['--config-dir=/etc'],
            'path traversal' => ['../../etc/passwd'],
            'empty' => [''],
        ];
    }

    public function test_a_unit_name_without_the_jetgrid_prefix_is_refused(): void
    {
        // This is the guard that keeps JetGrid away from the live projects'
        // systemd units even when writes are enabled.
        $this->expectException(InvalidCommandArgumentException::class);

        app(CommandRunner::class)->run('systemd.restart_managed', ['unit' => 'nginx.service']);
    }

    public function test_every_placeholder_in_every_command_has_a_validating_pattern(): void
    {
        foreach (app(CommandRegistry::class)->all() as $command) {
            preg_match_all('/\{(\w+)\}/', implode(' ', $command->argv), $matches);

            foreach (array_unique($matches[1]) as $placeholder) {
                $this->assertArrayHasKey(
                    $placeholder,
                    $command->patterns,
                    "Command [{$command->key}] has an unvalidated placeholder {{$placeholder}}.",
                );
            }
        }
    }

    public function test_no_command_is_passed_through_a_shell(): void
    {
        // argv must stay a list of discrete tokens. A token containing a shell
        // metacharacter would mean someone had started building command lines
        // as strings again.
        foreach (app(CommandRegistry::class)->all() as $command) {
            foreach ($command->argv as $token) {
                $this->assertDoesNotMatchRegularExpression(
                    '/[;&|><`$]/',
                    $token,
                    "Command [{$command->key}] contains a shell metacharacter in: {$token}",
                );
            }
        }
    }

    public function test_a_dry_run_writes_an_audit_entry_and_executes_nothing(): void
    {
        $site = Site::create(['domain' => 'managed.example.com', 'is_protected' => false, 'management_mode' => 'managed']);

        config()->set('jetgrid.readonly', false);
        config()->set('jetgrid.allow_runtime_unlock', true);
        Setting::put(KillSwitch::SETTING, false);

        $outcome = app(CommandRunner::class)->run('nginx.reload', [], $site, dryRun: true);

        $this->assertTrue($outcome->wasDryRun);
        $this->assertTrue($outcome->result->skipped);
        $this->assertSame('sudo /bin/systemctl reload nginx', $outcome->preview());

        $audit = AuditLog::latest('id')->first();
        $this->assertSame('nginx.reload', $audit->command_key);
        $this->assertSame('dry_run', $audit->outcome);
        $this->assertTrue($audit->dry_run);
    }

    public function test_audit_entries_cannot_be_edited_or_deleted(): void
    {
        app(CommandRunner::class)->run('mem.info');

        $audit = AuditLog::latest('id')->firstOrFail();

        try {
            $audit->update(['command_key' => 'something.else']);
            $this->fail('Audit entries must be immutable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $audit->delete();
            $this->fail('Audit entries must not be deletable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }
    }

    public function test_read_commands_still_run_while_read_only(): void
    {
        $this->assertTrue(app(CommandRunner::class)->readOnly());

        $outcome = app(CommandRunner::class)->run('mem.info');

        $this->assertTrue($outcome->ok());
        $this->assertFalse($outcome->wasDryRun);
        $this->assertStringContainsString('Mem:', $outcome->result->stdout);
    }

    public function test_path_guard_rejects_traversal_and_paths_outside_the_allowed_roots(): void
    {
        $guard = new PathGuard(['/srv/jetgrid/sites']);

        $this->assertTrue($guard->isInside('/srv/jetgrid/sites/example/public/index.php'));

        foreach (['/etc/passwd', '/srv/jetgrid/sites/../../etc/shadow', '/var/www/live-project'] as $path) {
            $this->assertFalse($guard->isInside($path), "{$path} should have been rejected.");
        }

        $this->expectException(PathEscapeException::class);
        $guard->assertInside('/etc/nginx/nginx.conf');
    }
}
