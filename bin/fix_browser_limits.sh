#!/bin/bash
# Fix Chromium thread limits — run once as root if browser fetch is needed
# Usage: sudo bash bin/fix_browser_limits.sh

set -e

USER_NAME="${1:-site_user}"

echo "=== Checking limits for user: $USER_NAME ==="
su - "$USER_NAME" -c 'ulimit -u; ulimit -n' 2>/dev/null || true

echo ""
echo "=== Zombie Chrome processes ==="
pgrep -a chrome 2>/dev/null | wc -l || echo 0

echo ""
echo "=== Recommended: add to /etc/security/limits.d/stoloto.conf ==="
cat <<EOF
$USER_NAME soft nproc 65535
$USER_NAME hard nproc 65535
$USER_NAME soft nofile 65535
$USER_NAME hard nofile 65535
root soft nproc 65535
root hard nproc 65535
EOF

echo ""
echo "Then: killall chrome 2>/dev/null; log out and back in"
echo "Test browser: cd lotopredict && bash browser/run.sh --pages=1 --game=5x36plus --url=https://www.stoloto.ru/5x36plus/archive --out=/tmp/t.json"
