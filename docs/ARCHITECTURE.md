# JetGrid architecture

This document answers the Phase 0 questions. Everything in it is implemented —
where a decision was made differently from the brief, it says so and why.

---

## 1. Directory layout and isolation

JetGrid writes to exactly four places and nowhere else:

```
/opt/jetgrid/                       the application itself
/srv/jetgrid/sites/<domain>/        sites JetGrid creates
    releases/<id>/                    atomic release directories
    current -> releases/<id>          the symlink nginx points at
    shared/                           .env, storage, anything persisted
/var/backups/jetgrid/configs/       timestamped config backups
/etc/nginx/sites-available/jetgrid-<name>    vhosts JetGrid creates
```

Existing projects live in `/var/www/*` and are **read only**. JetGrid has no
code path that writes there.

Isolation is enforced, not assumed:

- **Its own database and DB user.** `jetgrid`, with grants on `jetgrid.*` only.
  It never connects to a project's database.
- **Its own system user.** `jetgrid`, `--system`, `nologin`. Per-site users it
  creates are all `jetgrid-`-prefixed.
- **Its own nginx vhost**, named `jetgrid`. Every vhost it *creates* is
  `jetgrid-`-prefixed, and the sudoers rules only match that prefix, so JetGrid
  cannot enable or remove a symlink for a site it did not create.
- **`PathGuard`** (`app/Support/PathGuard.php`) rejects any write path outside
  the roots above. It does a lexical check (resolving `..` textually, so it works
  for paths that do not exist yet) *and*, when the path exists, a realpath check
  — which is what catches a symlink inside a site directory pointing at `/etc`.

### The `jetgrid-` prefix is load-bearing

It is not a naming convention, it is the security boundary. `P_SITENAME`,
`P_UNIT` and `P_VHOST` in `CommandRegistry` all require it, and — importantly —
the generated sudoers file preserves it as a literal prefix (`jetgrid-*`) rather
than collapsing to `*`. See §3.

---

## 2. How existing sites are detected without touching them

`DiscoveryService` reads and never writes. It runs unchanged with
`JETGRID_READONLY=true`, because every command it uses is marked `isWrite=false`
in the registry.

| Source | How it is read | What it yields |
|---|---|---|
| nginx vhosts | Read files in `sites-enabled`, `conf.d` | `server_name`, `root`, `listen`, `ssl_certificate` |
| apache vhosts | Read files in `sites-enabled` | `ServerName`, `ServerAlias`, `DocumentRoot` |
| systemd units | Read `*.service` / `*.timer` files | `ExecStart`, `Description` |
| supervisor | Read `conf.d/*.conf` | `[program:*]` names |
| PHP-FPM pools | Read `/etc/php/*/fpm/pool.d/*.conf` | pool name, `pm.max_children`, user |
| crontabs | `sudo crontab -l -u <user>` (read-only flag) | schedule + command |
| web roots | Directory listing of `/var/www` etc. | candidate site directories |
| certificates | `sudo certbot certificates`, `openssl x509 -noout -dates` | expiry, SANs, path |

Three details that matter more than they look:

**The nginx reader brace-counts.** A regex cannot correctly find the end of a
`server { }` block once it contains `location { }` blocks. `NginxVhostParser`
counts braces instead. It extracts only the handful of directives needed for
display, and the parsed output is never used to regenerate the file.

**Aliases are not separate sites.** The first `server_name` in a block is the
site; the rest are recorded as aliases. Treating each name as its own site put
five houses on the grid for three projects, which is wrong and also unusable.

**Re-running is safe and idempotent.** An existing site has only its monitoring
columns refreshed — never its protection state. A file whose hash changed since
the last scan is reported as *drift*, not silently reconciled: if something
outside JetGrid edited a config, that is news, not something to fix
automatically.

Everything imported is created `is_protected = true`.

---

## 3. Root vs. unprivileged

**Genuinely needs root (22 commands):** anything touching `/etc`, systemd,
certbot's state, ufw, fail2ban's socket, supervisor's socket, or `useradd`.
`nginx -t` and `nginx -T` need root only because nginx's config tree is
root-readable.

**Runs unprivileged (8 commands):** `df`, `du`, `free`, `cat /proc/loadavg`,
`systemctl show`, `systemctl list-units`, `apt list --upgradable`,
`openssl x509`. systemd permits unauthenticated reads, and apt's `list`
subcommand needs no privilege. Roughly a third of what JetGrid does needs no
elevation at all, including most of the monitoring loop.

**Never granted, and JetGrid never asks for:** `NOPASSWD: ALL`, any shell or
interpreter, any editor, package installation, unrestricted `systemctl`, bare
`certbot renew`, or `chown`/`chmod`/`rm` outside the `jetgrid-` paths.

### The sudoers wildcard problem

This is the one part of the design that is easy to get wrong and worth stating
plainly.

In sudoers, **a wildcard in a command argument matches that entire argument,
slashes included.** So a naive translation of
`systemctl restart {unit}` into `systemctl restart *` would permit
`systemctl restart nginx` — taking down all three live projects — even though
the PHP layer validates the `jetgrid-` prefix perfectly.

That matters because the sudoers file is the defence that still holds if the PHP
layer is bypassed (a bug, an RCE, or someone running commands as the `jetgrid`
user by hand). It has to be narrow on its own terms.

So `SudoersGlobs` maps each argument pattern to either:

- a **glob that keeps its literal prefix** — `jetgrid-*`,
  `/etc/nginx/sites-available/jetgrid-*` — or
- a **list of literal values**, expanded into one sudoers line each
  (`tcp`/`udp`, the PHP versions).

Anything that genuinely cannot be narrowed — a certbot `--cert-name`, which is
an unbounded domain — is emitted as `*` **and listed in a `RESIDUAL RISK`
section of the artifact**, naming the command and the reason. For those, the
restriction to managed resources exists only in the application layer, and the
file says so rather than implying otherwise.

`SudoersArtifactTest` asserts all of this.

Generate and review with:

```bash
php artisan jetgrid:sudoers
php artisan jetgrid:commands --markdown
```

Both are generated from `CommandRegistry`, which is the single source of truth,
so the docs cannot drift from what the code can actually run.

---

## 4. Database schema

JetGrid's own database. It never reads or writes a project's schema.

| Table | Purpose | Notes |
|---|---|---|
| `users` | accounts | `role`, `plan_id`, TOTP secret + recovery codes (encrypted) |
| `plans` | Free / Starter / Pro / Unlimited | null limit = unlimited |
| `sites` | one row per site, managed or adopted | `management_mode`, `is_protected`, `grid_x`/`grid_z`, footprint |
| `protected_resources` | everything discovery found | `type`, `fingerprint` (drift detection), `excerpt`, `parsed` |
| `certificates` | managed **and** adopted | `is_managed` decides whether any action is offered |
| `certificate_issuances` | one row per attempt | pre-empts the Let's Encrypt 5-per-week duplicate limit |
| `deployments` | Envoyer-style releases | `release_path`, `previous_release_path` for rollback |
| `backups` | DB + file archives | `verified_at`, `verify_result` — an unverified backup does not count |
| `config_backups` | safety constraint #4 | path, checksum, and the contents inline for one-click restore |
| `metrics` | time series | server-wide when `site_id` is null |
| `health_checks` | uptime + response time | drives the uptime component of the health score |
| `cron_jobs` | discovered and managed | discovered ones are `is_protected` |
| `alert_channels` | mail / telegram / webhook | seeded inactive and unconfigured |
| `audit_logs` | safety constraint #7 | append-only; see below |
| `settings` | runtime flags | the kill switch lives here, combined with `.env` fail-closed |

**`sites.is_protected` is denormalised** from `management_mode` on purpose. A
protection check should never depend on a string comparison being written
correctly at every call site.

**`audit_logs` is append-only.** A row is opened *before* its command runs and
closed after, so a command that hangs or crashes the process still leaves a
trace of having been attempted. The model refuses updates and deletes. For the
strong version, revoke `UPDATE`/`DELETE` on that table from the app's DB user —
nothing in the code needs them.

---

## 5. The 3D dashboard's data pipeline

**Polling, not websockets.** This was the brief's open question, and the answer
is polling for a specific reason: Reverb or soketi means another long-lived PHP
process and roughly another 80–150 MB of RAM on an instance whose entire problem
is that it is already hosting three projects. The scene needs fresh data every
few seconds, not every few milliseconds.

How it works:

- `GET /jetgrid/api/grid` returns the whole scene as one JSON document, behind
  the panel's auth guard.
- The server-side work is **cached for 5 seconds**, so several open tabs cannot
  multiply into real load.
- **The server sets the poll interval.** The payload carries `pollMs`: 15 s
  normally, dropping to 3 s while any deployment is in progress. So the blue
  "deploying" beacon feels live without a persistent connection, and the loop
  slows back down on its own.
- The tab **stops polling when hidden**, so a dashboard left open on a second
  monitor overnight costs nothing.
- On error it backs off to 30 s rather than hammering a dead endpoint.

If you later want true realtime, the seam is `useGridData.js` — swapping it for
an Echo subscription would not touch the scene.

### Rendering

- **React 18 + `@react-three/fiber` + `@react-three/drei`, mounted in a custom
  Filament page** — not Inertia. Filament already owns authentication,
  navigation, theming and every other screen. Adding Inertia for one route would
  mean a second routing stack and a second auth surface for a single canvas that
  needs a div and a JSON endpoint. React mounts into the page; the rest of the
  app stays Livewire.
- **Instanced meshes.** All house bodies are one draw call, all roofs another,
  all beacons a third, and the air-traffic layer is a single `Points` with
  positions updated in place. The whole settlement is a handful of draw calls
  regardless of site count.
- **Beacon blink is a colour write, not a material change.** Rate comes from the
  server (`BeaconColor::blinkHz`), so "faster = more urgent" is defined once, in
  PHP.
- **Grid lines use drei's `<Grid>`, not three's `gridHelper`.** The helper draws
  1 px `GL_LINES` that all but vanish at this camera distance; drei's is a shader
  on a plane, so lines keep their weight and fade with distance instead of ending
  at a hard square edge.
- **Positions are assigned once and stored** (`grid_x`, `grid_z`, allocated on a
  square spiral). A house that moves between polls destroys your ability to
  learn where your own projects are on the map.
- **`prefers-reduced-motion` gets a real 2D fallback**, not a slowed-down 3D
  scene: a static card grid carrying the same information, with the strobe
  disabled. There is also a manual toggle for people who just prefer it.

### Colour

The brand neon `#39FF14` has a contrast ratio of about **1.4:1 on white** — it
is unreadable as text or a border. So the brand is two tokens with one job each:

- `--jg-neon` `#39FF14` — the real neon, used **only** on the dark 3D canvas.
- `--jg-accent` `#17800F` — the desaturated variant for the light UI. **5.09:1
  on white**, so it passes WCAG AA for body text.

The Filament palette is generated from `#17800F`. Nothing in the panel uses raw
neon on white.

---

## 6. Where the safety constraints live

| # | Constraint | Implementation |
|---|---|---|
| 1 | Discovery is read-only; imports are protected | `DiscoveryService`, `ProtectedResourceGuard`, `Site::booted()`, `SitePolicy` |
| 2 | Separate everything | `config/jetgrid.php` roots, `PathGuard`, `jetgrid-` prefix |
| 3 | Narrow sudo | `CommandRegistry`, `SudoersGlobs`, `jetgrid:sudoers` |
| 4 | Backup before write | `config_backups` table, `ConfigBackup` |
| 5 | Validate before reload | `nginx.test` before `nginx.reload` |
| 6 | Dry-run mode | `CommandRunner::resolveDryRun()`, default true, `force_dry_run` to pin it |
| 7 | Audit log | `AuditLog::open()` / `close()`, immutable |
| 8 | Kill switch | `KillSwitch`, fail-closed, default true |

The order of checks in `CommandRunner::run()` is deliberate and documented in
that file. The protected gate is checked **before** the kill switch, so the
message you get names the more fundamental reason.
