#!/bin/bash
set -euo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)"
BROWSER_DIR="$DIR/browser"
NODE_DIR="$BROWSER_DIR/nodejs"
NODE_BIN="$NODE_DIR/bin/node"

# Node 18+ needs glibc 2.28+ (Ubuntu 20.04+). Ubuntu 18.04 has glibc 2.27 → Node 16.
GLIBC_VER="$(ldd --version 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+$' || echo "0")"
GLIBC_MAJOR="${GLIBC_VER%%.*}"
GLIBC_MINOR="${GLIBC_VER#*.}"

if [ "$GLIBC_MAJOR" -gt 2 ] || { [ "$GLIBC_MAJOR" -eq 2 ] && [ "$GLIBC_MINOR" -ge 28 ]; }; then
  NODE_VERSION="18.20.4"
  PLAYWRIGHT_VERSION="1.49.0"
else
  NODE_VERSION="16.20.2"
  PLAYWRIGHT_VERSION="1.39.0"
fi

echo "==> LotoPredict browser parser setup"
echo "==> glibc $GLIBC_VER detected → Node $NODE_VERSION, Playwright $PLAYWRIGHT_VERSION"

download_node() {
  echo "==> Downloading Node.js $NODE_VERSION (portable)..."
  TMP="$(mktemp -d)"
  ARCH="x64"
  curl -fsSL "https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-${ARCH}.tar.xz" \
    | tar -xJ -C "$TMP"
  rm -rf "$NODE_DIR"
  mv "$TMP/node-v${NODE_VERSION}-linux-${ARCH}" "$NODE_DIR"
  rm -rf "$TMP"
}

needs_download=0
if [ ! -x "$NODE_BIN" ]; then
  needs_download=1
else
  if ! "$NODE_BIN" -v >/dev/null 2>&1; then
    echo "==> Existing Node binary broken (glibc mismatch?), re-downloading..."
    rm -rf "$NODE_DIR"
    needs_download=1
  fi
fi

if [ "$needs_download" -eq 1 ]; then
  download_node
fi

export PATH="$NODE_DIR/bin:$PATH"

NODE_VER="$("$NODE_BIN" -v)"
echo "Node: $NODE_VER"

NODE_MAJOR="${NODE_VER#v}"
NODE_MAJOR="${NODE_MAJOR%%.*}"
if [ "$NODE_MAJOR" -lt 16 ]; then
  echo "ERROR: Need Node 16+, got $NODE_VER"
  exit 1
fi

echo "==> Installing Chromium system dependencies (apt)..."
DEPS="libnss3 libnspr4 libatk1.0-0 libatk-bridge2.0-0 libxkbcommon0 libatspi2.0-0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1 libpango-1.0-0 libcairo2 libasound2 fonts-liberation libdrm2 libxshmfence1 libgtk-3-0"
if command -v apt-get >/dev/null 2>&1; then
  apt-get update -qq || true
  apt-get install -y -qq $DEPS || echo "WARN: some apt packages failed; chromium may not start"
fi

cd "$BROWSER_DIR"
export PLAYWRIGHT_BROWSERS_PATH="$BROWSER_DIR/.playwright-browsers"

# Pin Playwright version for this glibc/node combo
node -e "
const fs=require('fs');
const p=JSON.parse(fs.readFileSync('package.json','utf8'));
p.dependencies.playwright='$PLAYWRIGHT_VERSION';
fs.writeFileSync('package.json', JSON.stringify(p,null,2)+'\n');
"

echo "==> npm install..."
rm -rf node_modules package-lock.json
npm install --no-fund --no-audit

echo "==> Installing Playwright Chromium..."
npx playwright install chromium

echo "$NODE_BIN" > "$BROWSER_DIR/.node-bin"
chmod +x "$BROWSER_DIR/run.sh"

echo ""
echo "Done. Node: $NODE_BIN ($(node -v))"
echo "Test:"
echo "  $BROWSER_DIR/run.sh --game=5x36plus --url=https://www.stoloto.ru/5x36plus/archive --pages=3 --count=50"
