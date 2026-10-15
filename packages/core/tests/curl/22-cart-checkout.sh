#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${CART_ID:-}" ]; then
  echo "Usage: CART_ID=<id> $0"
  exit 1
fi

echo "=== Convert Cart $CART_ID to Checkout Session ==="
HDR=$(mktemp)
RESPONSE=$(curl -s -D "$HDR" -X POST "$UCP_API/carts/$CART_ID/checkout" \
  -H "Content-Type: application/json")

echo "$RESPONSE" | python3 -m json.tool

SESSION_ID=$(echo "$RESPONSE" | python3 -c "import json,sys; print(json.load(sys.stdin)['id'])" 2>/dev/null)
if [ -n "$SESSION_ID" ]; then
  echo ""
  echo "Session ID: $SESSION_ID"
  echo "Export it:  export SESSION_ID=$SESSION_ID"
fi

SECRET=$(secret_from_headers "$HDR")
if [ -n "$SECRET" ]; then
  echo "Export it:  export UCP_SESSION_SECRET=$SECRET"
fi
rm -f "$HDR"
