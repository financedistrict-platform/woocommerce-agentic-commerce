#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

QUERY="${1:-}"

echo "=== Catalog Search ==="
if [ -n "$QUERY" ]; then
  curl -s -X POST "$UCP_API/catalog/search" \
    -H "Content-Type: application/json" \
    -d "{\"query\": \"$QUERY\", \"limit\": 10}" | python3 -m json.tool
else
  curl -s -X POST "$UCP_API/catalog/search" \
    -H "Content-Type: application/json" \
    -d '{"limit": 10}' | python3 -m json.tool
fi
