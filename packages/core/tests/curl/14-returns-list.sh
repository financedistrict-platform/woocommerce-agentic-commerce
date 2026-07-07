#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${ORDER_ID:-}" ]; then
  echo "Usage: ORDER_ID=<id> $0"
  exit 1
fi

echo "=== List Returns for Order $ORDER_ID ==="
curl -s "$UCP_API/orders/$ORDER_ID/returns" \
  ${UCP_AGENT:+-H "UCP-Agent: $UCP_AGENT"} | python3 -m json.tool
