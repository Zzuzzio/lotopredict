#!/bin/bash
set -e
DIR="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="${1:-gosloto-5x36plus}"

cd "$DIR"

if [ ! -f "$DIR/ml/.python-bin" ]; then
  echo "Run first: bash bin/install_ml.sh" >&2
  exit 1
fi

PY="$(cat "$DIR/ml/.python-bin")"

echo "=== Export dataset: $SLUG ==="
php bin/export_ml_dataset.php --lottery="$SLUG"

DATASET="$DIR/storage/ml/${SLUG}.json"
MODEL="$DIR/ml/models/${SLUG}.json"

echo "=== Train evolutionary NN ==="
"$PY" "$DIR/ml/train.py" \
  --dataset="$DATASET" \
  --out="$MODEL" \
  --generations="${GENERATIONS:-120}" \
  --population="${POPULATION:-50}"

echo "=== Done. Model: $MODEL ==="
