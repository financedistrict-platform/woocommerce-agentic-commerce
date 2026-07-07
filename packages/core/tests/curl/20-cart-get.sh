#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${CART_ID:-}" ]; then
  echo "Usage: CART_ID=<id> $0"
  exit 1
fi

echo "=== Get Cart $CART_ID ==="
curl -s "$UCP_API/carts/$CART_ID" \
  ${UCP_AGENT:+-H "UCP-Agent: $UCP_AGENT"} | python3 -m json.tool
