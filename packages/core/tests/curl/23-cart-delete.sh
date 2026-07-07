#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${CART_ID:-}" ]; then
  echo "Usage: CART_ID=<id> $0"
  exit 1
fi

echo "=== Delete Cart $CART_ID ==="
curl -s -o /dev/null -w "HTTP Status: %{http_code}\n" -X DELETE "$UCP_API/carts/$CART_ID" \
  ${UCP_AGENT:+-H "UCP-Agent: $UCP_AGENT"}
