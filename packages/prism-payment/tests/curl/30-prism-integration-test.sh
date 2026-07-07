#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
UCP_API="$BASE_URL/wp-json/fd-ucp/v1"
PRODUCT_ID="${1:-42}"
PASS=0
FAIL=0

pass() { echo "  ✓ $1"; PASS=$((PASS + 1)); }
fail() { echo "  ✗ $1: $2"; FAIL=$((FAIL + 1)); }

assert_eq() {
  local label="$1" expected="$2" actual="$3"
  if [ "$actual" = "$expected" ]; then pass "$label"; else fail "$label" "expected $expected, got $actual"; fi
}

assert_not_empty() {
  local label="$1" value="$2"
  if [ -n "$value" ] && [ "$value" != "null" ]; then pass "$label"; else fail "$label" "value is empty/null"; fi
}

assert_gt() {
  local label="$1" value="$2" min="$3"
  if [ "$value" -gt "$min" ] 2>/dev/null; then pass "$label"; else fail "$label" "expected >$min, got $value"; fi
}

echo "========================================"
echo "  Prism Payment Integration Tests"
echo "  Target: $BASE_URL"
echo "  Product: $PRODUCT_ID"
echo "========================================"
echo ""

# ── 1. Discovery: Prism handler present ──────────────────

echo "1. Discovery — Prism handler"

DISCOVERY=$(curl -sL "$BASE_URL/.well-known/ucp")

HANDLER_ID=$(echo "$DISCOVERY" | python3 -c "
import sys, json
d = json.load(sys.stdin)
handlers = d.get('ucp', {}).get('payment_handlers', {})
print('xyz.fd.prism_payment' if 'xyz.fd.prism_payment' in handlers else '')
" 2>/dev/null)
assert_eq "prism handler registered" "xyz.fd.prism_payment" "$HANDLER_ID"

X402_VERSION=$(echo "$DISCOVERY" | python3 -c "
import sys, json
d = json.load(sys.stdin)
h = d['ucp']['payment_handlers']['xyz.fd.prism_payment'][0]
print(h.get('id', ''))
" 2>/dev/null)
assert_eq "handler type is x402" "x402" "$X402_VERSION"

echo ""

# ── 2. Checkout: payment_handlers block ──────────────────

echo "2. Checkout — payment requirements"

SESSION=$(curl -s -X POST "$UCP_API/checkout-sessions" \
  -H "Content-Type: application/json" \
  -H 'UCP-Agent: profile="https://agent.test/.well-known/ucp"' \
  -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": 1}]}")

SESSION_ID=$(echo "$SESSION" | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])" 2>/dev/null)
assert_not_empty "checkout session created" "$SESSION_ID"

ACCEPTS=$(echo "$SESSION" | python3 -c "
import sys, json
d = json.load(sys.stdin)
h = d['ucp']['payment_handlers']['xyz.fd.prism_payment'][0]
a = h['config']['accepts'][0]
print(json.dumps(a))
" 2>/dev/null)
assert_not_empty "accepts block present" "$ACCEPTS"

echo ""

# ── 3. Accepts: network / asset / payTo ──────────────────

echo "3. Accepts — x402 payment config"

NETWORK=$(echo "$ACCEPTS" | python3 -c "import sys,json; print(json.load(sys.stdin)['network'])" 2>/dev/null)
assert_eq "network is Base Sepolia" "eip155:84532" "$NETWORK"

SCHEME=$(echo "$ACCEPTS" | python3 -c "import sys,json; print(json.load(sys.stdin)['scheme'])" 2>/dev/null)
assert_eq "scheme is exact" "exact" "$SCHEME"

ASSET=$(echo "$ACCEPTS" | python3 -c "import sys,json; print(json.load(sys.stdin)['asset'].lower())" 2>/dev/null)
assert_eq "asset is USDC contract" "0x036cbd53842c5426634e7929541ec2318f3dcf7e" "$ASSET"

PAY_TO=$(echo "$ACCEPTS" | python3 -c "import sys,json; print(json.load(sys.stdin)['payTo'].lower())" 2>/dev/null)
assert_not_empty "payTo address present" "$PAY_TO"

AMOUNT=$(echo "$ACCEPTS" | python3 -c "import sys,json; print(json.load(sys.stdin)['amount'])" 2>/dev/null)
assert_gt "amount > 0" "$AMOUNT" 0

EXTRA_NAME=$(echo "$ACCEPTS" | python3 -c "import sys,json; print(json.load(sys.stdin).get('extra',{}).get('name',''))" 2>/dev/null)
assert_eq "extra.name is USDC" "USDC" "$EXTRA_NAME"

echo ""

# ── 4. Resource URL matches session ──────────────────────

echo "4. Resource — URL integrity"

RESOURCE_URL=$(echo "$SESSION" | python3 -c "
import sys, json
d = json.load(sys.stdin)
print(d['ucp']['payment_handlers']['xyz.fd.prism_payment'][0]['config']['resource']['url'])
" 2>/dev/null)

EXPECTED_URL="$UCP_API/checkout-sessions/$SESSION_ID"
assert_eq "resource URL matches session" "$EXPECTED_URL" "$RESOURCE_URL"

RESOURCE_DESC=$(echo "$SESSION" | python3 -c "
import sys, json
d = json.load(sys.stdin)
print(d['ucp']['payment_handlers']['xyz.fd.prism_payment'][0]['config']['resource']['description'])
" 2>/dev/null)
assert_not_empty "resource description present" "$RESOURCE_DESC"

echo ""

# ── 5. Update → amount changes ──────────────────────────

echo "5. Amount recalculation on update"

UPDATED=$(curl -s -X PUT "$UCP_API/checkout-sessions/$SESSION_ID" \
  -H "Content-Type: application/json" \
  -H 'UCP-Agent: profile="https://agent.test/.well-known/ucp"' \
  -d "{
    \"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": 2}],
    \"buyer\": {\"email\": \"prism-test@example.com\"},
    \"fulfillment\": {
      \"methods\": [{
        \"type\": \"shipping\",
        \"destinations\": [{
          \"street_address\": \"1 Market St\",
          \"address_locality\": \"San Francisco\",
          \"address_region\": \"CA\",
          \"postal_code\": \"94105\",
          \"address_country\": \"US\"
        }]
      }]
    }
  }")

NEW_AMOUNT=$(echo "$UPDATED" | python3 -c "
import sys, json
d = json.load(sys.stdin)
print(d['ucp']['payment_handlers']['xyz.fd.prism_payment'][0]['config']['accepts'][0]['amount'])
" 2>/dev/null)
assert_gt "amount increased after qty=2" "$NEW_AMOUNT" "$AMOUNT"

STATUS=$(echo "$UPDATED" | python3 -c "import sys,json; print(json.load(sys.stdin)['status'])" 2>/dev/null)
assert_eq "status is ready_for_complete" "ready_for_complete" "$STATUS"

echo ""

# ── 6. Cancel → no payment_handlers ─────────────────────

echo "6. Cancel — payment handlers removed"

CANCELED=$(curl -s -X POST "$UCP_API/checkout-sessions/$SESSION_ID/cancel" \
  -H "Content-Type: application/json" \
  -H 'UCP-Agent: profile="https://agent.test/.well-known/ucp"')

CANCEL_STATUS=$(echo "$CANCELED" | python3 -c "import sys,json; print(json.load(sys.stdin)['status'])" 2>/dev/null)
assert_eq "session canceled" "canceled" "$CANCEL_STATUS"

echo ""

# ── Summary ──────────────────────────────────────────────

echo "========================================"
echo "  Results: $PASS passed, $FAIL failed"
echo "========================================"

[ "$FAIL" -eq 0 ] || exit 1
