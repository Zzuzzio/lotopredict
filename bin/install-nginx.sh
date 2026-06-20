#!/bin/bash
# Установка nginx vhost lotopredict (только zzzzzzz.tw1.ru)
set -euo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)"

install -m 644 "$DIR/deploy/nginx/lotopredict.conf" /etc/nginx/sites-available/lotopredict
ln -sf /etc/nginx/sites-available/lotopredict /etc/nginx/sites-enabled/lotopredict

nginx -t
systemctl reload nginx
echo "OK: nginx lotopredict"
