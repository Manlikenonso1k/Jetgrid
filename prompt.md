# PROJECT: JetGrid — self-hosted server control panel

Build a Laravel + React application called JetGrid that turns a single AWS EC2
instance into a Hostinger-style control panel: install and manage apps, manage
security, and handle HTTPS issuance + auto-renewal — with a Filament admin and a
3D "flying over a grid of houses" dashboard.

## ⚠️ NON-NEGOTIABLE SAFETY CONSTRAINT — read before anything else

The target server already runs THREE live production projects. JetGrid must never
modify, restart, reconfigure, or take ownership of them. Treat them as read-only.

Enforce this architecturally, not by convention:

1. DISCOVERY IS READ-ONLY. JetGrid scans nginx/apache vhosts, systemd units,
   supervisor programs, cron entries, PHP-FPM pools, and /var/www (or wherever
   sites live) and IMPORTS them as "Adopted — Protected" records. Protected
   records expose monitoring only. No edit, no restart, no delete, no cert
   action. The UI must not even render those buttons for them.
2. SEPARATE EVERYTHING. JetGrid gets its own database, its own nginx vhost, its
   own system user, its own directory. It never writes into an existing project's
   directory, config file, or database.
3. NARROW SUDO. Do NOT grant blanket NOPASSWD:ALL. Create a dedicated system user
   with a sudoers file whitelisting only the exact commands needed (nginx -t,
   systemctl reload nginx, certbot with fixed arg patterns, etc.). Generate this
   sudoers file as a reviewable artifact I approve manually before installing it.
4. BACKUP BEFORE WRITE. Any config file JetGrid writes gets a timestamped copy
   first, with a one-click restore.
5. VALIDATE BEFORE RELOAD. Never reload nginx without `nginx -t` passing. If the
   test fails, roll back the config and abort.
6. DRY-RUN MODE. Every privileged operation must be previewable — show me the
   literal shell command that would execute, and require confirmation.
7. AUDIT LOG. Every privileged action: who, what command, when, exit code,
   stdout/stderr. Immutable, visible in Filament.
8. KILL SWITCH. A single env flag (JETGRID_READONLY=true) that disables all write
   operations globally. Default it to TRUE on first install.

If any instruction elsewhere in this prompt conflicts with the above, the safety
constraint wins. Flag the conflict instead of resolving it yourself.

---

## PHASE 0 — Recon and plan (do this first, then STOP and report)

Before writing code, tell me:
- Proposed directory layout and how JetGrid isolates itself from existing sites
- The exact sudoers whitelist you'll need, command by command, with justification
- Which parts genuinely need root vs. which can run unprivileged
- The DB schema (sites, certificates, deployments, backups, metrics, users,
  plans, audit_log, protected_resources)
- How you'll detect existing sites without touching them
- Your plan for the 3D dashboard's data pipeline (polling vs. websockets)

Wait for my approval before Phase 1.

---

## STACK

- Laravel 12 (PHP 8.3+), Filament 3 for admin
- React 18 + @react-three/fiber + @react-three/drei for the 3D scene, mounted
  inside a custom Filament page (or Inertia — recommend which and why)
- Tailwind, Vite
- MySQL/MariaDB or PostgreSQL — match whatever the server already runs, do not
  install a second engine
- Redis for metrics cache + queues if already present; fall back to database
  driver if not (do NOT install Redis on a small instance without asking)
- Laravel Horizon only if Redis exists

## BRAND

White base, neon green accent (#39FF14 or a slightly desaturated variant for
readability — pick one and define it as a token). Dark canvas for the 3D scene
so the neon reads properly. Filament theme customized to match.

---

## FEATURE 1 — The 3D Grid Dashboard (the centerpiece)

A top-down/isometric 3D scene: you're in a jet looking down at a grid of houses.
Each house = one project/site.

- House size scales with the site's resource footprint (RAM/disk)
- Each house has a small beacon on a rooftop corner — like an aircraft wingtip
  strobe — that BLINKS:
    · GREEN   = healthy (HTTP 200, cert valid, services up)
    · RED     = down, erroring, or server overloaded
    · YELLOW  = updates pending (composer/npm/OS packages) or cert expiring soon
    · BLUE (add this) = deployment/build currently in progress
    · GREY    = protected/adopted site, monitoring only, not managed by JetGrid
- Blink rate encodes urgency: faster = more critical
- Click a house → side panel with that site's detail
- Empty plots on the grid represent REMAINING CAPACITY (see Feature 2)
- Camera: slow orbital drift by default, click-drag to pan, scroll to zoom
- Performance: this runs in the browser, so it costs the server nothing — but
  keep it to instanced meshes and simple geometry; target 60fps on a mid laptop
- Respect prefers-reduced-motion: provide a flat 2D grid fallback

## FEATURE 2 — Capacity estimator

Calculate how many more sites fit on the current instance. Show as empty plots.

Model it honestly using real numbers read from the server:
- Total vs. used RAM, accounting for PHP-FPM pool max_children per site,
  the DB engine's buffer pool, and OS overhead
- Disk free vs. average site footprint
- CPU: for t-family instances this is the important one — track the CPUCredit
  balance (CloudWatch: CPUCreditBalance, CPUCreditUsage, CPUSurplusCreditBalance)
  and warn when the instance is burning credits faster than it earns them
- Output: "≈ N more small sites" with the limiting factor named explicitly
  ("RAM-bound", "CPU-credit-bound", "disk-bound"), not a single opaque number

## FEATURE 3 — HTTPS management (the one I care most about)

- Let's Encrypt via certbot or Lego, HTTP-01 and DNS-01 challenges
- Issue, renew, revoke — for JetGrid-managed sites only
- Renewal via Laravel scheduler, attempting at 30 days before expiry with retries
- Cert expiry countdown per site, surfaced as the yellow beacon at <14 days
- Pre-flight checks before issuance: DNS A record resolves to this server's public
  IP, port 80 reachable, no rate-limit breach (Let's Encrypt allows 5 duplicate
  certs per week — track and block, don't just fail)
- Alert me on renewal failure, loudly
- For ADOPTED sites: read and display cert expiry, but never touch the cert

## FEATURE 4 — App install & site management

- Create site: domain, PHP version, document root, generate nginx vhost from a
  template, create DB + scoped DB user, create system user
- One-click Laravel installer (composer create-project), plus generic PHP and
  static-site options
- Git deploy: connect a repo, deploy from a branch, run a configurable build
  script, atomic release-directory switching with rollback (Envoyer-style)
- PHP version switcher per site (only if multiple PHP-FPM versions are installed)
- .env editor with secret masking
- Artisan command runner (whitelisted commands only — no arbitrary shell)
- File manager (scoped to that site's directory, cannot escape via symlink/..)

## FEATURE 5 — Security

- UFW/firewall rule management with a warning before any rule touching port 22
- fail2ban status and banned-IP list
- SSH key management
- Automatic security-update checking (apt list --upgradable), surfaced as yellow
- Per-site basic auth toggle
- Suspicious-activity feed from auth.log and nginx access logs
- 2FA on JetGrid accounts themselves — mandatory for god mode

## FEATURE 6 — Monitoring & alerts

- Per-site uptime checks (interval configurable), response time history
- Server metrics: CPU, RAM, disk, load average, CPU credits — sparklines
- Log tailing (nginx error/access, Laravel log) streamed to the UI
- Queue worker / supervisor status
- Alert channels: email, Telegram, and a webhook. Make the channel pluggable.

## FEATURE 7 — Instance pricing & specs chart (learning tool)

A Filament page with a comparison chart of EC2 instance types — vCPU, RAM,
baseline CPU %, credits/hour, network, on-demand hourly and monthly price for my
region. Highlight my current instance. Show a projected monthly cost line and
"what upgrading to X would give you" deltas.

IMPORTANT: AWS pricing changes. Do NOT hardcode prices from your training data.
Either (a) pull from the AWS Price List API and cache, or (b) ship a seeded
JSON file with a visible "last updated" date and an admin refresh action. Say
clearly in the UI which approach is live.

## FEATURE 8 — Auth, roles, pricing tiers

- Registration with plan selection at signup
- Tiers: define Free / Starter / Pro / Unlimited with limits on sites, DBs,
  storage, backups, and monitoring frequency. Enforce limits server-side via
  Laravel policies — not just hidden UI.
- Roles: god_mode > admin > developer > viewer
- Seed victorynonso9@gmail.com as god_mode on install
- god_mode bypasses tier limits, sees the audit log, and is the ONLY role that
  can promote another user or flip JETGRID_READONLY
- Even god_mode cannot write to protected/adopted sites — that's a hard gate,
  not a permission

---

## ADDITIONAL FEATURES I'M ADDING — build these too

9.  BACKUPS. Scheduled DB dumps + file archives to S3 (or local with retention
    rotation). Restore must be tested — include a "restore to a scratch
    directory" verify action so backups aren't theoretically valid only.
10. CRON MANAGER. View and manage scheduled tasks per site. Protected sites'
    crons are visible but locked.
11. DNS PRE-FLIGHT. Before any site creation or cert request, check the domain's
    A/AAAA/CNAME records and show me what's wrong before certbot fails.
12. STAGING CLONE. Clone a managed site to a subdomain with its own DB. This is
    the single most useful safety feature for someone with live projects.
13. HEALTH SCORE per site: composite of uptime, cert validity, pending updates,
    error rate, backup freshness. Drives the beacon color.
14. AIR-TRAFFIC LAYER on the 3D map: animated particles flowing toward each house
    proportional to that site's current request volume. Cheap to render, and it
    makes "which project is actually getting traffic" instantly readable.
15. MAINTENANCE MODE toggle per managed site with a branded holding page.
16. COST ATTRIBUTION: rough per-site share of the instance bill based on resource
    usage — useful when deciding what to migrate off.
17. ONE-CLICK DIAGNOSTIC: when a house goes red, a button that runs a scripted
    triage (service status, disk full?, DB reachable?, cert expired?, recent
    error log lines) and shows a summary instead of making me SSH in.

---

## DELIVERABLES

- Working app with migrations, seeders, and an install script
- The sudoers file as a separate reviewable artifact
- README covering: install, the read-only default, how to un-protect a site
  deliberately, and how to fully uninstall JetGrid without residue
- A written list of every privileged command JetGrid can execute
- Docker Compose setup for local development that fakes the server layer, so I
  can build the UI without a real box

## BUILD ORDER

1. Phase 0 recon + plan → wait for approval
2. Core Laravel app, auth, roles, tiers, Filament shell with the theme
3. Read-only discovery + monitoring (safe — no writes at all)
4. 3D dashboard against real monitoring data
5. Capacity estimator + pricing chart
6. Only then: write operations, starting with site creation on a scratch domain
7. SSL last, since it's the highest-consequence subsystem

Do not proceed past step 3 until I've verified the discovery output against my
three live projects and confirmed nothing was touched.