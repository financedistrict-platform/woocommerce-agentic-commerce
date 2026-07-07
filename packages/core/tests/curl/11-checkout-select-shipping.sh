#!/usr/bin/env bash
# Select a specific shipping option from the available fulfillment options.
# Usage: SESSION_ID=<id> SHIPPING_OPTION=<option_id> ./11-checkout-select-shipping.sh
#
# Get available options first with 10-checkout-update-shipping.sh, then pass
# the desired option ID here. The session totals will update to include shipping cost.
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

SESSION_ID="${SESSION_ID:?Set SESSION_ID}"
SHIPPING_OPTION="${SHIPPING_OPTION:?Set SHIPPING_OPTION (e.g. flat_rate1 or free_shipping)}"

curl -s -X PUT "$UCP_API/checkout-sessions/$SESSION_ID" \
  -H "Content-Type: application/json" \
  -d "{
    \"fulfillment\": {
      \"methods\": [{
        \"type\": \"shipping\",
        \"destinations\": [{
          \"street_address\": \"1 Market St\",
          \"address_locality\": \"San Francisco\",
          \"address_region\": \"CA\",
          \"postal_code\": \"94105\",
          \"address_country\": \"US\"
        }],
        \"groups\": [{
          \"selected_option_id\": \"$SHIPPING_OPTION\"
        }]
      }]
    }
  }" | python3 -m json.tool
