#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

PRODUCT_ID="${1:-14}"
QUANTITY="${2:-1}"

echo "=== Create Cart (product $PRODUCT_ID x$QUANTITY) ==="
RESPONSE=$(curl -s -X POST "$UCP_API/carts" \
  -H "Content-Type: application/json" \
  ${UCP_AGENT:+-H "UCP-Agent: $UCP_AGENT"} \
  -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": $QUANTITY}]}")

echo "$RESPONSE" | python3 -m json.tool

CART_ID=$(echo "$RESPONSE" | python3 -c "import json,sys; print(json.load(sys.stdin)['id'])" 2>/dev/null)
if [ -n "$CART_ID" ]; then
  echo ""
  echo "Cart ID: $CART_ID"
  echo "Export it:  export CART_ID=$CART_ID"
fi
