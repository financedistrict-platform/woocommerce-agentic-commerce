#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${SESSION_ID:-}" ]; then
  echo "Usage: SESSION_ID=<id> $0"
  exit 1
fi

echo "=== Cancel Checkout Session $SESSION_ID ==="
curl -s -X POST "$UCP_API/checkout-sessions/$SESSION_ID/cancel" \
  -H "Content-Type: application/json" | python3 -m json.tool
