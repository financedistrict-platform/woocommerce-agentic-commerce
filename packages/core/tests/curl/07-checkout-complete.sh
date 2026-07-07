#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

HANDLER_ID="${HANDLER_ID:-xyz.fd.prism_payment}"

if [ -z "${SESSION_ID:-}" ]; then
  echo "Usage: SESSION_ID=<id> CREDENTIAL='<json>' [HANDLER_ID=<id>] $0"
  echo ""
  echo "The CREDENTIAL should be the payment authorization object from"
  echo "the payment handler (e.g. an x402 authorization from Agent Wallet)."
  echo "HANDLER_ID defaults to xyz.fd.prism_payment."
  exit 1
fi

if [ -z "${CREDENTIAL:-}" ]; then
  echo "Error: CREDENTIAL env var is required."
  echo "Obtain a signed credential from the payment handler,"
  echo "then pass the authorization object as CREDENTIAL."
  exit 1
fi

echo "=== Complete Checkout Session $SESSION_ID ==="
curl -s -X POST "$UCP_API/checkout-sessions/$SESSION_ID/complete" \
  -H "Content-Type: application/json" \
  -d "{
    \"payment\": {
      \"instruments\": [{
        \"handler_id\": \"$HANDLER_ID\",
        \"credential\": $CREDENTIAL
      }]
    }
  }" | python3 -m json.tool
