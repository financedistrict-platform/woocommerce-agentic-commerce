#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${SESSION_ID:-}" ]; then
  echo "Usage: SESSION_ID=<id> $0"
  exit 1
fi

echo "=== Get Buyer Identity for Session $SESSION_ID ==="
curl -s "$UCP_API/checkout-sessions/$SESSION_ID/buyer" \
  ${UCP_AGENT:+-H "UCP-Agent: $UCP_AGENT"} | python3 -m json.tool
