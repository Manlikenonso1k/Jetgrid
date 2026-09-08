<?php

namespace App\Services\Privilege;

use App\Exceptions\CommandNotWhitelistedException;

/**
 * THE whitelist. Safety constraint #3.
 *
 * Nothing outside this file can be executed by JetGrid. This class is also the
 * single source of truth for two deliverables:
 *   - artifacts/jetgrid-sudoers    (php artisan jetgrid:sudoers)
 *   - docs/PRIVILEGED-COMMANDS.md  (php artisan jetgrid:commands --markdown)
 * so the documentation cannot drift away from what the code can actually run.
 */
class CommandRegistry
{
    /** @var array<string,PrivilegedCommand>|null */
    private ?array $commands = null;

    // Argument patterns. Deliberately strict — an argument that does not match
    // never reaches the process layer.
    public const P_DOMAIN   = '/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i';
    public const P_SITENAME = '/^jetgrid-[a-z0-9]([a-z0-9._-]{0,60}[a-z0-9])?$/';
    public const P_UNIT     = '/^jetgrid-[a-z0-9@._-]{1,64}\.(service|timer)$/';
    public const P_PHPVER   = '/^(7\.4|8\.[0-9])$/';
    public const P_EMAIL    = '/^[^@\s]{1,64}@[^@\s]{1,190}$/';
    public const P_PORT     = '/^(?:[1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5])$/';
    public const P_PROTO    = '/^(tcp|udp)$/';
    public const P_IPV4     = '/^(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)$/';
    public const P_JAIL     = '/^[a-z0-9-]{1,32}$/';
    public const P_SYSUSER  = '/^[a-z_][a-z0-9_-]{0,31}$/';
    public const P_READPATH = '#^/[A-Za-z0-9._/-]{1,200}$#';
    public const P_ANYUNIT  = '/^[a-zA-Z0-9@._-]{1,128}\.(service|timer|socket)$/';
    // Managed write paths only: JetGrid's own vhost dir, with its mandatory prefix.
    public const P_VHOST    = '#^/etc/nginx/sites-available/jetgrid-[a-z0-9][a-z0-9._-]{0,60}$#';
    public const P_CERTPATH = '#^/etc/letsencrypt/live/[a-z0-9.-]{1,253}/fullchain\.pem$#';
    public const P_WEBROOT  = '#^/var/www/letsencrypt$#';

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
    private function define(): array
    {
        $c = [];

        $add = function (string $key, array $argv, array $patterns, bool $write, bool $root, string $why) use (&$c): void {
            $c[$key] = new PrivilegedCommand($key, $argv, $patterns, $write, $root, $why);
        };

        // ---- READ-ONLY: discovery & monitoring -------------------------------
        // These are the only commands JetGrid runs while JETGRID_READONLY=true.

        $add('nginx.test', ['sudo', '/usr/sbin/nginx', '-t'], [], false, true,
            'Validate nginx configuration. Required by safety constraint #5 before any reload. Read-only: -t never writes.');

        $add('nginx.dump', ['sudo', '/usr/sbin/nginx', '-T'], [], false, true,
            'Dump the fully-resolved nginx config for read-only discovery of existing vhosts.');

        $add('systemctl.list', ['/usr/bin/systemctl', 'list-units', '--type=service', '--all', '--no-pager', '--output=json'], [], false, false,
            'Enumerate services for discovery. Unprivileged: systemd permits unauthenticated reads.');

        $add('systemctl.status', ['/usr/bin/systemctl', 'show', '{unit}', '--no-pager'], ['unit' => self::P_ANYUNIT], false, false,
            'Read one unit state for the monitoring panel. Unprivileged read; adopted units are readable but locked.');

        $add('supervisor.status', ['sudo', '/usr/bin/supervisorctl', 'status'], [], false, true,
            'Read supervisor program states. supervisorctl needs its socket, hence sudo. Read-only subcommand.');

        $add('disk.usage', ['/usr/bin/df', '-PB1'], [], false, false,
            'Disk free/used for the capacity estimator. Unprivileged.');

        $add('disk.dir_size', ['/usr/bin/du', '-sb', '{path}'], ['path' => self::P_READPATH], false, false,
            'Per-site disk footprint for house sizing and cost attribution. Unprivileged; fails closed on unreadable dirs.');

        $add('mem.info', ['/usr/bin/free', '-b'], [], false, false,
            'Total/used RAM for the capacity estimator. Unprivileged.');

        $add('load.avg', ['/usr/bin/cat', '/proc/loadavg'], [], false, false,
            'Load average sparkline. Unprivileged.');

        $add('apt.upgradable', ['/usr/bin/apt', 'list', '--upgradable'], [], false, false,
            'Pending OS security updates, surfaced as the yellow beacon. Read-only apt subcommand; no root needed.');

        $add('fail2ban.status', ['sudo', '/usr/bin/fail2ban-client', 'status'], [], false, true,
            'List fail2ban jails. Requires the fail2ban socket, hence sudo. Read-only subcommand.');

        $add('fail2ban.jail', ['sudo', '/usr/bin/fail2ban-client', 'status', '{jail}'], ['jail' => self::P_JAIL], false, true,
            'Banned-IP list for one jail. Read-only subcommand.');

        $add('ufw.status', ['sudo', '/usr/sbin/ufw', 'status', 'numbered'], [], false, true,
            'Firewall rules for the security panel. ufw has no unprivileged read mode.');

        $add('cert.inspect', ['/usr/bin/openssl', 'x509', '-in', '{certpath}', '-noout', '-dates', '-subject', '-serial'], ['certpath' => self::P_CERTPATH], false, false,
            'Read expiry for BOTH managed and adopted certs. Read-only — this is how an adopted cert is displayed without ever being touched.');

        $add('certbot.list', ['sudo', '/usr/bin/certbot', 'certificates'], [], false, true,
            'Inventory existing certificates, including ones JetGrid did not issue. Read-only subcommand.');

        $add('crontab.list', ['sudo', '/usr/bin/crontab', '-l', '-u', '{user}'], ['user' => self::P_SYSUSER], false, true,
            'Read a crontab for the cron manager. Read-only flag; adopted crons are displayed locked.');

        // ---- WRITE: refused entirely while JETGRID_READONLY=true -------------

        $add('nginx.reload', ['sudo', '/bin/systemctl', 'reload', 'nginx'], [], true, true,
            'Apply a new managed vhost. Only ever reached after nginx.test passes (safety constraint #5). Reload, not restart, so live sites keep serving.');

        $add('nginx.enable_site', ['sudo', '/bin/ln', '-sfn', '{vhost}', '/etc/nginx/sites-enabled/{sitename}'], ['vhost' => self::P_VHOST, 'sitename' => self::P_SITENAME], true, true,
            'Enable a JetGrid-created vhost. Both paths are constrained to the jetgrid- prefix, so an existing site symlink cannot be named.');

        $add('nginx.disable_site', ['sudo', '/bin/rm', '-f', '/etc/nginx/sites-enabled/{sitename}'], ['sitename' => self::P_SITENAME], true, true,
            'Disable a JetGrid-created vhost. The pattern makes it impossible to name a non-JetGrid site.');

        $add('phpfpm.reload', ['sudo', '/bin/systemctl', 'reload', 'php{phpver}-fpm'], ['phpver' => self::P_PHPVER], true, true,
            'Apply a new managed PHP-FPM pool. Reload rather than restart so existing workers drain instead of dropping connections held by other sites.');

        $add('systemd.reload_daemon', ['sudo', '/bin/systemctl', 'daemon-reload'], [], true, true,
            'Pick up a newly written JetGrid unit file. Global but non-disruptive: it re-reads unit files, it does not restart anything.');

        $add('systemd.restart_managed', ['sudo', '/bin/systemctl', 'restart', '{unit}'], ['unit' => self::P_UNIT], true, true,
            'Restart a JetGrid-created worker. The jetgrid- prefix in the pattern is what stops this from ever touching an existing project unit.');

        $add('certbot.issue.http01', [
            'sudo', '/usr/bin/certbot', 'certonly', '--non-interactive', '--agree-tos',
            '--webroot', '-w', '{webroot}', '-d', '{domain}', '-m', '{email}',
            '--cert-name', '{domain}', '--keep-until-expiring',
        ], ['webroot' => self::P_WEBROOT, 'domain' => self::P_DOMAIN, 'email' => self::P_EMAIL], true, true,
            'Issue a cert via HTTP-01 for a JetGrid-managed domain. --cert-name is pinned to the domain so an existing certificate lineage cannot be overwritten.');

        $add('certbot.issue.dns01', [
            'sudo', '/usr/bin/certbot', 'certonly', '--non-interactive', '--agree-tos',
            '--manual', '--preferred-challenges', 'dns', '-d', '{domain}', '-m', '{email}',
            '--cert-name', '{domain}',
        ], ['domain' => self::P_DOMAIN, 'email' => self::P_EMAIL], true, true,
            'Issue via DNS-01, for wildcards or domains not reachable on port 80.');

        $add('certbot.renew', ['sudo', '/usr/bin/certbot', 'renew', '--cert-name', '{domain}', '--non-interactive'], ['domain' => self::P_DOMAIN], true, true,
            'Renew ONE named managed cert. Deliberately not bare "certbot renew", which would sweep up the adopted certificates too.');

        $add('certbot.revoke', ['sudo', '/usr/bin/certbot', 'revoke', '--cert-name', '{domain}', '--non-interactive', '--delete-after-revoke'], ['domain' => self::P_DOMAIN], true, true,
            'Revoke a managed cert. Blocked for adopted sites by the protected gate long before it reaches sudo.');

        $add('ufw.allow', ['sudo', '/usr/sbin/ufw', 'allow', '{port}/{proto}'], ['port' => self::P_PORT, 'proto' => self::P_PROTO], true, true,
            'Open a port. The UI warns loudly before any rule touching 22 (Feature 5).');

        $add('ufw.deny', ['sudo', '/usr/sbin/ufw', 'deny', '{port}/{proto}'], ['port' => self::P_PORT, 'proto' => self::P_PROTO], true, true,
            'Close a port. The same port-22 warning applies.');

        $add('fail2ban.unban', ['sudo', '/usr/bin/fail2ban-client', 'set', '{jail}', 'unbanip', '{ip}'], ['jail' => self::P_JAIL, 'ip' => self::P_IPV4], true, true,
            'Release an IP you locked yourself out with. Narrow: only unbanip, never a jail reconfigure.');

        $add('user.create_site_user', ['sudo', '/usr/sbin/useradd', '--system', '--no-create-home', '--shell', '/usr/sbin/nologin', '{sitename}'], ['sitename' => self::P_SITENAME], true, true,
            'Create the per-site system user. Locked to the jetgrid- prefix and to a nologin shell.');

        return $c;
    }

    /** @return list<PrivilegedCommand> */
    public function requiringRoot(): array
    {
        return array_values(array_filter($this->all(), static fn (PrivilegedCommand $c) => $c->needsRoot));
    }

    /** @return list<PrivilegedCommand> */
    public function unprivileged(): array
    {
        return array_values(array_filter($this->all(), static fn (PrivilegedCommand $c) => ! $c->needsRoot));
    }

    /** @return list<PrivilegedCommand> */
    public function writes(): array
    {
        return array_values(array_filter($this->all(), static fn (PrivilegedCommand $c) => $c->isWrite));
    }

    /** @return list<PrivilegedCommand> */
    public function reads(): array
    {
        return array_values(array_filter($this->all(), static fn (PrivilegedCommand $c) => ! $c->isWrite));
    }
}
