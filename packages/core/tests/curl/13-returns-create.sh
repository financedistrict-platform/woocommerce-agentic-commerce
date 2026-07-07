#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${ORDER_ID:-}" ]; then
  echo "Usage: ORDER_ID=<id> $0 [line_item_id] [quantity]"
  echo "  Without args: full refund"
  echo "  With args:    partial refund"
  exit 1
fi

LINE_ITEM_ID="${1:-}"
QUANTITY="${2:-1}"

echo "=== Create Return for Order $ORDER_ID ==="

if [ -z "$LINE_ITEM_ID" ]; then
  echo "(Full refund)"
  curl -s -X POST "$UCP_API/orders/$ORDER_ID/returns" \
    -H "Content-Type: application/json" \
    ${UCP_AGENT:+-H "UCP-Agent: $UCP_AGENT"} \
    -d '{}' | python3 -m json.tool
else
  echo "(Partial refund: item $LINE_ITEM_ID x$QUANTITY)"
  curl -s -X POST "$UCP_API/orders/$ORDER_ID/returns" \
    -H "Content-Type: application/json" \
    ${UCP_AGENT:+-H "UCP-Agent: $UCP_AGENT"} \
    -d "{\"items\": [{\"line_item_id\": $LINE_ITEM_ID, \"quantity\": $QUANTITY, \"reason\": \"Test refund\"}]}" | python3 -m json.tool
fi
