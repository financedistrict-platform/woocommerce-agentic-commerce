#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

LIMIT="${1:-10}"
OFFSET="${2:-0}"

echo "=== List Orders (limit=$LIMIT, offset=$OFFSET) ==="
curl -s "$UCP_API/orders?limit=$LIMIT&offset=$OFFSET" | python3 -m json.tool
