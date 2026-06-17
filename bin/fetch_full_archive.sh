#!/bin/bash
# Wrapper with raised limits for long-running full archive fetch
DIR="$(cd "$(dirname "$0")/.." && pwd)"

ulimit -u 65535 2>/dev/null || ulimit -u 8192 2>/dev/null || true
ulimit -n 65535 2>/dev/null || true

cd "$DIR" || exit 1
exec php bin/fetch_full_archive.php "$@"
