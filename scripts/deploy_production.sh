#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/budget-backend}"
BRANCH="${BRANCH:-main}"

cd "$APP_DIR"

git fetch origin "$BRANCH"
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

php scripts/migrate.php
