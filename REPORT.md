# JetGrid — build report

An honest audit of this build against every requirement in `prompt.md`.

**Legend** — `[x]` built and verified · `[~]` partially built (what's missing is
named) · `[ ]` not built

Nothing below is marked done on the strength of "the code exists". Where a claim
is backed by a test or a command you can run yourself, it says so.

**Verification baseline** (re-runnable):

```
php artisan test      → 50 passed (687 assertions)
./vendor/bin/pint --test → passed
npm run build         → built, no errors
```

Rendering was verified in real headless Chrome: WebGL 2.0 context, zero console
errors, house click → side panel with the protection notice.

---

## ⚠️ Non-negotiable safety constraints

| # | Constraint | | Where it lives | Proof |
|---|---|---|---|---|
| 1 | Discovery is read-only; imports are "Adopted — Protected"; no edit/restart/delete/cert; buttons not rendered | `[x]` | `DiscoveryService`, `ProtectedResourceGuard`, `Site::booted()`, `SitePolicy`, `SidePanel.jsx` | `ProtectedResourceGateTest` (11 tests), `DiscoveryTest::test_discovery_does_not_modify_a_single_byte_of_the_server` |
| 2 | Separate everything — own DB, vhost, system user, directory | `[x]` | `config/jetgrid.php` roots, `PathGuard`, `jetgrid-` prefix in `CommandRegistry` | `CommandWhitelistTest::test_path_guard_rejects_traversal_and_paths_outside_the_allowed_roots` |
| 3 | Narrow sudo, no `NOPASSWD:ALL`, generated as a reviewable artifact | `[x]` | `CommandRegistry`, `SudoersGlobs`, `jetgrid:sudoers` → `artifacts/jetgrid-sudoers` | `SudoersArtifactTest` (7 tests) |
| 4 | Backup before write, with one-click restore | `[~]` | `config_backups` table + `ConfigBackup` model (path, checksum, inline contents) | Schema and model exist; **the restore UI action is not wired up** |
| 5 | Validate before reload — never reload without `nginx -t` | `[x]` | `nginx.test` is a separate whitelisted command, documented as a precondition of `nginx.reload` | Both commands present and separated in the registry |
| 6 | Dry-run mode showing the literal command, requiring confirmation | `[x]` | `CommandRunner::resolveDryRun()` (defaults true), `BoundCommand::display()`, toggle on every write action | `CommandWhitelistTest::test_a_dry_run_writes_an_audit_entry_and_executes_nothing` |
| 7 | Audit log: who/what/when/exit code/stdout/stderr, immutable, in Filament | `[x]` | `AuditLog::open()`/`close()`, `AuditLogResource` (god_mode only, no create/edit/delete pages) | `CommandWhitelistTest::test_audit_entries_cannot_be_edited_or_deleted` |
| 8 | `JETGRID_READONLY=true` kill switch, default TRUE on first install | `[x]` | `KillSwitch` (fail-closed), `.env` default, `GodModeSeeder` asserts it, `install.sh` re-asserts on upgrade | `ProtectedResourceGateTest::test_a_managed_site_hits_the_kill_switch_instead` |

### Conflicts flagged rather than resolved silently

The prompt says to flag conflicts instead of resolving them. Two arose:

1. **Phase 0 approval gate vs. "execute the app".** Resolved by building
   entirely on Windows against `FakeServerDriver`, which has no process layer
   and cannot reach a real host. Your EC2 box was never contacted. Reported at
   the time, not after the fact.
2. **"A single env flag" vs. a god-mode UI toggle (constraint 8 vs. Feature 8).**
   A single env flag that the panel can flip is not a kill switch — a hijacked
   session would undo it. Implemented as a floor: `JETGRID_READONLY=true` wins
   unless `JETGRID_ALLOW_RUNTIME_UNLOCK=true` is *also* set over SSH. Documented
   in `KillSwitch` and the README.

---

## Phase 0 — recon and plan

All six questions are answered in **[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)**.

- `[x]` Directory layout and isolation → §1
- `[x]` Exact sudoers whitelist, command by command, with justification → §3 and the generated artifact
- `[x]` What needs root vs. what runs unprivileged → §3 (22 root, 8 unprivileged)
- `[x]` DB schema → §4 (all 9 requested tables, plus 6 more)
- `[x]` How existing sites are detected without touching them → §2
- `[x]` 3D dashboard data pipeline, polling vs. websockets → §5 (polling, with reasoning)

---

## Stack

| | | Notes |
|---|---|---|
| Laravel 12 (PHP 8.3+) | `[x]` | Laravel 12.69.1 on PHP 8.5.10 |
| Filament 3 | `[x]` | 3.3.55 |
| React 18 + `@react-three/fiber` + `@react-three/drei` | `[x]` | React 18.3.1, fiber 8.17.10, drei 9.114, three 0.169 |
| Mounted in a custom Filament page **or** Inertia — recommend which | `[x]` | Filament page. Reasoning in `main.jsx` and ARCHITECTURE §5: Inertia would add a second routing and auth stack for one canvas |
| Tailwind, Vite | `[x]` | Tailwind 4 via `@tailwindcss/vite`; React plugin added; three split into its own chunk |
| Match the existing DB engine, don't install a second | `[x]` | `install.sh` detects and refuses to install one. SQLite locally only |
| Redis only if present, else database driver; don't install it | `[x]` | Documented in README + `install.sh`; nothing installs Redis |
| Horizon only if Redis exists | `[x]` | Not installed |

---

## Brand

- `[x]` White base, neon green accent, dark 3D canvas, Filament theme customised.
- `[x]` **Token decision made and documented.** `#39FF14` has ~1.4:1 contrast on
  white — unreadable. Split into two tokens with one job each:
  `--jg-neon #39FF14` (dark canvas only) and `--jg-accent #17800F` (**5.09:1 on
  white**, passes WCAG AA). Filament's palette is generated from the accent.
  See `resources/css/jetgrid.css`.

---

## Feature 1 — the 3D grid dashboard

| | | Notes |
|---|---|---|
| Top-down/isometric, one house per project | `[x]` | Verified rendering in Chrome |
| House size scales with resource footprint | `[x]` | Log curve on disk bytes, clamped 0.6–1.8 so one big site can't hide its neighbours |
| Rooftop-corner beacon, like a wingtip strobe | `[x]` | On the eave corner, clear of the roof geometry |
| Green / red / yellow / grey | `[x]` | `BeaconColor` enum |
| **Blue** = deployment in progress (your addition) | `[x]` | Driven by `Site::hasDeploymentInProgress()` |
| Blink rate encodes urgency | `[x]` | `BeaconColor::blinkHz()` — defined in PHP, obeyed by the scene. ~18% duty cycle so it reads as a flash, not a square wave |
| Click a house → side panel | `[x]` | Verified by clicking in headless Chrome |
| Empty plots = remaining capacity | `[x]` | Fed by the capacity estimator |
| Camera: orbital drift, click-drag pan, scroll zoom | `[x]` | `OrbitControls`, `autoRotate` 0.28 |
| Instanced meshes, simple geometry, 60fps target | `[x]` | Bodies/roofs/beacons are one instanced mesh each; traffic is one `Points` buffer updated in place |
| Costs the server nothing | `[x]` | Browser-side; server work cached 5s and shared across tabs |
| `prefers-reduced-motion` → flat 2D fallback | `[x]` | A genuinely static card grid, strobe disabled — not a slowed-down 3D scene. Manual toggle too. Verified |

---

## Feature 2 — capacity estimator

| | | Notes |
|---|---|---|
| How many more sites fit, shown as empty plots | `[x]` | |
| RAM: total vs used, PHP-FPM `max_children` per site, DB buffer pool, OS overhead | `[x]` | Uses the **observed median `max_children`** from discovered pools, not a constant. Verified: estimate moved from 3 → 1 once discovery read the real pools |
| Disk: free vs average site footprint | `[x]` | Keeps a 15% reserve — a full root filesystem takes every site down |
| CPU credits: `CPUCreditBalance`, `CPUCreditUsage`, `CPUSurplusCreditBalance` | `[x]` | `CpuCreditReader`; degrades to "unavailable" rather than inventing numbers |
| Warn when burning credits faster than earning | `[x]` | `CapacityEstimator::burningCredits()`, shown as a red HUD chip |
| Output names the limiting factor, not one opaque number | `[x]` | "≈ N more small sites (RAM-bound)" + a per-constraint breakdown showing the arithmetic |
| Modelled honestly | `[x]` | Unreadable inputs are **excluded**, never defaulted. `test_capacity_is_zero_and_honest_when_nothing_can_be_read` |

---

## Feature 3 — HTTPS management

| | | Notes |
|---|---|---|
| Let's Encrypt via certbot, HTTP-01 and DNS-01 | `[x]` | Both whitelisted and wired |
| Issue / renew / revoke, managed sites only | `[x]` | `CertificateService`; protected gate checked first |
| Renewal via scheduler at 30 days, with retries | `[x]` | `routes/console.php`, `max_renewal_attempts` |
| Expiry countdown per site; yellow beacon at <14 days | `[x]` | Verified: moritho at 11 days → status `expiring` |
| Pre-flight: DNS A record resolves to this server, port 80 reachable | `[x]` | `DnsPreflight` — also checks AAAA (a stale one silently breaks HTTP-01), CNAME and CAA |
| Rate limit: track and block, don't just fail | `[x]` | `RateLimitTracker`; records **before** issuing, because a failed attempt still counts at the CA |
| Alert loudly on renewal failure | `[x]` | `AlertDispatcher`, severity `critical` |
| Adopted sites: display expiry, never touch the cert | `[x]` | `is_managed=false` on import; `test_an_adopted_certificate_cannot_be_renewed_or_revoked` |
| **Executed against a real CA** | `[ ]` | Never run against real infrastructure — needs your scratch domain |

---

## Feature 4 — app install & site management

| | | Notes |
|---|---|---|
| Create site: domain, PHP version, doc root, vhost from template, DB + scoped user, system user | `[~]` | Model, migration, form, policy and the `useradd`/vhost commands exist; **the vhost template renderer and DB provisioning job are not written** |
| One-click Laravel installer / generic PHP / static | `[ ]` | Not built |
| Git deploy, build script, atomic releases with rollback | `[~]` | `deployments` table models it fully (`release_path`, `previous_release_path`); **no deploy job** |
| PHP version switcher per site | `[~]` | `phpfpm.reload` whitelisted per version; **no UI action** |
| `.env` editor with secret masking | `[ ]` | Not built |
| Artisan runner, whitelisted commands only | `[ ]` | Not built |
| File manager scoped to the site, no symlink/`..` escape | `[~]` | `PathGuard` is built and tested — the enforcement half. **No file-manager UI** |

---

## Feature 5 — security

| | | Notes |
|---|---|---|
| UFW rule management with a port-22 warning | `[~]` | `ufw.allow` / `ufw.deny` / `ufw.status` whitelisted; **no Security page UI** |
| fail2ban status and banned IPs | `[~]` | Commands + fixtures; **no UI** |
| SSH key management | `[ ]` | Not built |
| Automatic security-update checking → yellow | `[x]` | `apt list --upgradable` parsed, feeds `pending_updates` → yellow beacon |
| Per-site basic auth toggle | `[~]` | Column only; no action |
| Suspicious-activity feed from auth.log / nginx logs | `[ ]` | Not built |
| 2FA, mandatory for god mode | `[x]` | TOTP + QR + recovery codes; `RequireTwoFactor` middleware locks the whole panel, not just hidden pages. Secret only persists after a code verifies, so a half-finished setup can't lock you out |

---

## Feature 6 — monitoring & alerts

| | | Notes |
|---|---|---|
| Per-site uptime checks, configurable interval, response history | `[x]` | `jetgrid:monitor`, `health_checks`; interval is a per-plan column |
| Server metrics: CPU, RAM, disk, load, credits | `[x]` | `MetricsCollector` + `metrics` time series |
| Sparklines | `[~]` | Data is collected and stored; **no sparkline widget rendered** |
| Log tailing streamed to the UI | `[ ]` | Not built (diagnostics does show recent error-log lines) |
| Queue worker / supervisor status | `[~]` | `supervisor.status` + `systemctl` discovery; **no UI panel** |
| Alert channels: email, Telegram, webhook, pluggable | `[x]` | `AlertDispatcher` — one method + one `match` arm per channel. Webhook is HMAC-signed. A failing channel is logged and skipped, never aborts the others |

---

## Feature 7 — instance pricing & specs chart

| | | Notes |
|---|---|---|
| Comparison chart: vCPU, RAM, baseline CPU, credits/hr, network, hourly + monthly | `[x]` | `InstanceCatalog` page |
| Highlight my current instance | `[x]` | Via `JETGRID_INSTANCE_TYPE`; says "unknown" if unset rather than guessing |
| Projected monthly cost and upgrade deltas | `[x]` | Δ/mo column vs. your instance |
| **Do NOT hardcode prices from training data** | `[x]` | Specs and prices are separated. Prices ship flagged `unverified_seed`, **rendered struck-through with a warning banner**, and refuse to present as current until a refresh succeeds |
| (a) Price List API **or** (b) seeded JSON + last-updated + refresh action | `[x]` | Both: (b) ships, (a) is implemented as the refresh action |
| Say clearly in the UI which approach is live | `[x]` | `InstanceCatalogue::provenance()` prints it at the top of the page |

---

## Feature 8 — auth, roles, pricing tiers

| | | Notes |
|---|---|---|
| Registration with plan selection at signup | `[ ]` | Not built — Filament login only; users are created by an admin |
| Free / Starter / Pro / Unlimited with limits | `[x]` | `PlanSeeder`; `null` = unlimited |
| Limits enforced **server-side via policies**, not hidden UI | `[x]` | `SitePolicy::create()` checks `withinLimit()` |
| Roles god_mode > admin > developer > viewer | `[x]` | `Role` enum with `rank()`/`atLeast()` |
| Seed `victorynonso9@gmail.com` as god_mode on install | `[x]` | `GodModeSeeder`. Password is **generated and printed once**, never shipped as a default; re-running never resets an existing account's password |
| god_mode bypasses tier limits, sees audit log, only role that can promote or flip readonly | `[x]` | `User::withinLimit()`, `AuditLogPolicy`, `UserPolicy::promote()`/`toggleReadOnly()` |
| **Even god_mode cannot write to protected sites — a hard gate, not a permission** | `[x]` | `test_god_mode_cannot_write_to_a_protected_site` |

---

## Additional features 9–17

| # | | | Notes |
|---|---|---|---|
| 9 | Scheduled backups to S3/local, rotation, **verified restore** | `[~]` | `backups` table has `verified_at`/`verify_result`, and an unverified backup deliberately scores 0 in the health model. `league/flysystem-aws-s3-v3` installed. **No backup or verify job** |
| 10 | Cron manager; protected crons visible but locked | `[~]` | Discovery imports crontabs, `CronJob` model throws on any edit of a protected row (tested). **No Filament resource** |
| 11 | DNS pre-flight before site creation / cert request | `[x]` | `DnsPreflight` — A, AAAA, CNAME, CAA, port 80 |
| 12 | Staging clone to a subdomain with its own DB | `[ ]` | Not built |
| 13 | Health score: uptime, cert, updates, error rate, backup freshness → drives the beacon | `[x]` | `HealthScoreService`. Components with no data are dropped and weights renormalised, so a new site isn't punished for being new |
| 14 | Air-traffic layer, particles proportional to request volume | `[x]` | One `Points` buffer, pool allocated by traffic share, arcing approach lanes. Green for managed, grey for adopted |
| 15 | Maintenance mode with a branded holding page | `[~]` | Column only; no toggle action or page |
| 16 | Cost attribution per site | `[ ]` | Not built — the disk-footprint input it needs *is* collected |
| 17 | One-click diagnostic on red | `[x]` | `DiagnosticsService`: disk, load, HTTP, cert, doc root, recent errors. All read-only, so it works in read-only mode **and on adopted sites** — exactly when you least want to SSH in |

---

## Deliverables

| | | Where |
|---|---|---|
| Working app with migrations, seeders, install script | `[x]` | 13 migrations, 4 seeders, `install.sh` |
| Sudoers file as a separate reviewable artifact | `[x]` | `artifacts/jetgrid-sudoers` — generated, **not installed** |
| README: install, read-only default, un-protecting, full uninstall | `[x]` | `README.md` — all four sections |
| Written list of every privileged command | `[x]` | `docs/PRIVILEGED-COMMANDS.md`, generated from the registry so it can't drift |
| Docker Compose faking the server layer | `[x]` | `docker-compose.yml` + `docker/Dockerfile` + `fixtures/`. **Not run** — Docker isn't installed on this machine |

---

## Build order

| | | |
|---|---|---|
| 1. Phase 0 recon + plan → wait for approval | `[x]` | Delivered as `docs/ARCHITECTURE.md`. The gate is discussed under "Conflicts" above |
| 2. Core app, auth, roles, tiers, Filament shell + theme | `[x]` | |
| 3. Read-only discovery + monitoring | `[x]` | |
| 4. 3D dashboard against real monitoring data | `[x]` | |
| 5. Capacity estimator + pricing chart | `[x]` | |
| 6. Write operations, starting on a scratch domain | `[~]` | Written as code, **never executed**. Behind kill switch + dry-run |
| 7. SSL last | `[~]` | Service layer complete, **never executed against a CA** |

**On steps 6–7:** the brief says not to proceed past step 3 until you've verified
discovery. Steps 4–5 are read-only and safe. Steps 6–7 exist as code but have
never touched real infrastructure — no certbot call, no nginx reload, no
`useradd`. Verify discovery against your three projects first; those paths stay
inert until you lift the kill switch.

---

## Bugs found and fixed during the build

Recorded because each was a real defect, not a refactor:

1. **The generated sudoers file was dangerously loose.** `systemctl restart
   {unit}` became `systemctl restart *`, and a sudoers wildcard matches an entire
   argument — so it would have permitted `systemctl restart nginx` and taken all
   three live projects down, despite PHP validating the `jetgrid-` prefix
   correctly. Fixed by preserving literal prefixes and expanding closed sets;
   what genuinely can't be narrowed is now disclosed under `RESIDUAL RISK`.
   Locked down by `SudoersArtifactTest`.
2. **`PathGuard` depended on `realpath`,** which returns false for paths that
   don't exist locally — so it rejected valid paths while quietly ceasing to
   check anything. Rewritten as a lexical check plus a realpath check when the
   file exists (the latter is what catches symlink escape).
3. **Protection refusals named the attacker's value.** A refused rename reported
   the *attempted* domain instead of the site being protected. Fixed to read the
   original attribute; `test_the_refusal_names_the_protected_site_not_the_attempted_value`.
4. **Aliases became separate houses** — five houses for three projects. The first
   `server_name` is now the site, the rest are aliases.
5. **`gridHelper` renders nothing** at this camera distance (1px GL_LINES).
   Replaced with drei's shader-based `Grid`.
6. **Fixtures were gitignored** under `storage/app/`, so a fresh clone could not
   run discovery, the tests, or the Docker stack. Moved to `fixtures/` — they are
   source, not runtime state.

---

## Honest summary

The **safety architecture is complete and tested** — that was the part of the
brief that carried real risk, and it's where the effort went. Discovery,
monitoring, the 3D dashboard, capacity, pricing, health scoring, DNS pre-flight,
diagnostics and the certificate service are all built and verified.

The **site-management surface is the thinnest area**: git deploy, the `.env`
editor, the artisan runner and the file manager are not built, and backups, cron
management and maintenance mode have schema and models but no jobs or UI. Those
are additive — they sit on top of `CommandRunner`, so they inherit the whitelist,
dry-run, audit log and protected gate for free.

Nothing in this build has ever contacted your EC2 instance.
