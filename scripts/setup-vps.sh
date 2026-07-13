#!/usr/bin/env bash
#
# VPS Setup for Playwright PDF generation
# Installs Chromium system libraries, Playwright browser, and verifies the setup.
#
# Usage:
#   sudo bash scripts/setup-vps.sh
#
# Environment variables (optional):
#   APP_DIR       - application root (default: /var/www/pccs)
#   APP_USER      - the user that runs PHP/queue worker (default: www)

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/pccs}"
APP_USER="${APP_USER:-www}"

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

echo "========================================"
echo "  PCCS VPS Playwright Setup"
echo "========================================"
echo "  App dir  : $APP_DIR"
echo "  App user : $APP_USER"
echo ""

# ── 0. Pre-flight ──────────────────────────

[ "$(id -u)" -eq 0 ] || die "This script must be run as root (use sudo)."

id "$APP_USER" &>/dev/null || die "App user '$APP_USER' does not exist on this system."

[ -d "$APP_DIR" ] || die "App directory '$APP_DIR' not found. Deploy the code first."

command -v node &>/dev/null || die "Node.js is not installed. Install Node.js 18+ first."
command -v npm  &>/dev/null || die "npm is not installed."
command -v php  &>/dev/null || log_warn "PHP not found in PATH; skipping PHP extension checks."

log_info "Node.js $(node -v) | npm $(npm -v)"

if command -v php &>/dev/null; then
    log_info "PHP $(php -r 'echo PHP_VERSION;')"
fi

# ── 1. Install npm dependencies ────────────

echo ""
log_info "[1/5] Installing npm dependencies..."
cd "$APP_DIR"

if [ ! -d "node_modules" ]; then
    log_info "node_modules missing, running npm ci..."
    sudo -u "$APP_USER" npm ci --omit=dev
else
    log_info "node_modules exists, verifying playwright is present..."
    if ! sudo -u "$APP_USER" node -e "require('playwright')" 2>/dev/null; then
        log_warn "playwright package missing, reinstalling..."
        sudo -u "$APP_USER" npm ci --omit=dev
    fi
fi

# ── 2. System deps for Chromium ────────────

echo ""
log_info "[2/5] Installing Chromium system dependencies..."

npx playwright install-deps chromium 2>&1 || {
    log_warn "npx install-deps failed. Falling back to manual apt..."
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
}

# ── 3. PHP extension checks ────────────────

echo ""
log_info "[3/5] Checking PHP extensions..."
if command -v php &>/dev/null; then
    for ext in mbstring gd fileinfo pdo pdo_mysql; do
        if php -m 2>/dev/null | grep -qi "^$ext$"; then
            log_info "  $ext: OK"
        else
            log_warn "  $ext: MISSING — may be needed by the application"
        fi
    done
fi

# ── 4. Install Playwright Chromium ─────────

echo ""
log_info "[4/5] Installing Playwright Chromium browser (as $APP_USER)..."
sudo -u "$APP_USER" npx playwright install chromium 2>&1

# ── 5. Locate & verify Chromium ────────────

echo ""
log_info "[5/5] Locating and verifying Chromium binary..."

CHROMIUM_PATH=""

for cache_base in \
    "/home/$APP_USER/.cache/ms-playwright" \
    "/var/www/.cache/ms-playwright" \
    "/root/.cache/ms-playwright"; do

    if [ -d "$cache_base" ]; then
        found=$(find "$cache_base" -maxdepth 2 -path '*/chrome-linux64/chrome' -type f -executable 2>/dev/null | sort -r | head -1)
        if [ -n "$found" ]; then
            CHROMIUM_PATH="$found"
            break
        fi
    fi
done

if [ -z "$CHROMIUM_PATH" ] || [ ! -f "$CHROMIUM_PATH" ]; then
    die "Could not find Chromium binary after installation."
fi

log_info "  Binary : $CHROMIUM_PATH"

VERSION=$(sudo -u "$APP_USER" "$CHROMIUM_PATH" --version 2>&1) || {
    log_error "Chromium --version check failed. Missing system libraries?"
    log_error "Try: ldd '$CHROMIUM_PATH' | grep 'not found'"
    die "Chromium cannot be launched. Run 'npx playwright install-deps chromium' manually."
}

log_info "  Version: $VERSION"

# ── Persist the path in .env ───────────────

ENV_FILE="$APP_DIR/.env"
if [ -f "$ENV_FILE" ]; then
    if grep -q "^PLAYWRIGHT_CHROMIUM_PATH=" "$ENV_FILE"; then
        sed -i "s|^PLAYWRIGHT_CHROMIUM_PATH=.*|PLAYWRIGHT_CHROMIUM_PATH=$CHROMIUM_PATH|" "$ENV_FILE"
    else
        echo "" >> "$ENV_FILE"
        echo "PLAYWRIGHT_CHROMIUM_PATH=$CHROMIUM_PATH" >> "$ENV_FILE"
    fi
    log_info "  .env updated with PLAYWRIGHT_CHROMIUM_PATH"
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
echo "  2. Verify setup:"
echo "     sudo -u $APP_USER php $APP_DIR/artisan pccs:playwright-diagnose"
echo ""
echo "  3. Run PDF generation from the UI to confirm it works."
