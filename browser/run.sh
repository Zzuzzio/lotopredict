#!/bin/bash
DIR="$(cd "$(dirname "$0")" && pwd)"
NODE_BIN="$DIR/nodejs/bin/node"

if [ ! -x "$NODE_BIN" ]; then
  if [ -f "$DIR/.node-bin" ] && [ -x "$(cat "$DIR/.node-bin")" ]; then
    NODE_BIN="$(cat "$DIR/.node-bin")"
    export PATH="$(dirname "$NODE_BIN"):$PATH"
  else
    echo "ERROR: Node not found. Run: bash bin/install_browser.sh" >&2
    exit 1
  fi
else
  export PATH="$DIR/nodejs/bin:$PATH"
fi

# Apache/PHP-FPM often has low nproc limits; Chromium needs more threads
ulimit -u 65535 2>/dev/null || ulimit -u 8192 2>/dev/null || ulimit -u 4096 2>/dev/null || true
ulimit -n 65535 2>/dev/null || ulimit -n 8192 2>/dev/null || true

export PLAYWRIGHT_BROWSERS_PATH="$DIR/.playwright-browsers"
exec "$NODE_BIN" "$DIR/fetch_archive.js" "$@"
