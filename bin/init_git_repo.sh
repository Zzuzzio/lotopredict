#!/bin/bash
# Initialize git repository for LotoPredict (run once).
set -e
DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$DIR"

if ! command -v git >/dev/null 2>&1; then
  echo "Git not installed. Run as root: apt install git" >&2
  exit 1
fi

if [ -d .git ]; then
  echo "Repository already exists in $DIR"
else
  git init
  git checkout -b main 2>/dev/null || git branch -M main 2>/dev/null || true
  echo "Initialized git repository in $DIR"
fi

git add -A
git status --short | head -30
echo "..."

if git diff --cached --quiet; then
  echo "Nothing to commit."
  exit 0
fi

git commit -m "$(cat <<'EOF'
Initial commit: LotoPredict for Stoloto lotteries.

PHP app with SQLite, Stoloto archive import, statistics, statistical
and evolutionary NN predictions for 5x36plus (main + bonus field).
EOF
)"

echo ""
git log -1 --oneline
echo "Done. Remote: git remote add origin <url> && git push -u origin main"
