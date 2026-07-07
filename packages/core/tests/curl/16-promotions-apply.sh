#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${SESSION_ID:-}" ]; then
  echo "Usage: SESSION_ID=<id> $0 <coupon_code>"
  exit 1
fi

CODE="${1:-}"
if [ -z "$CODE" ]; then
  echo "Usage: SESSION_ID=<id> $0 <coupon_code>"
  exit 1
fi

echo "=== Apply Promotion '$CODE' to Session $SESSION_ID ==="
curl -s -X POST "$UCP_API/checkout-sessions/$SESSION_ID/promotions" \
  -H "Content-Type: application/json" \
  ${UCP_AGENT:+-H "UCP-Agent: $UCP_AGENT"} \
  -d "{\"code\": \"$CODE\"}" | python3 -m json.tool
