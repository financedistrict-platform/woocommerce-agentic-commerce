#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

PRODUCT_ID="${1:-}"
UCP_VER="${UCP_VER:-}"
UCP_AGENT_PROFILE="${UCP_AGENT_PROFILE:-}"
PASS=0
FAIL=0
SKIP=0

HDRS=$(mktemp)
trap 'rm -f "$HDRS"' EXIT
SESSION_TOKEN=""

ucp_curl() {
  local headers=()
  [ -n "$UCP_AGENT_PROFILE" ] && headers+=(-H "UCP-Agent: profile=\"$UCP_AGENT_PROFILE\"")
  [ -n "$SESSION_TOKEN" ] && headers+=(-H "UCP-Session-Token: $SESSION_TOKEN")
  curl "${headers[@]}" "$@"
}

issued_token() { tr -d '\r' < "$HDRS" | awk -F': ' 'tolower($1)=="ucp-session-token"{print $2}'; }

json_get() { python3 -c "import json,sys; d=json.load(sys.stdin); $1" 2>/dev/null || true; }

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
echo "  UCP version: ${UCP_VER:-auto}"
echo "========================================"
echo ""

# ── 1. Discovery ──────────────────────────────────────────────

echo "1. Discovery"
if [ -n "$UCP_VER" ]; then
  DISC=$(ucp_curl -sL "$BASE_URL/.well-known/ucp/$UCP_VER/")
else
  DISC=$(ucp_curl -sL "$BASE_URL/.well-known/ucp")
fi
FOUND_VER=$(echo "$DISC" | json_get "print(d.get('ucp',{}).get('version',''))")
assert_not_empty "discovery returns ucp.version" "$FOUND_VER"
if [ -n "$UCP_VER" ]; then assert_status "ucp.version matches UCP_VER" "$UCP_VER" "$FOUND_VER"; fi
EXPECT_VER="${UCP_VER:-$FOUND_VER}"

CAPS=$(echo "$DISC" | json_get "print(len(d.get('ucp',{}).get('capabilities',{})))")
CAP_LIST=$(echo "$DISC" | json_get "print(' '.join(d.get('ucp',{}).get('capabilities',{})))")
case "$EXPECT_VER" in
  2026-08-25) EXPECTED_CAPS=6 ;;
  2026-04-08) EXPECTED_CAPS=9 ;;
  2026-01-23) EXPECTED_CAPS=3 ;;
  *) EXPECTED_CAPS="" ;;
esac
if [ -z "$EXPECTED_CAPS" ]; then
  skip "capability count (no expectation for version '$EXPECT_VER')"
else
  assert_status "discovery lists $EXPECTED_CAPS capabilities for $EXPECT_VER" "$EXPECTED_CAPS" "${CAPS:-0}"
fi
has_cap() { case " $CAP_LIST " in *" $1 "*) return 0 ;; *) return 1 ;; esac; }
echo ""

# ── 2. Catalog ────────────────────────────────────────────────

echo "2. Catalog"
if has_cap dev.ucp.shopping.catalog.search; then
  SEARCH=$(ucp_curl -s -X POST "$UCP_API/catalog/search" \
    -H "Content-Type: application/json" \
    -d '{"query": "", "limit": 5}')
  ITEM_COUNT=$(echo "$SEARCH" | json_get "print(len(d.get('products',[])))")
  if [ "${ITEM_COUNT:-0}" -gt 0 ]; then pass "catalog search returns products ($ITEM_COUNT)"; else fail "catalog search" "no products returned"; fi
  if [ -z "$PRODUCT_ID" ]; then
    PRODUCT_ID=$(echo "$SEARCH" | json_get "print(d['products'][0]['id'])")
  fi
else
  skip "catalog search (capability not advertised)"
fi
if [ -z "$PRODUCT_ID" ]; then
  PRODUCT_ID=$(ucp_curl -s "$BASE_URL/wp-json/wc/store/v1/products?per_page=1" | json_get "print(d[0]['id'])")
fi
if [ -z "$PRODUCT_ID" ]; then echo "  no product id available"; exit 1; fi
echo "  using product $PRODUCT_ID"

if has_cap dev.ucp.shopping.catalog.lookup; then
  LOOKUP=$(ucp_curl -s -X POST "$UCP_API/catalog/lookup" \
    -H "Content-Type: application/json" \
    -d "{\"ids\": [\"$PRODUCT_ID\"]}")
  LOOKUP_ID=$(echo "$LOOKUP" | python3 -c "import json,sys; items=json.load(sys.stdin).get('products',[]); print(items[0]['id'] if items else '')" 2>/dev/null || true)
  assert_not_empty "catalog lookup by ID" "$LOOKUP_ID"
else
  skip "catalog lookup (capability not advertised)"
fi
echo ""

# ── 3. Cart ───────────────────────────────────────────────────

echo "3. Cart"
if has_cap dev.ucp.shopping.cart; then
  CART_RESP=$(ucp_curl -s -D "$HDRS" -X POST "$UCP_API/carts" \
    -H "Content-Type: application/json" \
    -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": 1}]}")
  SESSION_TOKEN=$(issued_token)
  assert_not_empty "cart issues a session token" "$SESSION_TOKEN"
  CART_ID=$(echo "$CART_RESP" | python3 -c "import json,sys; print(json.load(sys.stdin).get('id',''))" 2>/dev/null || true)
  assert_not_empty "create cart" "$CART_ID"

  GET_CART=$(ucp_curl -s "$UCP_API/carts/$CART_ID")
  GOT_CART_ID=$(echo "$GET_CART" | python3 -c "import json,sys; print(json.load(sys.stdin).get('id',''))" 2>/dev/null || true)
  assert_status "get cart" "$CART_ID" "$GOT_CART_ID"

  UPD_CART=$(ucp_curl -s -X PUT "$UCP_API/carts/$CART_ID" \
    -H "Content-Type: application/json" \
    -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": 2}]}")
  UPD_QTY=$(echo "$UPD_CART" | python3 -c "import json,sys; print(json.load(sys.stdin)['line_items'][0]['quantity'])" 2>/dev/null || echo "0")
  assert_status "update cart quantity" "2" "$UPD_QTY"

  # Cart → checkout
  CART_CO=$(ucp_curl -s -X POST "$UCP_API/carts/$CART_ID/checkout" \
    -H "Content-Type: application/json")
  CART_SESSION=$(echo "$CART_CO" | python3 -c "import json,sys; print(json.load(sys.stdin).get('checkout_session_id',''))" 2>/dev/null || true)
  assert_not_empty "cart → checkout session" "$CART_SESSION"

  # Cart should be deleted after checkout
  DEL_STATUS=$(ucp_curl -s -o /dev/null -w "%{http_code}" "$UCP_API/carts/$CART_ID")
  assert_status "cart deleted after checkout" "404" "$DEL_STATUS"
else
  skip "cart (capability not advertised)"
fi
echo ""

# ── 4. Checkout Session (fresh) ───────────────────────────────

echo "4. Checkout Session"
CREATE=$(ucp_curl -s -D "$HDRS" -X POST "$UCP_API/checkout-sessions" \
  -H "Content-Type: application/json" \
  -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": 1}]}")
SESSION_TOKEN=$(issued_token)
assert_not_empty "checkout session issues a session token" "$SESSION_TOKEN"
SESSION_ID=$(echo "$CREATE" | python3 -c "import json,sys; print(json.load(sys.stdin).get('id',''))" 2>/dev/null || true)
assert_not_empty "create checkout session" "$SESSION_ID"

GET_SESS=$(ucp_curl -s "$UCP_API/checkout-sessions/$SESSION_ID")
SESS_STATUS=$(echo "$GET_SESS" | python3 -c "import json,sys; print(json.load(sys.stdin).get('status',''))" 2>/dev/null || true)
assert_status "session status is incomplete" "incomplete" "$SESS_STATUS"

FOREIGN_GET=$(SESSION_TOKEN="" ucp_curl -s -o /dev/null -w "%{http_code}" "$UCP_API/checkout-sessions/$SESSION_ID")
assert_status "session without its token is refused" "403" "$FOREIGN_GET"
echo ""

# ── 5. Buyer Identity ────────────────────────────────────────

echo "5. Buyer Identity"
if has_cap dev.ucp.shopping.buyer_identity; then
  BUYER_UPD=$(ucp_curl -s -X PUT "$UCP_API/checkout-sessions/$SESSION_ID/buyer" \
    -H "Content-Type: application/json" \
    -d '{"email": "test@example.com", "first_name": "Test", "last_name": "Agent", "phone": "+1234567890"}')
  BUYER_EMAIL=$(echo "$BUYER_UPD" | python3 -c "import json,sys; print(json.load(sys.stdin).get('buyer',{}).get('email',''))" 2>/dev/null || true)
  assert_status "update buyer email" "test@example.com" "$BUYER_EMAIL"

  BUYER_GET=$(ucp_curl -s "$UCP_API/checkout-sessions/$SESSION_ID/buyer")
  BUYER_FIRST=$(echo "$BUYER_GET" | python3 -c "import json,sys; print(json.load(sys.stdin).get('buyer',{}).get('first_name',''))" 2>/dev/null || true)
  assert_status "get buyer first_name" "Test" "$BUYER_FIRST"
else
  skip "buyer identity (capability not advertised)"
fi
echo ""

# ── 6. Cancel Session (for cleanup) ──────────────────────────

echo "6. Cancel Session"
CANCEL=$(ucp_curl -s -X POST "$UCP_API/checkout-sessions/$SESSION_ID/cancel" \
  -H "Content-Type: application/json")
CANCEL_STATUS=$(echo "$CANCEL" | python3 -c "import json,sys; print(json.load(sys.stdin).get('status',''))" 2>/dev/null || true)
assert_status "cancel session" "canceled" "$CANCEL_STATUS"
echo ""

# ── 7. Orders List ────────────────────────────────────────────

echo "7. Orders"
ORDERS=$(ucp_curl -s "$UCP_API/orders")
ORDER_STATUS=$(echo "$ORDERS" | python3 -c "import json,sys; print(json.load(sys.stdin).get('ucp',{}).get('status',''))" 2>/dev/null || true)
assert_status "list orders endpoint returns success" "success" "$ORDER_STATUS"
echo ""

# ── 8. Cart Delete ────────────────────────────────────────────

echo "8. Cart Delete"
if has_cap dev.ucp.shopping.cart; then
  CART2_RESP=$(ucp_curl -s -D "$HDRS" -X POST "$UCP_API/carts" \
    -H "Content-Type: application/json" \
    -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": 1}]}")
  SESSION_TOKEN=$(issued_token)
  CART2_ID=$(echo "$CART2_RESP" | python3 -c "import json,sys; print(json.load(sys.stdin).get('id',''))" 2>/dev/null || true)
  assert_not_empty "create cart for delete test" "$CART2_ID"

  DEL2_STATUS=$(ucp_curl -s -o /dev/null -w "%{http_code}" -X DELETE "$UCP_API/carts/$CART2_ID")
  assert_status "delete cart returns 204" "204" "$DEL2_STATUS"

  DEL2_GET=$(ucp_curl -s -o /dev/null -w "%{http_code}" "$UCP_API/carts/$CART2_ID")
  assert_status "deleted cart returns 404" "404" "$DEL2_GET"
else
  skip "cart delete (capability not advertised)"
fi
echo ""

# ── Summary ───────────────────────────────────────────────────

echo "========================================"
echo "  Results: $PASS passed, $FAIL failed, $SKIP skipped"
echo "========================================"

if [ "$FAIL" -gt 0 ]; then exit 1; fi
