#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

PRODUCT_ID="${1:-14}"
QUANTITY="${2:-1}"

echo "=== Create Checkout Session (product $PRODUCT_ID x$QUANTITY) ==="
HDR=$(mktemp)
RESPONSE=$(curl -s -D "$HDR" -X POST "$UCP_API/checkout-sessions" \
  -H "Content-Type: application/json" \
  -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": $QUANTITY}]}")

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
