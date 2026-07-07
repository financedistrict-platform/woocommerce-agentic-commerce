#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

PRODUCT_ID="${1:-42}"
PASS=0
FAIL=0
SKIP=0

pass() { echo "  ✓ $1"; PASS=$((PASS + 1)); }
fail() { echo "  ✗ $1: $2"; FAIL=$((FAIL + 1)); }
skip() { echo "  - $1 (skipped)"; SKIP=$((SKIP + 1)); }

assert_status() {
  local label="$1" expected="$2" actual="$3"
  if [ "$actual" = "$expected" ]; then pass "$label"; else fail "$label" "expected $expected, got $actual"; fi
}

assert_not_empty() {
  local label="$1" value="$2"
  if [ -n "$value" ] && [ "$value" != "null" ]; then pass "$label"; else fail "$label" "value is empty/null"; fi
}

echo "========================================"
echo "  UCP Integration Test Suite"
echo "  Target: $BASE_URL"
echo "  Product: $PRODUCT_ID"
echo "========================================"
echo ""

# ── 1. Discovery ──────────────────────────────────────────────

echo "1. Discovery"
DISC=$(curl -sL "$BASE_URL/.well-known/ucp")
UCP_VER=$(echo "$DISC" | python3 -c "import json,sys; print(json.load(sys.stdin).get('ucp',{}).get('version',''))" 2>/dev/null || true)
assert_not_empty "discovery returns ucp.version" "$UCP_VER"

CAPS=$(echo "$DISC" | python3 -c "import json,sys; caps=json.load(sys.stdin).get('ucp',{}).get('capabilities',{}); print(len(caps))" 2>/dev/null || echo "0")
if [ "$CAPS" -ge 9 ]; then pass "discovery lists ≥9 capabilities"; else fail "discovery capabilities" "got $CAPS, expected ≥9"; fi
echo ""

# ── 2. Catalog ────────────────────────────────────────────────

echo "2. Catalog"
SEARCH=$(curl -s -X POST "$UCP_API/catalog/search" \
  -H "Content-Type: application/json" \
  -d '{"query": "", "limit": 5}')
ITEM_COUNT=$(echo "$SEARCH" | python3 -c "import json,sys; print(len(json.load(sys.stdin).get('products',[])))" 2>/dev/null || echo "0")
if [ "$ITEM_COUNT" -gt 0 ]; then pass "catalog search returns products ($ITEM_COUNT)"; else fail "catalog search" "no products returned"; fi

LOOKUP=$(curl -s -X POST "$UCP_API/catalog/lookup" \
  -H "Content-Type: application/json" \
  -d "{\"ids\": [\"$PRODUCT_ID\"]}")
LOOKUP_ID=$(echo "$LOOKUP" | python3 -c "import json,sys; items=json.load(sys.stdin).get('products',[]); print(items[0]['id'] if items else '')" 2>/dev/null || true)
assert_not_empty "catalog lookup by ID" "$LOOKUP_ID"
echo ""

# ── 3. Cart ───────────────────────────────────────────────────

echo "3. Cart"
CART_RESP=$(curl -s -X POST "$UCP_API/carts" \
  -H "Content-Type: application/json" \
  -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": 1}]}")
CART_ID=$(echo "$CART_RESP" | python3 -c "import json,sys; print(json.load(sys.stdin).get('id',''))" 2>/dev/null || true)
assert_not_empty "create cart" "$CART_ID"

GET_CART=$(curl -s "$UCP_API/carts/$CART_ID")
GOT_CART_ID=$(echo "$GET_CART" | python3 -c "import json,sys; print(json.load(sys.stdin).get('id',''))" 2>/dev/null || true)
assert_status "get cart" "$CART_ID" "$GOT_CART_ID"

UPD_CART=$(curl -s -X PUT "$UCP_API/carts/$CART_ID" \
  -H "Content-Type: application/json" \
  -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": 2}]}")
UPD_QTY=$(echo "$UPD_CART" | python3 -c "import json,sys; print(json.load(sys.stdin)['line_items'][0]['quantity'])" 2>/dev/null || echo "0")
assert_status "update cart quantity" "2" "$UPD_QTY"

# Cart → checkout
CART_CO=$(curl -s -X POST "$UCP_API/carts/$CART_ID/checkout" \
  -H "Content-Type: application/json")
CART_SESSION=$(echo "$CART_CO" | python3 -c "import json,sys; print(json.load(sys.stdin).get('checkout_session_id',''))" 2>/dev/null || true)
assert_not_empty "cart → checkout session" "$CART_SESSION"

# Cart should be deleted after checkout
DEL_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$UCP_API/carts/$CART_ID")
assert_status "cart deleted after checkout" "404" "$DEL_STATUS"
echo ""

# ── 4. Checkout Session (fresh) ───────────────────────────────

echo "4. Checkout Session"
CREATE=$(curl -s -X POST "$UCP_API/checkout-sessions" \
  -H "Content-Type: application/json" \
  -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": 1}]}")
SESSION_ID=$(echo "$CREATE" | python3 -c "import json,sys; print(json.load(sys.stdin).get('id',''))" 2>/dev/null || true)
assert_not_empty "create checkout session" "$SESSION_ID"

GET_SESS=$(curl -s "$UCP_API/checkout-sessions/$SESSION_ID")
SESS_STATUS=$(echo "$GET_SESS" | python3 -c "import json,sys; print(json.load(sys.stdin).get('status',''))" 2>/dev/null || true)
assert_status "session status is incomplete" "incomplete" "$SESS_STATUS"
echo ""

# ── 5. Buyer Identity ────────────────────────────────────────

echo "5. Buyer Identity"
BUYER_UPD=$(curl -s -X PUT "$UCP_API/checkout-sessions/$SESSION_ID/buyer" \
  -H "Content-Type: application/json" \
  -d '{"email": "test@example.com", "first_name": "Test", "last_name": "Agent", "phone": "+1234567890"}')
BUYER_EMAIL=$(echo "$BUYER_UPD" | python3 -c "import json,sys; print(json.load(sys.stdin).get('buyer',{}).get('email',''))" 2>/dev/null || true)
assert_status "update buyer email" "test@example.com" "$BUYER_EMAIL"

BUYER_GET=$(curl -s "$UCP_API/checkout-sessions/$SESSION_ID/buyer")
BUYER_FIRST=$(echo "$BUYER_GET" | python3 -c "import json,sys; print(json.load(sys.stdin).get('buyer',{}).get('first_name',''))" 2>/dev/null || true)
assert_status "get buyer first_name" "Test" "$BUYER_FIRST"
echo ""

# ── 6. Cancel Session (for cleanup) ──────────────────────────

echo "6. Cancel Session"
CANCEL=$(curl -s -X POST "$UCP_API/checkout-sessions/$SESSION_ID/cancel" \
  -H "Content-Type: application/json")
CANCEL_STATUS=$(echo "$CANCEL" | python3 -c "import json,sys; print(json.load(sys.stdin).get('status',''))" 2>/dev/null || true)
assert_status "cancel session" "canceled" "$CANCEL_STATUS"
echo ""

# ── 7. Orders List ────────────────────────────────────────────

echo "7. Orders"
ORDERS=$(curl -s "$UCP_API/orders")
ORDER_STATUS=$(echo "$ORDERS" | python3 -c "import json,sys; print(json.load(sys.stdin).get('ucp',{}).get('status',''))" 2>/dev/null || true)
assert_status "list orders endpoint returns success" "success" "$ORDER_STATUS"
echo ""

# ── 8. Cart Delete ────────────────────────────────────────────

echo "8. Cart Delete"
CART2_RESP=$(curl -s -X POST "$UCP_API/carts" \
  -H "Content-Type: application/json" \
  -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": 1}]}")
CART2_ID=$(echo "$CART2_RESP" | python3 -c "import json,sys; print(json.load(sys.stdin).get('id',''))" 2>/dev/null || true)
assert_not_empty "create cart for delete test" "$CART2_ID"

DEL2_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X DELETE "$UCP_API/carts/$CART2_ID")
assert_status "delete cart returns 204" "204" "$DEL2_STATUS"

DEL2_GET=$(curl -s -o /dev/null -w "%{http_code}" "$UCP_API/carts/$CART2_ID")
assert_status "deleted cart returns 404" "404" "$DEL2_GET"
echo ""

# ── Summary ───────────────────────────────────────────────────

echo "========================================"
echo "  Results: $PASS passed, $FAIL failed, $SKIP skipped"
echo "========================================"

if [ "$FAIL" -gt 0 ]; then exit 1; fi
