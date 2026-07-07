#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

CODE="${1:-}"

if [ -z "$CODE" ]; then
  echo "Usage: $0 <coupon_code>"
  exit 1
fi

echo "=== Validate Promotion Code: $CODE ==="
curl -s -X POST "$UCP_API/promotions/validate" \
  -H "Content-Type: application/json" \
  -d "{\"code\": \"$CODE\"}" | python3 -m json.tool
