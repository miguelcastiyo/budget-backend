#!/usr/bin/env bash
set -euo pipefail

FRONTEND_URL="${FRONTEND_URL:-https://budget.miguelcastillo.info}"
BACKEND_URL="${BACKEND_URL:-https://api-budget.miguelcastillo.info}"
MAX_TIME="${MAX_TIME:-20}"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

check_request() {
  local label="$1"
  local url="$2"
  local method="${3:-GET}"
  local expected_status="$4"
  local body_pattern="${5:-}"
  local body_file="$TMP_DIR/$(echo "$label" | tr ' /' '__').body"

  local curl_args=(
    --max-time "$MAX_TIME"
    --silent
    --show-error
    --output "$body_file"
    --write-out '%{http_code} %{time_total}'
    --request "$method"
  )

  if [[ "$method" == "POST" ]]; then
    curl_args+=(
      --header 'Content-Type: application/json'
      --data '{}'
    )
  fi

  local result
  result="$(curl "${curl_args[@]}" "$url")"
  local status="${result%% *}"
  local total="${result##* }"

  if [[ "$status" != "$expected_status" ]]; then
    echo "FAIL [$label] expected status $expected_status got $status in ${total}s" >&2
    if [[ -s "$body_file" ]]; then
      echo "Body:" >&2
      sed -n '1,20p' "$body_file" >&2
    fi
    exit 1
  fi

  if [[ -n "$body_pattern" ]] && ! grep -q "$body_pattern" "$body_file"; then
    echo "FAIL [$label] response body did not match pattern: $body_pattern" >&2
    if [[ -s "$body_file" ]]; then
      echo "Body:" >&2
      sed -n '1,20p' "$body_file" >&2
    fi
    exit 1
  fi

  echo "OK   [$label] status=$status total=${total}s"
}

check_request "backend health" "${BACKEND_URL%/}/api/v1/health" GET 200 '"ok":true'
check_request "frontend proxy health" "${FRONTEND_URL%/}/api/v1/health" GET 200 '"ok":true'
check_request "frontend google sign-in probe" "${FRONTEND_URL%/}/api/v1/auth/sessions/google" POST 422 '"code":"VALIDATION_ERROR"'

echo "Production health checks passed."
