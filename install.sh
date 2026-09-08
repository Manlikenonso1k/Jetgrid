#!/usr/bin/env bash
#
# JetGrid installer.
#
# What this script does:  sets up JetGrid's OWN application, database and user.
# What it deliberately does NOT do:
#   - install sudo rules (it generates them for you to review and install)
#   - touch any existing site, vhost, unit, cron, database or certificate
#   - enable write mode (JETGRID_READONLY stays true)
#   - install Redis, or a second database engine
#
# Read it before running it. It is short on purpose.

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; BOLD=$'\033[1m'; OFF=$'\033[0m'

say()  { printf '%s\n' "$*"; }
ok()   { printf '%s✓%s %s\n' "$GREEN" "$OFF" "$*"; }
warn() { printf '%s!%s %s\n' "$YELLOW" "$OFF" "$*"; }
die()  { printf '%s✗%s %s\n' "$RED" "$OFF" "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Refuse to run as root.
#
# Everything below belongs to the unprivileged jetgrid user. Running the whole
# installer as root is how an application ends up owning root-owned files it
# then cannot read, and how a "small" install quietly acquires more privilege
# than it needs.
# ---------------------------------------------------------------------------
[ "$(id -u)" -eq 0 ] && die "Do not run this as root. Run it as the user JetGrid will run as."

command -v php      >/dev/null || die "php not found"
command -v composer >/dev/null || die "composer not found"
command -v npm      >/dev/null || die "npm not found"

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "PHP 8.2+ required, found $PHP_VERSION"
ok "PHP $PHP_VERSION"

cd "$(dirname "$0")"

# ---------------------------------------------------------------------------
say ""
say "${BOLD}1/6  Dependencies${OFF}"
composer install --no-interaction --prefer-dist --optimize-autoloader
npm ci --no-audit --no-fund 2>/dev/null || npm install --no-audit --no-fund
npm run build
ok "Dependencies installed and assets built"

# ---------------------------------------------------------------------------
say ""
say "${BOLD}2/6  Environment${OFF}"
if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --ansi
    ok "Created .env"
else
    warn ".env already exists — leaving it alone"
fi

# The kill switch is asserted on every install, including upgrades. An upgrade
# must never be a way to silently end up with writes enabled.
if grep -q '^JETGRID_READONLY=' .env; then
    sed -i 's/^JETGRID_READONLY=.*/JETGRID_READONLY=true/' .env
else
    printf '\nJETGRID_READONLY=true\n' >> .env
fi
ok "JETGRID_READONLY=true (write operations disabled)"

# ---------------------------------------------------------------------------
say ""
say "${BOLD}3/6  Which database engine does this server already run?${OFF}"
say "  JetGrid will NOT install a second engine."
DETECTED="none"
command -v mysql   >/dev/null && DETECTED="mysql"
command -v psql    >/dev/null && DETECTED="pgsql"
say "  Detected: ${BOLD}${DETECTED}${OFF}"
say ""
warn "Create JetGrid's own database and user yourself, then put them in .env:"
say ""
if [ "$DETECTED" = "pgsql" ]; then
    say "    CREATE DATABASE jetgrid;"
    say "    CREATE USER jetgrid WITH PASSWORD '<a strong password>';"
    say "    GRANT ALL PRIVILEGES ON DATABASE jetgrid TO jetgrid;"
else
    say "    CREATE DATABASE jetgrid CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    say "    CREATE USER 'jetgrid'@'localhost' IDENTIFIED BY '<a strong password>';"
    say "    GRANT ALL PRIVILEGES ON jetgrid.* TO 'jetgrid'@'localhost';"
fi
say ""
say "  Note the grant is scoped to jetgrid.* — JetGrid must never hold"
say "  privileges on your projects' databases."
say ""
read -r -p "  Press Enter once .env has the correct DB_* values... " _

php artisan migrate --force || die "Migration failed. Check your DB_* settings in .env."
ok "Schema created"

# ---------------------------------------------------------------------------
say ""
say "${BOLD}4/6  Seed plans and the god_mode account${OFF}"
php artisan db:seed --force
ok "Seeded (the generated password is printed above — save it now)"

# ---------------------------------------------------------------------------
say ""
say "${BOLD}5/6  Generate the sudoers file for review${OFF}"
php artisan jetgrid:sudoers
php artisan jetgrid:commands --markdown
ok "Wrote artifacts/jetgrid-sudoers and docs/PRIVILEGED-COMMANDS.md"
warn "NOT installed. Review it, then install it yourself:"
say "    less artifacts/jetgrid-sudoers"
say "    sudo visudo -cf artifacts/jetgrid-sudoers"
say "    sudo install -m 0440 -o root -g root artifacts/jetgrid-sudoers /etc/sudoers.d/jetgrid"

# ---------------------------------------------------------------------------
say ""
say "${BOLD}6/6  Read-only discovery${OFF}"
say "  This reads the server and modifies nothing."
read -r -p "  Run it now? [Y/n] " RUN_DISCOVERY
if [ "${RUN_DISCOVERY:-Y}" != "n" ] && [ "${RUN_DISCOVERY:-Y}" != "N" ]; then
    if grep -q '^JETGRID_DRIVER=fake' .env; then
        warn "JETGRID_DRIVER=fake — this will read FIXTURES, not this server."
        warn "Set JETGRID_DRIVER=linux in .env to scan the real machine."
    fi
    php artisan jetgrid:discover
fi

# ---------------------------------------------------------------------------
say ""
say "${GREEN}${BOLD}JetGrid is installed and read-only.${OFF}"
say ""
say "Next:"
say "  1. Point a vhost at $(pwd)/public and open /admin"
say "  2. Log in as the god_mode account above; you will be asked to set up 2FA"
say "  3. ${BOLD}Verify the discovered sites against what you know is on this box${OFF}"
say "  4. Only then consider enabling writes — see the README"
say ""
say "Add the scheduler when you are ready for monitoring:"
say "  * * * * * cd $(pwd) && php artisan schedule:run >> /dev/null 2>&1"
say ""
