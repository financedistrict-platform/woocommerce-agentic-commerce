#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${ORDER_ID:-}" ]; then
  echo "Usage: ORDER_ID=<order id from the complete response> $0 [line_item_id] [quantity]"
  echo "  Without args: return request for the whole order"
  echo "  With args:    return request for one line item"
  exit 1
fi

LINE_ITEM_ID="${1:-}"
QUANTITY="${2:-1}"

echo "=== Create Return for Order $ORDER_ID ==="

if [ -z "$LINE_ITEM_ID" ]; then
  echo "(Whole order)"
  curl -s -X POST "$UCP_API/orders/$ORDER_ID/returns" \
    -H "Content-Type: application/json" \
    -d '{}' | python3 -m json.tool
else
  echo "(Item $LINE_ITEM_ID x$QUANTITY)"
  curl -s -X POST "$UCP_API/orders/$ORDER_ID/returns" \
    -H "Content-Type: application/json" \
    -d "{\"items\": [{\"line_item_id\": $LINE_ITEM_ID, \"quantity\": $QUANTITY, \"reason\": \"Test return\"}]}" | python3 -m json.tool
fi
