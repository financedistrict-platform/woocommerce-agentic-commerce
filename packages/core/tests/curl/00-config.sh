#!/usr/bin/env bash
# Shared config for all curl tests.
# Source this file: . ./00-config.sh

BASE_URL="${BASE_URL:-http://localhost:8080}"
UCP_API="$BASE_URL/wp-json/fd-ucp/v1"
SESSION_TOKEN="${SESSION_TOKEN:-}"

curl() {
  if [ -n "$SESSION_TOKEN" ]; then
    command curl -H "UCP-Session-Token: $SESSION_TOKEN" "$@"
  else
    command curl "$@"
  fi
}
