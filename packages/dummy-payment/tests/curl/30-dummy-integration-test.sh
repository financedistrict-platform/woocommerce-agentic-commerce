#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
UCP_API="$BASE_URL/wp-json/fd-ucp/v1"
UCP_PROFILE="${UCP_PROFILE:-https://fd.xyz/.well-known/ucp}"
UCP_API_KEY="${UCP_API_KEY:-}"

ucp_curl() {
  curl -H "UCP-Agent: profile=\"$UCP_PROFILE\"" -H "X-API-Key: $UCP_API_KEY" "$@"
}

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

HANDLER_NS="xyz.fd.dummy_payment"

echo "========================================"
echo "  Dummy Payment Integration Tests"
echo "  Target: $BASE_URL"
echo "  Product: $PRODUCT_ID"
echo "========================================"
echo ""

# ── 1. Discovery: dummy handler is never advertised ─────

echo "1. Discovery — dummy handler hidden"

DISCOVERY=$(curl -sL "$BASE_URL/.well-known/ucp")

FOUND=$(echo "$DISCOVERY" | python3 -c "
import sys, json
d = json.load(sys.stdin)
handlers = d.get('ucp', {}).get('payment_handlers', {})
print('listed' if '$HANDLER_NS' in handlers else 'hidden')
" 2>/dev/null)
assert_eq "dummy handler not in discovery" "hidden" "$FOUND"

echo ""

# ── 3. Checkout: dummy handler in payment_handlers ──────

echo "3. Checkout — payment requirements"

SESSION=$(ucp_curl -s -X POST "$UCP_API/checkout-sessions" \
  -H "Content-Type: application/json" \
  -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": 1}]}")

SESSION_ID=$(echo "$SESSION" | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])" 2>/dev/null)
assert_not_empty "checkout session created" "$SESSION_ID"

CHECKOUT_ACCEPTS=$(echo "$SESSION" | python3 -c "
import sys, json
d = json.load(sys.stdin)
h = d['ucp']['payment_handlers']['$HANDLER_NS'][0]
a = h['config']['accepts'][0]
print(json.dumps(a))
" 2>/dev/null)
assert_not_empty "dummy accepts in checkout" "$CHECKOUT_ACCEPTS"

CHECKOUT_NETWORK=$(echo "$CHECKOUT_ACCEPTS" | python3 -c "import sys,json; print(json.load(sys.stdin)['network'])" 2>/dev/null)
assert_eq "checkout network is dummy:testnet" "dummy:testnet" "$CHECKOUT_NETWORK"

CHECKOUT_AMOUNT=$(echo "$CHECKOUT_ACCEPTS" | python3 -c "import sys,json; print(json.load(sys.stdin)['amount'])" 2>/dev/null)
assert_gt "checkout amount > 0" "$CHECKOUT_AMOUNT" 0

echo ""

# ── 4. Coexistence: both handlers present ───────────────

echo "4. Coexistence — multiple handlers"

HANDLER_COUNT=$(echo "$SESSION" | python3 -c "
import sys, json
d = json.load(sys.stdin)
print(len(d['ucp']['payment_handlers']))
" 2>/dev/null)
assert_gt "more than 1 handler" "$HANDLER_COUNT" 1

HAS_PRISM=$(echo "$SESSION" | python3 -c "
import sys, json
d = json.load(sys.stdin)
print('yes' if 'xyz.fd.prism_payment' in d['ucp']['payment_handlers'] else 'no')
" 2>/dev/null)
assert_eq "prism handler also present" "yes" "$HAS_PRISM"

echo ""

# ── 5. Update → amount recalculates ────────────────────

echo "5. Amount recalculation on update"

UPDATED=$(ucp_curl -s -X PUT "$UCP_API/checkout-sessions/$SESSION_ID" \
  -H "Content-Type: application/json" \
  -d "{
    \"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": 3}],
    \"buyer\": {\"email\": \"dummy-test@example.com\"},
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
print(d['ucp']['payment_handlers']['$HANDLER_NS'][0]['config']['accepts'][0]['amount'])
" 2>/dev/null)
assert_gt "amount increased after qty=3" "$NEW_AMOUNT" "$CHECKOUT_AMOUNT"

STATUS=$(echo "$UPDATED" | python3 -c "import sys,json; print(json.load(sys.stdin)['status'])" 2>/dev/null)
assert_eq "status is ready_for_complete" "ready_for_complete" "$STATUS"

echo ""

# ── 6. Complete → dummy settlement succeeds ─────────────

echo "6. Complete — dummy settlement"

COMPLETED=$(ucp_curl -s -X POST "$UCP_API/checkout-sessions/$SESSION_ID/complete" \
  -H "Content-Type: application/json" \
  -d "{
    \"payment\": {
      \"instruments\": [{
        \"id\": \"inst_1\",
        \"handler_id\": \"$HANDLER_NS\",
        \"type\": \"dummy\",
        \"credential\": {\"type\": \"dummy\", \"dummy\": true}
      }]
    }
  }")

COMPLETE_STATUS=$(echo "$COMPLETED" | python3 -c "import sys,json; print(json.load(sys.stdin)['status'])" 2>/dev/null)
assert_eq "status is completed" "completed" "$COMPLETE_STATUS"

TX_REF=$(echo "$COMPLETED" | python3 -c "import sys,json; print(json.load(sys.stdin)['order']['transaction_reference'])" 2>/dev/null)
assert_not_empty "transaction reference present" "$TX_REF"

ORDER_NETWORK=$(echo "$COMPLETED" | python3 -c "import sys,json; print(json.load(sys.stdin)['order']['network'])" 2>/dev/null)
assert_eq "order network is dummy:testnet" "dummy:testnet" "$ORDER_NETWORK"

ORDER_ID=$(echo "$COMPLETED" | python3 -c "import sys,json; print(json.load(sys.stdin)['order']['id'])" 2>/dev/null)
assert_not_empty "WooCommerce order created" "$ORDER_ID"
assert_eq "order id is the opaque order key" "wc_order_" "${ORDER_ID:0:9}"

ORDER_GET=$(ucp_curl -s "$UCP_API/orders/$ORDER_ID" | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])" 2>/dev/null)
assert_eq "order is retrievable by its id" "$ORDER_ID" "$ORDER_GET"

ORDER_LIST_STATUS=$(ucp_curl -s -o /dev/null -w "%{http_code}" "$UCP_API/orders")
assert_eq "orders cannot be listed" "404" "$ORDER_LIST_STATUS"

echo ""

# ── Summary ─────────────────────────────────────────────

echo "========================================"
echo "  Results: $PASS passed, $FAIL failed"
echo "========================================"

[ "$FAIL" -eq 0 ] || exit 1
