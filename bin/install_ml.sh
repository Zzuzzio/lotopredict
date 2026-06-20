#!/bin/bash
set -e
DIR="$(cd "$(dirname "$0")/.." && pwd)"
ML="$DIR/ml"

echo "=== LotoPredict ML setup (pure Python) ==="

if ! command -v python3 >/dev/null 2>&1; then
  echo "ERROR: python3 not found." >&2
  exit 1
fi

python3 --version
command -v python3 > "$ML/.python-bin"
mkdir -p "$DIR/ml/models" "$DIR/storage/ml"

echo "Python: $(cat "$ML/.python-bin")"
python3 -c "import json, math; print('stdlib OK')"
echo "=== ML ready (no pip needed) ==="
echo "Next: bash bin/train_evolution.sh gosloto-5x36plus"
