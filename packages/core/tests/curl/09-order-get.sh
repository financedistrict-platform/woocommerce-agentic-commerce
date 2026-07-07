#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${ORDER_ID:-}" ]; then
  echo "Usage: ORDER_ID=<id> $0"
  exit 1
fi

echo "=== Get Order $ORDER_ID ==="
curl -s "$UCP_API/orders/$ORDER_ID" | python3 -m json.tool
