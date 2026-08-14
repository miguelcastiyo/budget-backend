#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/budget-backend}"
BRANCH="${BRANCH:-main}"

BACKEND_URL="${BACKEND_URL:-https://api-budget.miguelcastillo.info}"
MAX_TIME="${MAX_TIME:-20}"
HEALTH_RETRIES="${HEALTH_RETRIES:-3}"
HEALTH_RETRY_SLEEP_SECONDS="${HEALTH_RETRY_SLEEP_SECONDS:-5}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"

check_backend_health() {
  local check_name="$1"
  local path="$2"

  local url="${BACKEND_URL%/}${path}"
  local attempt=1

  while (( attempt <= HEALTH_RETRIES )); do
    local body_file
    body_file="$(mktemp)"

    local http_status="000"
    if http_status="$(
      curl \
        --silent \
        --show-error \
        --max-time "$MAX_TIME" \
        --output "$body_file" \
        --write-out '%{http_code}' \
        "$url"
    )"; then
      : # http_status already set
    else
      http_status="000"
    fi

    if [[ "$http_status" == "200" ]] && grep -q '"ok"[[:space:]]*:[[:space:]]*true' "$body_file"; then
      rm -f "$body_file"
      return 0
    fi

    if (( attempt < HEALTH_RETRIES )); then
      rm -f "$body_file"
      sleep "$HEALTH_RETRY_SLEEP_SECONDS"
      attempt=$(( attempt + 1 ))
      continue
    fi

    echo "Backend health verification failed: $check_name" >&2
    echo "URL: $url" >&2
    echo "Expected: HTTP 200 and body containing \"ok\":true" >&2
    echo "Actual HTTP status: $http_status" >&2
    if [[ -s "$body_file" ]]; then
      echo "--- response body (first 20 lines) ---" >&2
      head -n 20 "$body_file" >&2 || true
      echo "--------------------------------------" >&2
    else
      echo "Response body: (empty)" >&2
    fi
    rm -f "$body_file"
    echo "Hint: see docs/operations/production-health.md" >&2
    return 1
  done
}

cd "$APP_DIR"

git fetch origin "$BRANCH"
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

if ! command -v composer >/dev/null 2>&1; then
  echo "Composer 2 is required on the production host" >&2
  exit 1
fi
composer --version | grep -Eq 'Composer version 2\.'

composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction

composer check-platform-reqs --no-dev
test -f vendor/autoload.php

RETIREMENT_MIGRATION="20260815_retire_legacy_auth_representation.sql"
MIGRATION_STATUS="$(php scripts/migrate.php --status)"
RETIREMENT_PENDING=0
if grep -Fq "$RETIREMENT_MIGRATION" <<<"$MIGRATION_STATUS"; then
  RETIREMENT_PENDING=1
  echo "Phase 4 auth retirement is pending; running authoritative preflight."
  php scripts/verify_auth_identity_retirement.php
fi

MIGRATION_OUTPUT="$(mktemp)"
if ! php scripts/migrate.php 2>&1 | tee "$MIGRATION_OUTPUT"; then
  if grep -q 'Phase 4 schema retirement refused' "$MIGRATION_OUTPUT"; then
    echo "Phase 4 migration is waiting for the approved account-pruning step; reloading PHP-FPM so the deployed compatible application code is active." >&2
    sudo systemctl reload "$PHP_FPM_SERVICE"
  fi
  rm -f "$MIGRATION_OUTPUT"
  exit 1
fi
rm -f "$MIGRATION_OUTPUT"

if (( RETIREMENT_PENDING )); then
  echo "Phase 4 auth retirement applied; running post-migration verification."
  php scripts/verify_auth_identity_retirement.php
fi

sudo systemctl reload "$PHP_FPM_SERVICE"

check_backend_health "health" "/api/v1/health"
check_backend_health "ready" "/api/v1/ready"
