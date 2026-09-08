<?php

namespace App\Services\Privilege;

/**
 * How each validated argument is expressed in sudoers.
 *
 * This exists because a naive `{placeholder}` → `*` translation is dangerously
 * wrong. In sudoers, a wildcard in a COMMAND ARGUMENT matches the entire
 * argument, slashes included — so `systemctl restart *` grants
 * `systemctl restart nginx`, and the `jetgrid-` prefix that PHP so carefully
 * validates simply does not exist at the sudo layer.
 *
 * That matters because the sudoers file is the defence that still holds if the
 * PHP layer is bypassed entirely (a bug, an RCE, someone running commands as
 * the jetgrid user by hand). It has to be narrow on its own terms.
 *
 * So each argument pattern maps to either:
 *   - a GLOB that keeps its literal prefix (`jetgrid-*`), or
 *   - a LIST of literal values, which expands into one sudoers line each.
 *
 * Anything not listed here falls back to `*` and is reported as a broad rule in
 * the generated artifact, so the residual risk is visible rather than implied.
 */
final class SudoersGlobs
{
    /** @return array<string,string|list<string>> */
    public static function map(): array
    {
        return [
            // Prefix-preserving globs. These are the ones that carry the weight:
            // an argument matching `jetgrid-*` can never name an existing
            // project's unit, site or user.
            CommandRegistry::P_SITENAME => 'jetgrid-*',
            CommandRegistry::P_UNIT => 'jetgrid-*',
            CommandRegistry::P_VHOST => '/etc/nginx/sites-available/jetgrid-*',
            CommandRegistry::P_CERTPATH => '/etc/letsencrypt/live/*/fullchain.pem',
            CommandRegistry::P_WEBROOT => '/var/www/letsencrypt',

            // Small, closed sets: expanded to explicit literals so there is no
            // wildcard at all.
            CommandRegistry::P_PROTO => ['tcp', 'udp'],
            CommandRegistry::P_PHPVER => ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'],
        ];
    }

    /**
     * Arguments that genuinely cannot be narrowed in sudoers, with the reason.
     * Surfaced in the artifact rather than glossed over.
     *
     * @return array<string,string>
     */
    public static function broadReasons(): array
    {
        return [
            CommandRegistry::P_DOMAIN => 'A domain is unbounded, so sudo cannot restrict this to JetGrid-managed domains. The restriction to managed sites is enforced in PHP only (protected gate + policy). See the warning in the artifact.',
            CommandRegistry::P_EMAIL => 'Contact address passed to the CA. Low consequence.',
            CommandRegistry::P_JAIL => 'fail2ban jail name; the subcommand is already fixed.',
            CommandRegistry::P_IPV4 => 'An IP address; the subcommand is already fixed to unbanip.',
            CommandRegistry::P_PORT => 'A port number; the subcommand is already fixed to allow/deny.',
            CommandRegistry::P_SYSUSER => 'Username for a read-only crontab -l.',
            CommandRegistry::P_ANYUNIT => 'Read-only systemctl show; needs to cover adopted units in order to monitor them.',
            CommandRegistry::P_READPATH => 'Read-only du -sb; needs to cover adopted site directories in order to size them.',
        ];
    }
}
