#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

PRODUCT_ID="${1:-14}"

echo "=== Catalog Lookup (product $PRODUCT_ID) ==="
curl -s -X POST "$UCP_API/catalog/lookup" \
  -H "Content-Type: application/json" \
  -d "{\"ids\": [\"$PRODUCT_ID\"]}" | python3 -m json.tool
