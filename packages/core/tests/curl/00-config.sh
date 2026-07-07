#!/usr/bin/env bash
# Shared config for all curl tests.
# Source this file: . ./00-config.sh

BASE_URL="${BASE_URL:-http://localhost:8080}"
UCP_API="$BASE_URL/wp-json/fd-ucp/v1"
