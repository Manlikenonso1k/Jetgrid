<?php

namespace App\Services\Local\Platform;

use App\Enums\PlatformFamily;

/**
 * Which OS this is, and — the part that matters for safety — whether it looks
 * like somebody's desk or somebody's fleet.
 *
 * isLocalWorkstation() is L0 check 4, and it is the only one of the four that
 * cannot be satisfied by editing a config file. The other three are declarations
 * of intent; this one is evidence. It answers by looking for the marks a server
 * leaves and nothing else — a workstation has no positive signature, so the
 * verdict is "no server evidence found", never "this is definitely a laptop".
 *
 * Every signal is recorded with its outcome so `jetgrid:doctor` can show the
 * working. A refusal nobody can explain gets worked around.
 */
class PlatformDetector
{
    /** @var list<array{signal:string,server:bool,detail:string}>|null */
    private ?array $evidence = null;

    public function __construct(private readonly ToolLocator $tools) {}

    public function family(): PlatformFamily
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => PlatformFamily::Windows,
            'Darwin' => PlatformFamily::MacOS,
            'Linux' => PlatformFamily::Linux,
            default => PlatformFamily::Unknown,
        };
    }

    public function commands(): PlatformCommands
    {
        return match ($this->family()) {
            PlatformFamily::Windows => new WindowsPlatformCommands,
            PlatformFamily::MacOS => new MacPlatformCommands,
            PlatformFamily::Linux => new LinuxPlatformCommands($this->tools),
            PlatformFamily::Unknown => new UnsupportedPlatformCommands,
        };
    }

    public function isLocalWorkstation(): bool
    {
        foreach ($this->workstationEvidence() as $signal) {
            if ($signal['server']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The signals behind the verdict, in the order they are checked.
     *
     * @return list<array{signal:string,server:bool,detail:string}>
     */
    public function workstationEvidence(): array
    {
        return $this->evidence ??= array_values(array_filter([
            $this->headlessSystemdSignal(),
            $this->cloudInitSignal(),
            $this->metadataEndpointSignal(),
            $this->containerSignal(),
        ]));
    }

    /**
     * PID 1 is systemd (or another init) and nothing on the box can draw a
     * window. Checked on the filesystem rather than through $DISPLAY, because
     * under php-fpm the environment is empty even on a desktop and reading it
     * would mark every Linux workstation as a server.
     *
     * @return array{signal:string,server:bool,detail:string}|null
     */
    private function headlessSystemdSignal(): ?array
    {
        if ($this->family() !== PlatformFamily::Linux) {
            return null;
        }

        $init = trim((string) @file_get_contents('/proc/1/comm'));

        if ($init === '') {
            return null;
        }

        $desktop = array_values(array_filter([
            '/usr/share/xsessions',
            '/usr/share/wayland-sessions',
            '/tmp/.X11-unix',
        ], static fn (string $path): bool => is_dir($path) && (glob($path.'/*') ?: []) !== []));

        $headless = $init === 'systemd' && $desktop === [];

        return [
            'signal' => 'headless init',
            'server' => $headless,
            'detail' => $headless
                ? 'PID 1 is systemd and no desktop session is installed.'
                : 'PID 1 is '.$init.($desktop === [] ? '.' : ', desktop session present ('.implode(', ', $desktop).').'),
        ];
    }

    /** @return array{signal:string,server:bool,detail:string}|null */
    private function cloudInitSignal(): ?array
    {
        if ($this->family() === PlatformFamily::Windows) {
            return null;
        }

        $found = array_values(array_filter([
            '/var/lib/cloud/instance',
            '/run/cloud-init/instance-data.json',
        ], static fn (string $path): bool => file_exists($path)));

        return [
            'signal' => 'cloud-init',
            'server' => $found !== [],
            'detail' => $found === []
                ? 'No cloud-init instance data on disk.'
                : 'Instance provisioned by cloud-init: '.implode(', ', $found),
        ];
    }

    /**
     * The link-local metadata service. A 150ms connect: on a workstation the
     * address is unrouted and fails immediately, and on an instance it answers
     * on the first packet.
     *
     * @return array{signal:string,server:bool,detail:string}|null
     */
    private function metadataEndpointSignal(): ?array
    {
        if (! config('jetgrid.local.metadata_probe', true)) {
            return null;
        }

        [$host, $port] = config('jetgrid.local.metadata_endpoint', ['169.254.169.254', 80]);

        $socket = @stream_socket_client(
            'tcp://'.$host.':'.$port,
            $errno,
            $errstr,
            0.15,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket !== false) {
            fclose($socket);
        }

        return [
            'signal' => 'cloud metadata endpoint',
            'server' => $socket !== false,
            'detail' => $socket !== false
                ? 'Reachable at '.$host.':'.$port.' — this is a cloud instance.'
                : 'Not reachable at '.$host.':'.$port.'.',
        ];
    }

    /**
     * A container is not a workstation. It is also where a "local" JetGrid image
     * would most plausibly be run on a server by mistake.
     *
     * @return array{signal:string,server:bool,detail:string}|null
     */
    private function containerSignal(): ?array
    {
        if ($this->family() === PlatformFamily::Windows) {
            return null;
        }

        $inContainer = file_exists('/.dockerenv') || file_exists('/run/.containerenv');

        return [
            'signal' => 'container runtime',
            'server' => $inContainer,
            'detail' => $inContainer
                ? 'Running inside a container.'
                : 'Not running inside a container.',
        ];
    }
}
