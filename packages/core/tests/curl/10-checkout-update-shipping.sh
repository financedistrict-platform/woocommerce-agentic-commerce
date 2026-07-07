#!/usr/bin/env bash
# Update checkout session with shipping address to get fulfillment options.
# Usage: SESSION_ID=<id> ./10-checkout-update-shipping.sh
#
# After running, the response will include fulfillment.methods[0].groups[0].options[]
# with available shipping rates. Note the selected_option_id — you can change it
# by sending another update with the desired option.
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

SESSION_ID="${SESSION_ID:?Set SESSION_ID}"

curl -s -X PUT "$UCP_API/checkout-sessions/$SESSION_ID" \
  -H "Content-Type: application/json" \
  -d '{
    "buyer": {
      "email": "agent@example.com",
      "first_name": "Test",
      "last_name": "Agent"
    },
    "fulfillment": {
      "methods": [{
        "type": "shipping",
        "destinations": [{
          "street_address": "1 Market St",
          "address_locality": "San Francisco",
          "address_region": "CA",
          "postal_code": "94105",
          "address_country": "US"
        }]
      }]
    }
  }' | python3 -m json.tool
