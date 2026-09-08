# MODULE: Local Project Discovery & Control (cross-platform)

Extends JetGrid with a LOCAL MODE that discovers sibling project folders on my
own machine, works out whether each one is currently running on a local dev
server, renders them as houses on the 3D grid, and lets me start and stop them —
on local only, never on a production server.

Must work identically on Windows, macOS, and Linux. I develop on Windows; assume
the same codebase may later run on macOS or Linux.

---

## L0 — Mode gating (build this first)

Local control is dangerous if it ever activates on production. Gate it with
multiple independent checks, all of which must pass:

1. config('jetgrid.mode') === 'local' (from JETGRID_MODE env, default 'production')
2. app()->environment('local')
3. Absence of a production marker file (e.g. /etc/jetgrid/production.lock)
4. A method PlatformDetector::isLocalWorkstation() that returns false if it
   detects a headless server environment (systemd running as PID 1 with no
   desktop session, cloud-init metadata endpoint reachable, etc.)

Any start/stop/scan-local endpoint must run through a single
EnsureLocalMode middleware. If gating fails, return 403 and log it — do not
silently no-op. Write a test that asserts every local-control route is behind
that middleware.

## L1 — Cross-platform path handling

- Never hardcode / or \. Use DIRECTORY_SEPARATOR, realpath(), and
  Illuminate\Support\Str for path work; use Symfony\Component\Finder for walking.
- Handle Windows drive letters, UNC paths, and spaces in directory names.
- Handle permission-denied and symlink loops without crashing the scan — collect
  errors and surface them in the UI instead of aborting.
- Normalise every discovered path to a canonical form before storing, so the
  same project isn't imported twice.

## L2 — Scan roots

- Default scan root: the PARENT directory of JetGrid's own base_path(). So if
  JetGrid lives at Documents/jetgrid, it scans Documents/.
- Allow additional roots to be added in the UI (multiple, each toggleable).
- Configurable max depth, default 2 levels below each root.
- Always skip: node_modules, vendor, .git, .idea, .vscode, dist, build, storage,
  bootstrap/cache, __pycache__, target, .next, .nuxt, Pods.
- JetGrid must EXCLUDE ITSELF from results — compare canonical base_path().
- Scan must be incremental and cached. Do not re-walk the whole tree on every
  page load. Provide a manual "Rescan" action and an optional filesystem-watch
  based refresh.

## L3 — Project type detection (by filesystem signature)

Detect type by marker files, in this priority order. A folder matching none of
these is not a project — ignore it.

  Laravel        artisan + composer.json containing laravel/framework
  Symfony        bin/console + composer.json containing symfony/framework-bundle
  WordPress      wp-config.php or wp-settings.php
  Generic PHP    composer.json alone, or index.php at root
  Next.js        package.json with "next" dependency
  Nuxt           nuxt.config.{js,ts}
  Vite/React/Vue vite.config.{js,ts} — read package.json deps to name the framework
  Node/Express   package.json with a start or dev script, no framework marker
  Django         manage.py
  Flask/FastAPI  requirements.txt or pyproject.toml containing flask/fastapi
  Rails          Gemfile + config/application.rb
  Go             go.mod
  Rust           Cargo.toml
  Static         index.html with no other marker
  Docker         docker-compose.{yml,yaml} — treat as a modifier, not a type

Record for each: name (folder name, or "name" from package.json / composer.json),
type, canonical path, detected framework version if cheaply readable, last
modified time, and repo info if .git exists (current branch, dirty/clean).

Also detect INSTALL STATE — this drives the yellow beacon:
- PHP project with no vendor/ → dependencies missing
- Node project with package.json but no node_modules/ → dependencies missing
- Laravel with no .env → not configured

## L4 — Port resolution

Work out which port each project would run on, without starting it:
- Laravel: APP_URL in .env, else default 8000
- Node: PORT in .env or .env.local, or "dev" script args in package.json, else
  framework default (Next 3000, Vite 5173, Nuxt 3000, CRA 3000)
- Django: default 8000
- Rails: default 3000
- docker-compose.yml: parse the ports: mappings for host ports
Store the resolved port, and allow me to override it per project in the UI.

## L5 — "Is it running?" detection (cross-platform)

Use a layered approach, cheapest first, degrading gracefully:

LAYER 1 — TCP probe (universal, always available)
  Attempt a short-timeout connection to 127.0.0.1 on the resolved port
  (fsockopen or stream_socket_client, ~200ms timeout). Open port = something is
  listening. This alone is enough for a basic running/not-running status.

LAYER 2 — HTTP probe (confirms it's actually the app)
  If the port is open, issue a GET to http://127.0.0.1:{port}/ with a short
  timeout. Record status code and response time. A 200/302 means healthy; a
  connection reset or 500 means running-but-erroring.

LAYER 3 — Process attribution (confirms WHICH project owns the port)
  Platform-specific, and must fail soft if the command is unavailable:
    Windows  netstat -ano to map port → PID, then tasklist /FI "PID eq {pid}"
             for the image name, and WMIC or PowerShell Get-CimInstance
             Win32_Process to read the command line (which usually contains the
             project path)
    macOS    lsof -nP -iTCP:{port} -sTCP:LISTEN for the PID, then
             lsof -a -p {pid} -d cwd -Fn to read its working directory
    Linux    ss -tlnp (or lsof as fallback) for the PID, then
             readlink /proc/{pid}/cwd for the working directory
  Match that working directory against the project's canonical path to confirm
  ownership. If attribution is unavailable, fall back to Layer 1/2 and mark the
  status as "unattributed" rather than guessing.

LAYER 4 — Docker
  If docker is on PATH, run `docker compose ls --format json` and
  `docker ps --format json`, and match containers to projects by compose project
  name or working_dir label.

Poll on a configurable interval (default 5s) while the dashboard is open, and
stop polling when it isn't. Never poll from the server on a cron — this is a
local UI concern.

## L6 — Start / stop / restart (LOCAL ONLY)

Behind EnsureLocalMode. Per-project commands, overridable in the UI:

  Laravel   php artisan serve --host=127.0.0.1 --port={port}
  Node      npm run dev   (detect yarn.lock / pnpm-lock.yaml and use the right
                           package manager)
  Django    python manage.py runserver 127.0.0.1:{port}
  Rails     bin/rails server -p {port}
  Go        go run .
  Static    php -S 127.0.0.1:{port}
  Docker    docker compose up -d

Implementation:
- Use symfony/process. Spawn detached so the dev server survives the request:
    Windows — start the process in a new console / detached mode
    macOS+Linux — detach from the parent, redirect output to a log file
- Write the PID to storage/app/jetgrid/pids/{project}.pid and store it in the DB.
- Stream each project's stdout/stderr to storage/app/jetgrid/logs/{project}.log
  and expose a live tail in the site detail panel. This is the single most useful
  part of this module — no more hunting for which terminal tab has the error.
- Stop: terminate by PID (taskkill /PID {pid} /T /F on Windows, posix_kill /
  SIGTERM then SIGKILL on Unix), then verify the port has actually closed.
- Restart = stop, wait for the port to free, start.
- Port collision: before starting, check the port. If occupied by a DIFFERENT
  process, refuse to start and tell me what is holding it (from Layer 3), rather
  than failing with an opaque error.
- Auto-port: optional toggle to pick the next free port if the preferred one is
  taken, and remember that choice.

HARD RULES:
- Never run composer install, npm install, migrations, or any build step
  automatically. Offer them as explicit one-click actions with a confirmation
  showing the literal command.
- Never write any file inside a discovered project directory. Discovery is
  read-only. All JetGrid state lives in JetGrid's own storage.
- Never start anything when mode gating fails.

## L7 — Grid rendering

Local projects appear as houses, visually distinct from production sites:

- Split the grid into two districts with a clear visual boundary: a LOCAL
  district (lighter ground plane, or a subtle neon-green grid overlay) and a
  PRODUCTION district. Label them in-scene.
- House styling by project type — vary roof shape or a small type badge so
  Laravel, Node, Python, and static projects are distinguishable at a glance.
- Beacon states for local houses:
    GREEN   running, HTTP probe healthy
    BLUE    starting up (process spawned, port not yet listening)
    GREY    detected, not running — house rendered dimmed/unlit
    RED     start failed, crashed, or port held by another process
    YELLOW  dependencies missing or not configured (no vendor/node_modules/.env)
    PURPLE  running under Docker
- Unlit houses stay on the grid. Seeing which projects exist but are asleep is
  the point.
- Click a house → panel with: path, type, git branch and dirty state, resolved
  port, live log tail, and Start / Stop / Restart / Open in Browser buttons.
- "Open in Browser" launches the local URL. "Open in Editor" launches
  code {path} if the VS Code CLI is on PATH — nice-to-have, fail soft.

## L8 — Verify before wiring up the UI

Ship an artisan command first:

    php artisan jetgrid:scan --dry-run

It prints a table of everything discovered — path, detected type, resolved port,
running/not-running, and which detection layer produced the answer. I want to run
this on Windows and confirm it correctly identifies my projects BEFORE any of it
is connected to the grid or to start/stop controls.

Then:

    php artisan jetgrid:doctor

Reports which platform was detected, which detection tools are available
(netstat, tasklist, lsof, ss, docker, git, code), and what will degrade if any
are missing.

## L9 — Deliverables for this module

- PlatformDetector, ProjectScanner, ProjectTypeDetector, PortResolver,
  RunStateProbe, and ProcessController as separate testable classes — the
  platform-specific shell commands isolated behind an interface with one
  implementation per OS, so adding a platform later doesn't touch the rest.
- Unit tests using fixture directory trees for type detection, so the logic is
  verifiable without spawning real servers.
- Documented list of every shell command this module can execute, per platform.