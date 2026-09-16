<div align="center">

```
     ██╗███████╗████████╗ ██████╗ ██████╗ ██╗██████╗
     ██║██╔════╝╚══██╔══╝██╔════╝ ██╔══██╗██║██╔══██╗
     ██║█████╗     ██║   ██║  ███╗██████╔╝██║██║  ██║
██   ██║██╔══╝     ██║   ██║   ██║██╔══██╗██║██║  ██║
╚█████╔╝███████╗   ██║   ╚██████╔╝██║  ██║██║██████╔╝
 ╚════╝ ╚══════╝   ╚═╝    ╚═════╝ ╚═╝  ╚═╝╚═╝╚═════╝
```

### ` FLY OVER YOUR INFRASTRUCTURE `

**A self-hosted control panel for a single EC2 instance —<br>with a 3D grid you look down on from a jet.**

<br>

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Filament](https://img.shields.io/badge/Filament-3-FDAE4B?style=for-the-badge&logo=laravel&logoColor=white)
![React](https://img.shields.io/badge/React-18-61DAFB?style=for-the-badge&logo=react&logoColor=black)
![three.js](https://img.shields.io/badge/three.js-r169-000000?style=for-the-badge&logo=threedotjs&logoColor=white)

![Tests](https://img.shields.io/badge/tests-50%20passing-39FF14?style=flat-square)
![Assertions](https://img.shields.io/badge/assertions-687-39FF14?style=flat-square)
![Style](https://img.shields.io/badge/pint-passing-39FF14?style=flat-square)
![Default](https://img.shields.io/badge/default%20mode-READ--ONLY-8A8F98?style=flat-square)

</div>

<br>

```
╔══════════════════════════════════════════════════════════════════════════════╗
║                                                                              ║
║   JetGrid is built to be installed on a server that is ALREADY RUNNING       ║
║   things you cannot afford to break.                                         ║
║                                                                              ║
║   Every design decision below follows from that one fact.                    ║
║                                                                              ║
╚══════════════════════════════════════════════════════════════════════════════╝
```

Discover what is already on the box. Monitor it. And — only when you deliberately
allow it — install and manage new sites, certificates and firewall rules.

---

## ◤ THE ONE THING TO UNDERSTAND FIRST

JetGrid sorts everything it finds into two classes of resource, and the boundary
between them is the whole product:

<table>
<tr><th></th><th>🔒 ADOPTED — PROTECTED</th><th>⚡ MANAGED</th></tr>
<tr>
  <td><b>Origin</b></td>
  <td>Discovery found it. JetGrid did not create it.</td>
  <td>JetGrid created it, or you handed it over on purpose.</td>
</tr>
<tr>
  <td><b>JetGrid does</b></td>
  <td>Reads. Monitors. Displays.</td>
  <td>Full lifecycle.</td>
</tr>
<tr>
  <td><b>Edit / restart / delete</b></td>
  <td><b>NEVER.</b> No role can.</td>
  <td>Yes — gated.</td>
</tr>
<tr>
  <td><b>Certificates</b></td>
  <td>Expiry read and shown. Never renewed or revoked.</td>
  <td>Issue, renew, revoke.</td>
</tr>
<tr>
  <td><b>Beacon on the grid</b></td>
  <td>⚪ Grey</td>
  <td>🟢 🔴 🟡 🔵</td>
</tr>
<tr>
  <td><b>Action buttons</b></td>
  <td><b>Not rendered at all</b></td>
  <td>Rendered</td>
</tr>
</table>

Everything discovery imports starts **Adopted — Protected**. That is not a
default you flip in settings. Getting out of it takes a deliberate, audited,
god-mode-only action on one specific site.

### Enforced in four independent places

```
  ┌─ 1 ─ ProtectedResourceGuard ────────────────────────────────────────────┐
  │  Consulted by CommandRunner before any write command is even built.     │
  │  Takes NO user argument — there is nothing you can pass that means      │
  │  "but I am god_mode".                                                   │
  ├─ 2 ─ The model ─────────────────────────────────────────────────────────┤
  │  Site::booted() refuses any update touching a column outside            │
  │  MONITORING_FIELDS, and refuses deletion. Catches stray mass-assignment │
  │  anywhere in the app.                                                   │
  ├─ 3 ─ Policies ──────────────────────────────────────────────────────────┤
  │  SitePolicy::update() and friends return false for protected sites,     │
  │  regardless of role.                                                    │
  ├─ 4 ─ The UI ────────────────────────────────────────────────────────────┤
  │  Buttons are not DISABLED for protected sites — they are ABSENT. A      │
  │  greyed-out button still claims "this is a thing JetGrid does to this   │
  │  site", and it is not.                                                  │
  └─────────────────────────────────────────────────────────────────────────┘
```

> The test suite asserts all of it, including
> `test_god_mode_cannot_write_to_a_protected_site`. **If that test ever fails,
> the promise this app makes has been broken.**

---

## ◤ THE GRID

A top-down 3D scene: you're in a jet, looking down at a settlement. Each house
is one project.

```
  ▸ HOUSE SIZE     scales with the site's disk footprint (log curve, clamped)
  ▸ ROOFTOP STROBE like an aircraft wingtip light — colour is state, RATE is urgency
  ▸ EMPTY PLOTS    remaining capacity, straight from the estimator
  ▸ AIR TRAFFIC    particles flowing in, proportional to live request volume
  ▸ CAMERA         slow orbital drift · drag to pan · scroll to zoom
```

| Beacon | State | Blink |
|:--:|---|--:|
| 🟢 | healthy — HTTP 200, cert valid, services up | `0.4 Hz` |
| 🔴 | down, erroring, or server overloaded | `2.4 Hz` |
| 🟡 | updates pending, or cert expiring soon | `1.0 Hz` |
| 🔵 | deployment in progress | `1.6 Hz` |
| ⚪ | adopted — monitoring only, not managed | `0.2 Hz` |

Blink rates come from `BeaconColor::blinkHz()` in **PHP**, so "faster = more
urgent" is defined once and the scene simply obeys it.

**Performance.** All house bodies are one instanced mesh; roofs another; beacons
a third; the traffic layer is a single `Points` buffer updated in place. The
whole settlement is a handful of draw calls no matter how many sites you have.

**`prefers-reduced-motion`** gets a real static card grid — not a slowed-down 3D
scene — carrying the same information with the strobe off. There's a manual
toggle too.

---

## ◤ INSTALL

### Requirements

```
PHP 8.2+          pdo · mbstring · openssl · curl · zip · intl
Composer 2        Node 18+
Database          whichever engine the server ALREADY runs — JetGrid installs no second one
Redis             OPTIONAL. Present → use it. Absent → database driver, Horizon stays off.
                  JetGrid will not install Redis on a small instance.
```

### On the server

```bash
git clone https://github.com/Manlikenonso1k/Jetgrid.git /opt/jetgrid
cd /opt/jetgrid
./install.sh
```

`install.sh` is interactive, **refuses to run as root**, and touches nothing
outside `/opt/jetgrid` and the database it creates. Read it first — it's short on
purpose.

### Then, separately and by hand — the sudo grant

```bash
php artisan jetgrid:sudoers                    # → artifacts/jetgrid-sudoers
less artifacts/jetgrid-sudoers                 # READ IT. Especially "RESIDUAL RISK".
sudo visudo -cf artifacts/jetgrid-sudoers
sudo install -m 0440 -o root -g root artifacts/jetgrid-sudoers /etc/sudoers.d/jetgrid
```

> **JetGrid does not install its own sudo rules.** A control panel that grants
> itself root is one you have to *trust*. One that hands you a file to read is
> one you can *verify*.

Want monitoring only? **Delete the `=== WRITES ===` section before installing
it.** JetGrid degrades cleanly — the write actions simply fail and everything
else keeps working.

### First run

```bash
php artisan jetgrid:discover    # read-only scan → imports as Adopted — Protected
php artisan jetgrid:monitor     # read-only health checks
```

Open `/admin`, log in as the god_mode account the installer printed, and
**verify the discovered list against what you know is on the box before doing
anything else.** Nothing has been modified at this point, and nothing can be
until you turn writes on.

---

## ◤ THE READ-ONLY DEFAULT

```
╔═══════════════════════════════════════════════════════════════════════╗
║  JETGRID_READONLY=true          ◀── ships this way. every install.    ║
╚═══════════════════════════════════════════════════════════════════════╝
```

While it's on, every whitelisted **write** command is refused at
`CommandRunner`, and the refusal is written to the audit log. Read commands —
all of discovery, all of monitoring — run normally.

Turning it off takes **two** deliberate acts, not one:

```env
# Option A — straight off
JETGRID_READONLY=false

# Option B — leave .env locked, allow the in-app switch
JETGRID_READONLY=true
JETGRID_ALLOW_RUNTIME_UNLOCK=true    # SSH-only opt-in
```

Without `JETGRID_ALLOW_RUNTIME_UNLOCK`, the in-app toggle is **inert**. That
split exists so "read-only", once set over SSH, cannot be undone from a hijacked
browser session. `App\Support\KillSwitch` resolves the two and **fails closed** —
if the settings table is missing, corrupt or unreachable, JetGrid is read-only.

Even with writes enabled, three things stay unconditional:

```
  ▸ DRY RUN IS THE DEFAULT   you see the literal shell command and confirm first
  ▸ nginx -t MUST PASS       before any reload; on failure, roll back and abort
  ▸ CONFIG IS BACKED UP      timestamped, before any file JetGrid writes
```

---

## ◤ EXTERNAL REACHABILITY — "LOCALHOST HEALTH IS NOT HEALTH"

```
╔══════════════════════════════════════════════════════════════════════════════╗
║  A production domain was suspended by its registrar. The nameservers were    ║
║  silently replaced with NS1.VERIFICATION-HOLD.SUSPENDED-DOMAIN.COM.          ║
║                                                                              ║
║  The server stayed healthy and returned HTTP 200 to ITSELF the entire time,  ║
║  so every check passed while the site was unreachable worldwide.             ║
╚══════════════════════════════════════════════════════════════════════════════╝
```

The original HTTP check is still there, but it is now labelled **internal** —
because that is all it ever proved. Five checks run from the outside in:

| Check | Alerts on | Cadence |
|---|---|--:|
| **DNS** | NXDOMAIN · REFUSED · SERVFAIL · empty answer · resolves to a loopback/RFC1918 address · resolves away from this server | `5 min` |
| **Nameservers** | any change from the recorded baseline; **CRITICAL** on registrar-hold hostnames | `5 min` |
| **External reachability** | internal check passing **+** DNS failing = *"reachable locally, unreachable externally"* | `5 min` |
| **TLS** | expiry at 21 / 14 / 7 / 3 days, read from the externally resolved host | `6 h` |
| **Registrar (RDAP)** | `clientHold` · `serverHold` · `pendingDelete` · `redemptionPeriod` · `transferPeriod`; registry expiry at 30 / 14 / 7 / 1 days | `24 h` |

The NS baseline is **recorded from the first successful lookup, never
hardcoded** — "changed from what we saw before" is the only definition that
works across registrars.

**No new privileged commands.** DNS goes over DoH against two independent
resolvers (1.1.1.1 and 8.8.8.8) and registrar status over RDAP — both plain
HTTPS. Nothing was added to the sudoers whitelist for any of this.

**A failed lookup is `UNKNOWN`, never `OK`.** "We could not ask" and "the answer
was fine" are different facts, and conflating them is exactly how a suspended
domain reported green.

### Alert discipline — the part that matters more than the checks

```
  ▸ STATE CHANGES ONLY   healthy→failing and failing→healthy. A check running
                         every 5 min must not produce 288 messages a day.
  ▸ CONFIRM FIRST        N consecutive failures (default 2) before believing one
  ▸ RATE LIMITED         at most one alert per site per check type per hour
  ▸ DIGEST               several sites failing at once collapse into one message
  ▸ RECOVERED            explicit, with how long it was down
  ▸ DAILY SUMMARY        sent even when everything is healthy — silent monitoring
                         is indistinguishable from broken monitoring
```

Every message names the site, the check, the **observed** value, the **expected**
value, and how long it has been failing.

```env
TELEGRAM_ALERTS_ENABLED=true
TELEGRAM_BOT_TOKEN=...
TELEGRAM_CHAT_ID=...
```

Per-site overrides live in `sites.meta.telegram_chat_id`, so different clients'
alerts can go to different groups from one bot.

> **Adopted — Protected sites are monitored exactly like managed ones.** Every
> result is written to JetGrid's own `domain_check_*` tables keyed by `site_id`,
> never to the site row — asserted by
> `test_monitoring_an_adopted_protected_site_writes_nothing_to_that_site`.

### Watching your Windows machine too

[`tools/local-monitor/`](tools/local-monitor/) is a standalone single-file
checker — no Composer, no framework, no database — that watches local dev
services and posts to the same bot, prefixed `[LOCAL]`. It is deliberately
independent: if the AWS box is down local alerting still works, and vice versa.

```powershell
cd tools\local-monitor
copy config.example.json config.json   # paste token + chat id, edit targets
php check.php --test
schtasks /Create /TN "JetGrid Local Monitor" /SC MINUTE /MO 5 /F ^
  /TR "\"C:\php\php.exe\" \"%CD%\check.php\""
```

---

## ◤ UN-PROTECTING A SITE, DELIBERATELY

The only route out of protection. Intentionally awkward.

**In the UI:** `Sites → the site → Adopt into JetGrid`. Visible to god_mode
only, requires typing the exact domain to confirm, recorded in the audit log.

| | |
|---|---|
| **What changes** | `is_protected → false`, `management_mode → managed`. Policies and the guard now allow writes to **that one site**. |
| **What doesn't** | Kill switch still applies. Dry-run still default. Every command still whitelisted and audited. |
| **Re-running discovery** | Will **not** undo it. `adoptSite()` only ever *inserts* sites it hasn't seen; existing records get monitoring columns refreshed and nothing else. Covered by `test_rediscovery_does_not_re_protect_a_site_you_deliberately_adopted`. |

To put a site back under protection, set `is_protected = true` and
`management_mode = 'adopted_protected'` on that row. There is no UI for it,
because re-protecting is safe and reversible while un-protecting is not.

---

## ◤ UNINSTALLING COMPLETELY, WITHOUT RESIDUE

JetGrid keeps everything it owns in four places, so removal is four steps.

```bash
# ── 1 ── stop the scheduler and any workers
sudo systemctl disable --now jetgrid-scheduler.timer 2>/dev/null || true
sudo systemctl disable --now 'jetgrid-*.service'     2>/dev/null || true
sudo crontab -l -u jetgrid 2>/dev/null    # check it's empty, then clear it

# ── 2 ── remove the sudo grant FIRST, before anything else can use it
sudo rm -f /etc/sudoers.d/jetgrid
sudo visudo -c                            # confirm sudoers is still valid

# ── 3 ── drop JetGrid's OWN database and user (never your projects')
mysql -e "DROP DATABASE IF EXISTS jetgrid; DROP USER IF EXISTS 'jetgrid'@'localhost';"

# ── 4 ── remove JetGrid's own files, vhost and system user
sudo rm -f /etc/nginx/sites-enabled/jetgrid /etc/nginx/sites-available/jetgrid
sudo nginx -t && sudo systemctl reload nginx
sudo rm -rf /opt/jetgrid /var/backups/jetgrid
sudo userdel jetgrid
```

**What this leaves behind, and why that's correct:** any site JetGrid *created*
(under `/srv/jetgrid/sites`, with a `jetgrid-` vhost and its own database) is a
real website still serving traffic. Uninstalling the panel does not delete your
websites — remove those individually first if you want them gone.

**What it never touches:** your existing projects. JetGrid never wrote to their
directories, configs, databases, units or certificates, so there is nothing of
JetGrid's to remove from them. That is the entire point.

Confirm nothing was left behind:

```bash
sudo grep -ri jetgrid /etc/nginx /etc/systemd/system /etc/supervisor /etc/sudoers.d 2>/dev/null
```

---

## ◤ LOCAL DEVELOPMENT — NO SERVER REQUIRED

You do not need a real box, or even a Linux machine, to work on this.

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan jetgrid:discover     # reads FIXTURES, not a real server
npm run dev
php artisan serve
```

```
┌──────────────────────────────────────────────────────────────────────────┐
│  JETGRID_DRIVER=fake   ◀── the default                                   │
│                                                                          │
│  FakeServerDriver reads a simulated machine from fixtures/ — three live   │
│  projects with nginx vhosts, systemd units, a supervisor program,        │
│  PHP-FPM pools and crontabs — and CANNOT EXECUTE ANYTHING.               │
│                                                                          │
│  run() returns canned fixture output. There is no process layer behind   │
│  it at all. Pointing dev at the fake driver makes an accident            │
│  PHYSICALLY IMPOSSIBLE rather than merely unlikely.                      │
└──────────────────────────────────────────────────────────────────────────┘
```

Docker Compose is provided too, for a Linux-shaped environment with MySQL and
Redis:

```bash
docker compose up -d
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan jetgrid:discover
# → http://localhost:8080/admin
```

### Tests

```bash
php artisan test          # 50 passed (687 assertions)
```

The suite is mostly about safety properties, not features:

| Test | Asserts |
|---|---|
| `DiscoveryTest::test_discovery_does_not_modify_a_single_byte_of_the_server` | Hashes **every** fixture file before and after a scan |
| `SudoersArtifactTest` | No shell, no blanket root, no unqualified `systemctl restart`, no bare `certbot renew` |
| `CommandWhitelistTest` | A table of injection attempts; every placeholder has a validating pattern |
| `ProtectedResourceGateTest` | Including god_mode hitting the same wall as everyone else |

---

## ◤ WHAT JETGRID CAN RUN

Generated from the code, never maintained by hand:

```bash
php artisan jetgrid:commands              # table
php artisan jetgrid:commands --markdown   # → docs/PRIVILEGED-COMMANDS.md
```

```
  30 whitelisted commands  ·  16 read-only  ·  14 writes  ·  8 need no privilege
```

There is **no path to an arbitrary shell command**. `CommandRunner` refuses any
key not in `CommandRegistry`, every `{placeholder}` is validated against an exact
pattern, and arguments reach the process as an **array** — never through a shell —
so no argument value can introduce a second command.

### The order of checks, and why it's that order

```
  1  whitelist       an unknown key never becomes a process
  2  bind + validate every argument must match its exact pattern
  3  PROTECTED GATE  refuse adopted targets — before any role check
  4  kill switch     refuse all writes while JETGRID_READONLY
  5  dry run         render + audit, execute nothing
  6  audit OPEN      the record exists BEFORE the process starts
  7  execute         argv array, never a shell string
  8  audit CLOSE     exit code, stdout, stderr, duration
```

Step 3 before step 4, so the message names the more fundamental reason.
Step 6 before step 7, so a command that hangs or crashes the process still
leaves a trace of having been attempted.

---

## ◤ COLOUR, AND ONE HONEST NOTE ABOUT NEON

`#39FF14` on white has a contrast ratio of roughly **1.4:1**. It is unreadable
as text or a border. So the brand is two tokens with one job each:

| Token | Value | Job |
|---|---|---|
| `--jg-neon` | `#39FF14` | The real neon. **Dark 3D canvas only** — beacons, grid lines, traffic. |
| `--jg-accent` | `#17800F` | The desaturated variant for the light UI. **5.09:1 on white — passes WCAG AA.** |

The Filament palette is generated from the accent. Nothing in the panel puts raw
neon on white.

---

## ◤ FURTHER READING

| | |
|---|---|
| [`REPORT.md`](REPORT.md) | Every requirement from the brief, with an honest built / partial / not-built status |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Directory layout, isolation, schema, root vs. unprivileged, the dashboard data pipeline |
| [`docs/PRIVILEGED-COMMANDS.md`](docs/PRIVILEGED-COMMANDS.md) | Generated — every command JetGrid can execute |
| [`artifacts/jetgrid-sudoers`](artifacts/jetgrid-sudoers) | Generated, for you to review before installing |

<div align="center">
<br>

```
     ╭──────────────────────────────────────────────────────╮
     │   read-only by default  ·  dry-run by default        │
     │   whitelisted  ·  validated  ·  audited  ·  reversible│
     ╰──────────────────────────────────────────────────────╯
```

</div>
