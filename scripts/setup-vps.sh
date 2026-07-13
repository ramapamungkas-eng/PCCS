#!/usr/bin/env bash
#
# VPS Setup for PDF generation (Puppeteer / Browsershot)
# Installs Chromium system libraries, Puppeteer browsers, and verifies the setup.
#
# Usage:
#   sudo bash scripts/setup-vps.sh
#
# Environment variables (optional):
#   APP_DIR       - application root (default: /var/www/pccs)
#   APP_USER      - the user that runs PHP/queue worker (auto-detected)

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/pccs}"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info()  { echo -e "${GREEN}[INFO]${NC}  $*"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC}  $*"; }
log_error() { echo -e "${RED}[ERROR]${NC} $*"; }

die() {
    log_error "$*"
    exit 1
}

# ── 0. Pre-flight ──────────────────────────

[ "$(id -u)" -eq 0 ] || die "This script must be run as root (use sudo)."
[ -d "$APP_DIR" ] || die "App directory '$APP_DIR' not found. Deploy the code first."
command -v node &>/dev/null || die "Node.js is not installed. Install Node.js 18+ first."
command -v npm  &>/dev/null || die "npm is not installed."
command -v php  &>/dev/null || log_warn "PHP not found in PATH; skipping PHP extension checks."

# Auto-detect the PHP-FPM pool user
if [ -z "${APP_USER:-}" ]; then
    for conf in /etc/php/*/fpm/pool.d/www.conf /etc/php/*/fpm/pool.d/*.conf; do
        [ -f "$conf" ] || continue
        detected=$(grep -Po '^user\s*=\s*\K\S+' "$conf" 2>/dev/null | head -1)
        if [ -n "$detected" ] && id "$detected" &>/dev/null; then
            APP_USER="$detected"
            break
        fi
    done
fi

if [ -z "${APP_USER:-}" ]; then
    detected=$(ps aux 2>/dev/null | grep -E 'php-fpm:\s+pool' | grep -v grep | awk '{print $1}' | head -1)
    [ -n "${detected:-}" ] && id "$detected" &>/dev/null && APP_USER="$detected"
fi

if [ -z "${APP_USER:-}" ]; then
    for u in www-data www nobody; do
        if id "$u" &>/dev/null; then
            APP_USER="$u"
            break
        fi
    done
fi

[ -n "${APP_USER:-}" ] || die "Could not auto-detect PHP-FPM user. Set APP_USER env var."
id "$APP_USER" &>/dev/null || die "App user '$APP_USER' does not exist on this system."

echo "========================================"
echo "  PCCS VPS PDF Setup (Puppeteer)"
echo "========================================"
echo "  App dir  : $APP_DIR"
echo "  App user : $APP_USER"
echo ""

log_info "Node.js $(node -v) | npm $(npm -v)"

if command -v php &>/dev/null; then
    log_info "PHP $(php -r 'echo PHP_VERSION;')"
fi

# ── 1. Install npm dependencies ────────────

echo ""
log_info "[1/6] Installing npm dependencies..."
cd "$APP_DIR"

# Fix permissions — node_modules and npm cache may be root-owned from prior runs
[ -d node_modules ] && chown -R "$APP_USER":"$APP_USER" node_modules 2>/dev/null || true

app_home=$(sudo -u "$APP_USER" bash -c 'echo "$HOME"' 2>/dev/null || echo "/var/www")
for cache_dir in "$app_home/.npm" /var/www/.npm; do
    [ -d "$cache_dir" ] && chown -R "$APP_USER":"$APP_USER" "$cache_dir" 2>/dev/null || true
done

if [ ! -d "node_modules" ]; then
    log_info "  node_modules missing, running npm install..."
    sudo -u "$APP_USER" npm install --omit=dev
else
    if sudo -u "$APP_USER" node -e "require('puppeteer')" 2>/dev/null; then
        log_info "  puppeteer: OK"
    else
        log_warn "  puppeteer: MISSING — reinstalling..."
        sudo -u "$APP_USER" rm -rf node_modules
        sudo -u "$APP_USER" npm install --omit=dev
    fi
fi

# ── 2. System deps for Chromium ────────────

echo ""
log_info "[2/6] Installing Chromium system dependencies..."

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
    libnss3 libnspr4 libatk-bridge2.0-0 libatk1.0-0 \
    libcups2 libdrm2 libdbus-1-3 libxkbcommon0 \
    libxcomposite1 libxdamage1 libxfixes3 libxrandr2 \
    libgbm1 libpango-1.0-0 libcairo2 libasound2t64 \
    libatspi2.0-0 libx11-6 libx11-xcb1 libxcb1 \
    libxext6 libxrender1 libxi6 libglib2.0-0t64 \
    libgtk-3-0t64 libgdk-pixbuf2.0-0 libpangocairo-1.0-0 \
    libcairo-gobject2 libgstreamer1.0-0 libgstreamer-plugins-base1.0-0 \
    libopus0 libharfbuzz-icu0 libenchant-2-2 libsecret-1-0 \
    libhyphen0 libmanette-0.2-0 libgles2 \
    fonts-liberation fonts-noto-color-emoji 2>&1 || true

# ── 3. PHP extension checks ────────────────

echo ""
log_info "[3/6] Checking PHP extensions..."
if command -v php &>/dev/null; then
    for ext in mbstring gd fileinfo pdo pdo_mysql; do
        if php -m 2>/dev/null | grep -qi "^$ext$"; then
            log_info "  $ext: OK"
        else
            log_warn "  $ext: MISSING — may be needed by the application"
        fi
    done
fi

# ── 4. Install Puppeteer Chrome ────────────

echo ""
log_info "[4/6] Installing Chromium via Puppeteer (as $APP_USER)..."
sudo -u "$APP_USER" npx puppeteer browsers install chrome 2>&1

# ── 5. AppArmor check ──────────────────────

echo ""
log_info "[5/6] Checking AppArmor profiles for Chromium..."

if command -v aa-status &>/dev/null; then
    if aa-status 2>/dev/null | grep -qiE 'chrome|chromium'; then
        log_warn "AppArmor has Chrome/Chromium profiles that may block Puppeteer!"

        if command -v aa-disable &>/dev/null; then
            for profile in $(aa-status 2>/dev/null | grep -iE '^\s+(chrome|chromium|snap\.chromium)' | tr -d ' '); do
                log_warn "Disabling AppArmor profile: $profile"
                aa-disable "$profile" 2>&1 || aa-complain "$profile" 2>&1 || true
            done
        else
            log_warn "apparmor-utils not installed. Install it to auto-fix:"
            log_warn "  sudo apt install -y apparmor-utils"
            log_warn "  sudo aa-disable chrome    # (or whichever profile shows up)"
        fi
    else
        log_info "No AppArmor Chrome profiles detected."
    fi
else
    log_info "AppArmor not found on this system."
fi

# ── 6. Locate & verify Chromium ────────────

echo ""
log_info "[6/6] Locating and verifying Chromium binary..."

CHROMIUM_PATH=""

# Puppeteer cache path: ~/.cache/puppeteer/chrome/linux-XXXX/chrome-linux64/chrome
cache_dirs=()

app_home=$(sudo -u "$APP_USER" bash -c 'echo "$HOME"' 2>/dev/null || echo "")
[ -n "$app_home" ] && cache_dirs+=("$app_home/.cache/puppeteer")

cache_dirs+=(
    "/var/www/.cache/puppeteer"
    "/home/$APP_USER/.cache/puppeteer"
    "/root/.cache/puppeteer"
)

# Also search the old Playwright cache (might still exist)
cache_dirs+=(
    "/var/www/.cache/ms-playwright"
    "/root/.cache/ms-playwright"
)

for homedir in /home/* /var/www /root; do
    [ -d "$homedir/.cache/puppeteer" ] && cache_dirs+=("$homedir/.cache/puppeteer")
    [ -d "$homedir/.cache/ms-playwright" ] && cache_dirs+=("$homedir/.cache/ms-playwright")
done

declare -A seen
unique_dirs=()
for d in "${cache_dirs[@]}"; do
    [ -d "$d" ] || continue
    [ -n "${seen[$d]:-}" ] && continue
    seen[$d]=1
    unique_dirs+=("$d")
done

for cache_dir in "${unique_dirs[@]}"; do
    log_info "  Searching: $cache_dir"
    found=$(find "$cache_dir" -maxdepth 5 -name chrome -type f -executable 2>/dev/null | sort -r | head -1)
    if [ -n "$found" ]; then
        CHROMIUM_PATH="$found"
        break
    fi
done

if [ -z "$CHROMIUM_PATH" ] || [ ! -f "$CHROMIUM_PATH" ]; then
    log_error "Could not find Chromium binary after installation."
    log_error "Searched these directories:"
    for d in "${unique_dirs[@]}"; do
        log_error "  $d"
    done
    log_error ""
    log_error "Try manually:"
    log_error "  sudo -u $APP_USER npx puppeteer browsers install chrome"
    log_error "  find / -name chrome -type f -executable 2>/dev/null"
    die "Chromium binary not found."
fi

log_info "  Binary : $CHROMIUM_PATH"

VERSION=$(sudo -u "$APP_USER" "$CHROMIUM_PATH" --version 2>&1) || {
    log_error "Chromium version check failed."
    log_error "Missing system libraries? Run: ldd '$CHROMIUM_PATH' | grep 'not found'"
    die "Chromium cannot be launched."
}

log_info "  Version: $VERSION"

# ── Persist the path in .env ───────────────

ENV_FILE="$APP_DIR/.env"
if [ -f "$ENV_FILE" ]; then
    if grep -q "^LARAVEL_PDF_CHROME_PATH=" "$ENV_FILE"; then
        sed -i "s|^LARAVEL_PDF_CHROME_PATH=.*|LARAVEL_PDF_CHROME_PATH=$CHROMIUM_PATH|" "$ENV_FILE"
    else
        echo "" >> "$ENV_FILE"
        echo "LARAVEL_PDF_CHROME_PATH=$CHROMIUM_PATH" >> "$ENV_FILE"
    fi
    log_info "  .env updated with LARAVEL_PDF_CHROME_PATH=$CHROMIUM_PATH"
fi

# ── Done ───────────────────────────────────

echo ""
echo "========================================"
echo "  Setup complete"
echo "========================================"
echo ""
echo "Next steps on the VPS:"
echo "  1. Restart queue worker:"
echo "     php $APP_DIR/artisan queue:restart"
echo "     sudo supervisorctl restart pccs-worker   # if using supervisor"
echo ""
echo "  2. Test a print from the UI."
