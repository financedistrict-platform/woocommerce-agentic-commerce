#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${SESSION_ID:-}" ]; then
  echo "Usage: SESSION_ID=<id> $0 [email] [first_name] [last_name]"
  exit 1
fi

EMAIL="${1:-agent@example.com}"
FIRST="${2:-Agent}"
LAST="${3:-Buyer}"

echo "=== Update Buyer Identity for Session $SESSION_ID ==="
curl -s -X PUT "$UCP_API/checkout-sessions/$SESSION_ID/buyer" \
  -H "Content-Type: application/json" \
  ${UCP_AGENT:+-H "UCP-Agent: $UCP_AGENT"} \
  -d "{\"email\": \"$EMAIL\", \"first_name\": \"$FIRST\", \"last_name\": \"$LAST\"}" | python3 -m json.tool
