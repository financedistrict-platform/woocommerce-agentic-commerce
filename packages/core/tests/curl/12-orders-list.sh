#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

AGENT="${UCP_AGENT:-}"
LIMIT="${1:-10}"
OFFSET="${2:-0}"

echo "=== List Orders (limit=$LIMIT, offset=$OFFSET) ==="
curl -s "$UCP_API/orders?limit=$LIMIT&offset=$OFFSET" \
  ${AGENT:+-H "UCP-Agent: $AGENT"} | python3 -m json.tool
