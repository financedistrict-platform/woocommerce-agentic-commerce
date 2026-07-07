#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${CART_ID:-}" ]; then
  echo "Usage: CART_ID=<id> $0 <product_id> <quantity>"
  exit 1
fi

PRODUCT_ID="${1:-14}"
QUANTITY="${2:-2}"

echo "=== Update Cart $CART_ID (product $PRODUCT_ID x$QUANTITY) ==="
curl -s -X PUT "$UCP_API/carts/$CART_ID" \
  -H "Content-Type: application/json" \
  ${UCP_AGENT:+-H "UCP-Agent: $UCP_AGENT"} \
  -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": $QUANTITY}]}" | python3 -m json.tool
