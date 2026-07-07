#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

echo "=== UCP Discovery ==="
curl -sL "$BASE_URL/.well-known/ucp" | python3 -m json.tool
