<?php

namespace Tests\Unit;

use App\Services\Local\LocalCommandRegistry;
use App\Services\Local\Platform\LinuxPlatformCommands;
use App\Services\Local\Platform\MacPlatformCommands;
use App\Services\Local\Platform\ToolLocator;
use App\Services\Local\Platform\WindowsPlatformCommands;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * Regressions found by running the module against a real workstation rather than
 * against fixtures. Both bugs passed the original unit tests, because both only
 * appear when the machine is messier than a fixture is.
 */
class PortAttributionRegressionTest extends TestCase
{
    private function linux(): LinuxPlatformCommands
    {
        return new LinuxPlatformCommands(new ToolLocator(new ExecutableFinder));
    }

    /**
     * Two dev servers on port 8000 at once: one belonging to the project, one a
     * stale server left behind. This is ordinary on a developer machine, and it
     * is what the original single-pid parser could not represent.
     */
    private const NETSTAT_SHARED_PORT = <<<'OUT'
    Active Connections

      Proto  Local Address          Foreign Address        State           PID
      TCP    127.0.0.1:8000         0.0.0.0:0              LISTENING       3416
      TCP    127.0.0.1:8000         0.0.0.0:0              LISTENING       22708
      TCP    127.0.0.1:54001        127.0.0.1:8000         ESTABLISHED     9999
      TCP    [::1]:8000             [::]:0                 LISTENING       3416
    OUT;

    private const SS_FORKED_WORKERS = <<<'OUT'
    State  Recv-Q Send-Q Local Address:Port  Peer Address:Port Process
    LISTEN 0      511          127.0.0.1:8000       0.0.0.0:*     users:(("php",pid=3416,fd=8),("php",pid=22708,fd=8))
    OUT;

    private const LSOF_BOTH_FAMILIES = <<<'OUT'
    COMMAND   PID  USER   FD   TYPE DEVICE SIZE/OFF NODE NAME
    php      3416 ayomi    8u  IPv4 0x1234      0t0  TCP 127.0.0.1:8000 (LISTEN)
    php     22708 ayomi    9u  IPv6 0x5678      0t0  TCP [::1]:8000 (LISTEN)
    OUT;

    /**
     * The bug: attribution read only the FIRST pid the tool printed, so a project
     * whose server was listed second reported "port held by something else". The
     * same scan then flipped between answers on consecutive runs, because netstat
     * does not guarantee row order.
     */
    public function test_windows_returns_every_pid_holding_a_port(): void
    {
        $owners = (new WindowsPlatformCommands)->parsePortOwners(self::NETSTAT_SHARED_PORT, 8000);

        $this->assertEqualsCanonicalizing([3416, 22708], $owners);
    }

    public function test_established_connections_are_still_excluded(): void
    {
        $owners = (new WindowsPlatformCommands)->parsePortOwners(self::NETSTAT_SHARED_PORT, 54001);

        $this->assertSame([], $owners, 'An outbound connection must never be read as a listener.');
    }

    public function test_a_pid_holding_both_address_families_is_reported_once(): void
    {
        $owners = (new WindowsPlatformCommands)->parsePortOwners(self::NETSTAT_SHARED_PORT, 8000);

        $this->assertSame(count($owners), count(array_unique($owners)));
    }

    /** ss puts forked workers that inherited the socket on one row. */
    public function test_linux_reads_every_pid_on_a_single_ss_row(): void
    {
        $owners = $this->linux()->parsePortOwners(self::SS_FORKED_WORKERS, 8000);

        $this->assertEqualsCanonicalizing([3416, 22708], $owners);
    }

    public function test_lsof_reads_both_the_ipv4_and_ipv6_listener(): void
    {
        $this->assertEqualsCanonicalizing(
            [3416, 22708],
            (new MacPlatformCommands)->parsePortOwners(self::LSOF_BOTH_FAMILIES, 8000),
        );
    }

    /** The single-pid accessor must stay consistent with the list. */
    public function test_the_singular_accessor_returns_the_first_of_the_list(): void
    {
        foreach ([new WindowsPlatformCommands, $this->linux(), new MacPlatformCommands] as $platform) {
            $output = match ($platform::class) {
                WindowsPlatformCommands::class => self::NETSTAT_SHARED_PORT,
                LinuxPlatformCommands::class => self::SS_FORKED_WORKERS,
                default => self::LSOF_BOTH_FAMILIES,
            };

            $this->assertSame(
                $platform->parsePortOwners($output, 8000)[0] ?? null,
                $platform->parsePortOwner($output, 8000),
            );
        }
    }

    /**
     * The other bug: `git status --porcelain` refreshes the on-disk index, which
     * takes .git/index.lock and rewrites .git/index — a WRITE inside a project
     * this module is forbidden to write to. It was observed changing the mtime of
     * .git in every scanned repository.
     */
    public function test_git_is_never_invoked_in_a_way_that_can_write_to_a_project(): void
    {
        $registry = app(LocalCommandRegistry::class);

        foreach ($registry->all() as $command) {
            if (($command->argv[0] ?? null) !== 'git') {
                continue;
            }

            $this->assertContains(
                '--no-optional-locks',
                $command->argv,
                "[{$command->key}] runs git without --no-optional-locks, so it may take a lock and write inside a discovered project.",
            );

            $this->assertFalse(
                $command->isWrite,
                "[{$command->key}] is a repo read and must not be marked as a write.",
            );
        }
    }
}
