<?php

namespace Tests\Unit;

use App\Enums\PlatformFamily;
use App\Services\Local\Platform\LinuxPlatformCommands;
use App\Services\Local\Platform\MacPlatformCommands;
use App\Services\Local\Platform\PlatformCommands;
use App\Services\Local\Platform\ToolLocator;
use App\Services\Local\Platform\UnsupportedPlatformCommands;
use App\Services\Local\Platform\WindowsPlatformCommands;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * L9. The platform seam, tested against captured output rather than against the
 * machine the suite happens to be running on.
 *
 * This is the only way the macOS and Linux implementations get tested at all
 * from a Windows workstation, and it is the reason the parsers are separate
 * methods from the commands that produce their input.
 */
class PlatformCommandsTest extends TestCase
{
    private const NETSTAT = <<<'OUT'
    Active Connections

      Proto  Local Address          Foreign Address        State           PID
      TCP    0.0.0.0:135            0.0.0.0:0              LISTENING       1044
      TCP    127.0.0.1:8000         0.0.0.0:0              LISTENING       20456
      TCP    127.0.0.1:54001        127.0.0.1:8000         ESTABLISHED     9999
      TCP    [::1]:3000             [::]:0                 LISTENING       7777
    OUT;

    private const SS = <<<'OUT'
    State  Recv-Q Send-Q Local Address:Port  Peer Address:Port Process
    LISTEN 0      511          127.0.0.1:8000       0.0.0.0:*     users:(("php",pid=20456,fd=8))
    LISTEN 0      4096         127.0.0.1:5432       0.0.0.0:*     users:(("postgres",pid=901,fd=5))
    OUT;

    private const LSOF = <<<'OUT'
    COMMAND   PID  USER   FD   TYPE DEVICE SIZE/OFF NODE NAME
    php     20456 ayomi    8u  IPv4 0x1234      0t0  TCP 127.0.0.1:8000 (LISTEN)
    OUT;

    public function test_windows_maps_a_listening_port_to_its_pid(): void
    {
        $windows = new WindowsPlatformCommands;

        $this->assertSame(20456, $windows->parsePortOwner(self::NETSTAT, 8000));
        $this->assertSame(7777, $windows->parsePortOwner(self::NETSTAT, 3000));
    }

    /**
     * An outbound connection to :8000 appears in the same table. Reading it as a
     * listener would attribute the port to the wrong process.
     */
    public function test_windows_ignores_established_connections_to_the_same_port(): void
    {
        $this->assertNotSame(9999, (new WindowsPlatformCommands)->parsePortOwner(self::NETSTAT, 54001));
    }

    public function test_windows_reads_the_image_name_out_of_tasklist_csv(): void
    {
        $out = '"php.exe","20456","Console","1","28,904 K"';

        $this->assertSame('php.exe', (new WindowsPlatformCommands)->parseProcessName($out));
    }

    /** Win32_Process has no cwd, so Windows attributes by command line instead. */
    public function test_windows_has_no_working_directory_command(): void
    {
        $windows = new WindowsPlatformCommands;

        $this->assertNull($windows->processCwdCommand(20456));
        $this->assertNotNull($windows->processCommandLineCommand(20456));
    }

    public function test_linux_reads_a_pid_out_of_ss(): void
    {
        $linux = new LinuxPlatformCommands(new ToolLocator(new ExecutableFinder));

        $this->assertSame(20456, $linux->parsePortOwner(self::SS, 8000));
        $this->assertSame(901, $linux->parsePortOwner(self::SS, 5432));
        $this->assertNull($linux->parsePortOwner(self::SS, 9999));
    }

    /** The same parser has to cope with the lsof fallback's output. */
    public function test_linux_falls_back_to_reading_lsof(): void
    {
        $linux = new LinuxPlatformCommands(new ToolLocator(new ExecutableFinder));

        $this->assertSame(20456, $linux->parsePortOwner(self::LSOF, 8000));
    }

    public function test_macos_reads_a_pid_and_a_working_directory_out_of_lsof(): void
    {
        $mac = new MacPlatformCommands;

        $this->assertSame(20456, $mac->parsePortOwner(self::LSOF, 8000));
        $this->assertSame('/Users/ayomi/code/shop', $mac->parseProcessCwd("p20456\nfcwd\nn/Users/ayomi/code/shop\n"));
    }

    /**
     * @dataProvider implementations
     */
    public function test_every_platform_answers_the_whole_interface(PlatformCommands $commands, PlatformFamily $family): void
    {
        $this->assertSame($family, $commands->family());
        $this->assertNotSame('', $commands->nullDevice());

        // Each accessor either returns a [key, args] pair or null. Nothing in
        // between, and nothing that is a command line.
        foreach ([
            $commands->portOwnerCommand(8000),
            $commands->processNameCommand(1),
            $commands->processCommandLineCommand(1),
            $commands->processCwdCommand(1),
        ] as $command) {
            if ($command === null) {
                continue;
            }

            $this->assertIsString($command[0]);
            $this->assertIsArray($command[1]);
        }
    }

    public static function implementations(): array
    {
        return [
            'windows' => [new WindowsPlatformCommands, PlatformFamily::Windows],
            'macos' => [new MacPlatformCommands, PlatformFamily::MacOS],
            'linux' => [new LinuxPlatformCommands(new ToolLocator(new ExecutableFinder)), PlatformFamily::Linux],
            'unsupported' => [new UnsupportedPlatformCommands, PlatformFamily::Unknown],
        ];
    }

    /**
     * An unrecognised OS must refuse to guess. Layers 1 and 2 still work, which
     * is what "degrades gracefully" has to mean.
     */
    public function test_an_unsupported_platform_offers_no_commands_at_all(): void
    {
        $unsupported = new UnsupportedPlatformCommands;

        $this->assertNull($unsupported->portOwnerCommand(8000));
        $this->assertNull($unsupported->processNameCommand(1));
        $this->assertSame([], $unsupported->terminationCommands(1));
        $this->assertSame([], $unsupported->toolMatrix());
    }
}
