<?php

namespace App\Services\Local;

use App\Exceptions\CommandNotWhitelistedException;
use App\Services\Privilege\CommandRegistry;
use App\Services\Privilege\PrivilegedCommand;

/**
 * THE whitelist for local mode. Same rule as App\Services\Privilege\CommandRegistry:
 * nothing outside this file can be executed.
 *
 * It is a SEPARATE registry rather than more entries in the privileged one for
 * three reasons:
 *
 *   - Nothing here is ever run with sudo. needsRoot is false on every entry, so
 *     none of it can leak into the generated sudoers artifact.
 *   - These commands are resolved from PATH (php, npm, git) rather than pinned
 *     to absolute paths, because a workstation has no fixed layout. The
 *     privileged registry's absolute paths are a security property there and
 *     would be a lie here.
 *   - They are gated by LocalModeGate, not by the production kill switch. Mixing
 *     the two would make one gate look like it covered the other.
 *
 * docs/LOCAL-MODE-COMMANDS.md is generated from this class, so the documented
 * list cannot drift away from what the code can actually run.
 */
class LocalCommandRegistry
{
    /** @var array<string,PrivilegedCommand>|null */
    private ?array $commands = null;

    public const P_PID = '/^[1-9][0-9]{0,9}$/';

    public const P_PORT = CommandRegistry::P_PORT;

    /**
     * An absolute path on any of the three platforms: drive-letter, UNC, or
     * POSIX. Spaces are allowed because "Documents\My Projects" is normal; what
     * is excluded is the set of characters that are never legal in a path and
     * are exactly what a redirection or wildcard attempt looks like. A leading
     * "-" is impossible here, so no path can be read as a flag.
     */
    public const P_PATH = '#^(?:[A-Za-z]:[\\\\/]|\\\\\\\\[^\\\\/:*?"<>|\r\n]{1,63}[\\\\/]|/)[^\r\n\x00*?"<>|]{0,4000}$#';

    /** A package.json script name. Deliberately narrow. */
    public const P_SCRIPT = '/^[a-zA-Z0-9][a-zA-Z0-9:_-]{0,40}$/';

    /** @return array<string,PrivilegedCommand> */
    public function all(): array
    {
        return $this->commands ??= $this->define();
    }

    public function get(string $key): PrivilegedCommand
    {
        return $this->all()[$key] ?? throw new CommandNotWhitelistedException($key);
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /** @return array<string,PrivilegedCommand> */
    public function withPrefix(string $prefix): array
    {
        return array_filter(
            $this->all(),
            static fn (string $key): bool => str_starts_with($key, $prefix.'.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return list<PrivilegedCommand> */
    public function reads(): array
    {
        return array_values(array_filter($this->all(), static fn (PrivilegedCommand $c) => ! $c->isWrite));
    }

    /** @return list<PrivilegedCommand> */
    public function writes(): array
    {
        return array_values(array_filter($this->all(), static fn (PrivilegedCommand $c) => $c->isWrite));
    }

    /** @return array<string,PrivilegedCommand> */
    private function define(): array
    {
        $c = [];

        $add = function (string $key, array $argv, array $patterns, bool $write, string $why) use (&$c): void {
            // needsRoot is hardcoded false: a local dev server that needs root
            // is a local dev server JetGrid should not be starting.
            $c[$key] = new PrivilegedCommand($key, $argv, $patterns, $write, false, $why);
        };

        // ---- L5 LAYER 3: process attribution, Windows ------------------------

        $add('windows.port.owner', ['netstat', '-ano', '-p', 'TCP'], [], false,
            'Map a listening TCP port to a PID. netstat has no port filter, so the whole table is read once and filtered in PHP - cheaper than one process per port.');

        $add('windows.process.name', ['tasklist', '/FI', 'PID eq {pid}', '/NH', '/FO', 'CSV'], ['pid' => self::P_PID], false,
            'Image name for an attributed PID, so the panel can say "php.exe" rather than a bare number.');

        // Win32_Process has no working-directory property, which is why L3 says
        // the command line is what gets matched against the project path.
        $add('windows.process.commandline', [
            'powershell', '-NoProfile', '-NonInteractive', '-Command',
            'Get-CimInstance Win32_Process -Filter "ProcessId = {pid}" | Select-Object -ExpandProperty CommandLine',
        ], ['pid' => self::P_PID], false,
            'Read a PID command line to confirm which project owns a port. The only interpolation is a PID constrained to digits, so the -Command script cannot be extended by an argument value.');

        $add('windows.process.kill', ['taskkill', '/PID', '{pid}', '/T', '/F'], ['pid' => self::P_PID], true,
            'Stop a dev server and its children. /T because npm and artisan both spawn a child that would otherwise keep holding the port.');

        // ---- L5 LAYER 3: process attribution, macOS --------------------------

        $add('macos.port.owner', ['lsof', '-nP', '-iTCP:{port}', '-sTCP:LISTEN'], ['port' => self::P_PORT], false,
            'PID listening on a port. -n and -P skip DNS and service-name lookups, which is the difference between 20ms and a second.');

        $add('macos.process.cwd', ['lsof', '-a', '-p', '{pid}', '-d', 'cwd', '-Fn'], ['pid' => self::P_PID], false,
            'Working directory of an attributed PID. -Fn gives machine-readable output rather than a table whose shape depends on terminal width.');

        $add('macos.process.name', ['ps', '-o', 'comm=', '-p', '{pid}'], ['pid' => self::P_PID], false,
            'Executable name for an attributed PID.');

        $add('macos.process.commandline', ['ps', '-o', 'command=', '-p', '{pid}'], ['pid' => self::P_PID], false,
            'Full command line, used when lsof cannot read the working directory and the path has to be matched out of the arguments instead.');

        // ---- L5 LAYER 3: process attribution, Linux --------------------------

        $add('linux.port.owner', ['ss', '-tlnp'], [], false,
            'Listening sockets with owning PIDs. Reads the whole table once, for the same reason as netstat.');

        $add('linux.port.owner.lsof', ['lsof', '-nP', '-iTCP:{port}', '-sTCP:LISTEN'], ['port' => self::P_PORT], false,
            'Fallback for hosts with no iproute2. Registered separately so the doctor can report which of the two is actually present.');

        $add('linux.process.cwd', ['readlink', '/proc/{pid}/cwd'], ['pid' => self::P_PID], false,
            'Working directory of an attributed PID, straight from procfs. Fails soft when the process belongs to another user.');

        $add('linux.process.name', ['ps', '-o', 'comm=', '-p', '{pid}'], ['pid' => self::P_PID], false,
            'Executable name for an attributed PID.');

        $add('linux.process.commandline', ['ps', '-o', 'args=', '-p', '{pid}'], ['pid' => self::P_PID], false,
            'Full command line, used to match a project path when procfs is unreadable.');

        // ---- Unix process control -------------------------------------------

        $add('unix.process.term', ['kill', '-15', '{pid}'], ['pid' => self::P_PID], true,
            'SIGTERM, giving a dev server the chance to release its port cleanly. Used only when ext-posix is unavailable - posix_kill needs no process at all.');

        $add('unix.process.kill', ['kill', '-9', '{pid}'], ['pid' => self::P_PID], true,
            'SIGKILL, after SIGTERM has been given time to work.');

        // ---- L5 LAYER 4: Docker ---------------------------------------------

        $add('docker.compose.ls', ['docker', 'compose', 'ls', '--format', 'json'], [], false,
            'List running compose projects, to match a container back to a discovered folder by compose project name.');

        $add('docker.ps', ['docker', 'ps', '--format', 'json'], [], false,
            'List running containers with their labels, including com.docker.compose.project.working_dir.');

        $add('docker.compose.up', ['docker', 'compose', 'up', '-d'], [], true,
            'Start a project that ships a compose file. Run with the project directory as the working directory, so it needs no path argument.');

        $add('docker.compose.down', ['docker', 'compose', 'down'], [], true,
            'Stop a compose project. The counterpart of the above, and the only stop path for a Docker-run project.');

        // ---- Repo state (read-only, working directory = the project) ---------

        $add('git.branch', ['git', '--no-optional-locks', 'rev-parse', '--abbrev-ref', 'HEAD'], [], false,
            'Current branch for the detail panel. Read-only plumbing command.');

        /*
         * --no-optional-locks is not cosmetic, it is what makes this read-only.
         *
         * Plain `git status` refreshes the on-disk index: it takes .git/index.lock
         * and rewrites .git/index. That is a WRITE inside a discovered project,
         * which this module is forbidden to do — and it was observed happening,
         * changing the mtime of .git in every scanned repository. The flag is
         * git's own answer for exactly this case (it is what editors use to poll
         * a repo they do not own).
         */
        $add('git.dirty', ['git', '--no-optional-locks', 'status', '--porcelain'], [], false,
            'Whether the working tree is dirty. --porcelain because the human format is localised and changes between git versions; --no-optional-locks so reading a project never writes to its .git directory.');

        // ---- L6: start commands ---------------------------------------------
        // All are spawned detached with the project directory as the working
        // directory. None takes a path argument, so a start command can never be
        // pointed at a directory other than the one it was launched for.

        $add('start.laravel', ['php', 'artisan', 'serve', '--host=127.0.0.1', '--port={port}'], ['port' => self::P_PORT], true,
            'Laravel dev server. --host is passed explicitly rather than trusting artisan defaults, so a discovered project is never exposed to the LAN.');

        $add('start.php_builtin', ['php', '-S', '127.0.0.1:{port}'], ['port' => self::P_PORT], true,
            'The PHP built-in server, for static sites and PHP projects with no framework runner.');

        $add('start.node', ['npm', 'run', '{script}'], ['script' => self::P_SCRIPT], true,
            'Node dev server via npm. The script name comes from the project package.json and is pattern-checked before it is used.');

        $add('start.node.yarn', ['yarn', 'run', '{script}'], ['script' => self::P_SCRIPT], true,
            'Same, for a project with a yarn.lock. Using the wrong package manager is the most common reason a dev server will not boot.');

        $add('start.node.pnpm', ['pnpm', 'run', '{script}'], ['script' => self::P_SCRIPT], true,
            'Same, for a project with a pnpm-lock.yaml.');

        $add('start.django', ['python', 'manage.py', 'runserver', '127.0.0.1:{port}'], ['port' => self::P_PORT], true,
            'Django dev server, bound to loopback.');

        $add('start.rails', ['bin/rails', 'server', '-p', '{port}'], ['port' => self::P_PORT], true,
            'Rails server via the project binstub, which is what picks up its bundle.');

        $add('start.go', ['go', 'run', '.'], [], true,
            'Go program in the project root. Go has no port convention, so the resolved port is informational for this type.');

        // ---- L6: explicit, confirmed maintenance -----------------------------
        // HARD RULE: never automatic. ProcessController refuses to run any of
        // these unless the caller echoes back the literal rendered command.

        $add('install.composer', ['composer', 'install'], [], true,
            'Install PHP dependencies. A one-click action behind a confirmation showing the literal command - never part of a start.');

        $add('install.npm', ['npm', 'install'], [], true,
            'Install Node dependencies. Same confirmation rule.');

        $add('install.yarn', ['yarn', 'install'], [], true,
            'Install Node dependencies for a yarn project.');

        $add('install.pnpm', ['pnpm', 'install'], [], true,
            'Install Node dependencies for a pnpm project.');

        $add('laravel.migrate', ['php', 'artisan', 'migrate'], [], true,
            'Run a discovered Laravel project own migrations. The most destructive action in this registry, and the reason the confirmation echoes the literal command.');

        // ---- Nice-to-have, fails soft ---------------------------------------

        $add('editor.open', ['code', '{path}'], ['path' => self::P_PATH], false,
            'Open a project in VS Code. An absent CLI is not an error - the action is simply not offered.');

        return $c;
    }
}
