#!/bin/bash
# One-time server setup for LotoPredict on a dedicated parsing host.
set -euo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$DIR"

echo "==> LotoPredict server setup"
echo "==> Project: $DIR"

if command -v apt-get >/dev/null 2>&1; then
  echo "==> Installing PHP + SQLite + curl..."
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq \
    php-cli php-sqlite3 php-curl php-json php-mbstring \
    curl ca-certificates git unzip
fi

if ! command -v php >/dev/null 2>&1; then
  echo "ERROR: php not found. Install PHP 7.2+ manually."
  exit 1
fi

echo "PHP: $(php -v | head -1)"
php -m | grep -E 'pdo_sqlite|curl|json' || true

mkdir -p storage/logs storage/ml
chmod +x bin/*.sh bin/*.php 2>/dev/null || true

echo "==> Initializing SQLite database..."
php -r "require 'bootstrap.php'; App\Database\Connection::get(); echo 'DB OK: ' . (require 'config/app.php')['db_path'] . PHP_EOL;"

echo "==> Installing headless browser (Playwright + Chromium)..."
if bash bin/install_browser.sh; then
  export PATH="$DIR/browser/nodejs/bin:$PATH"
  cd browser && npx playwright install-deps chromium 2>/dev/null || true
  cd "$DIR"
  echo "Browser parser: OK"
else
  echo "WARN: browser install failed — use --curl-only mode or retry install_browser.sh"
fi

echo ""
echo "==> Quick API test (5x36plus)..."
php bin/test_stoloto_api.php 5x36plus || true

echo ""
echo "Done. Commands:"
echo "  php bin/fetch_5x36plus.php --status          # DB + JSONL status"
echo "  php bin/fetch_5x36plus.php --recent          # latest draws"
echo "  php bin/fetch_5x36plus.php --full            # full archive (browser, ~1-3h)"
echo "  php bin/fetch_5x36plus.php --full --curl-only # curl parallel fallback"
echo "  bash bin/fetch_full_archive.sh --lottery=gosloto-5x36plus"
