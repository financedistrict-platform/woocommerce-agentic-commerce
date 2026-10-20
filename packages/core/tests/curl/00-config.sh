#!/usr/bin/env bash

BASE_URL="${BASE_URL:-http://localhost:8080}"
UCP_API="$BASE_URL/wp-json/fd-ucp/v1"
UCP_PROFILE="${UCP_PROFILE:-https://fd.xyz/.well-known/ucp}"
UCP_API_KEY="${UCP_API_KEY:-}"

curl() {
  command curl -H "UCP-Agent: profile=\"$UCP_PROFILE\"" -H "X-API-Key: $UCP_API_KEY" ${UCP_SESSION_SECRET:+-H "UCP-Session-Secret: $UCP_SESSION_SECRET"} "$@"
}

secret_from_headers() {
  tr -d '' < "$1" | awk -F': ' 'tolower($1)=="ucp-session-secret"{print $2}' | tail -1
}
