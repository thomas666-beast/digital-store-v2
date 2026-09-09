#!/bin/bash

echo "=== Testing Untrustworthy Supplier ==="

# 1. Create order
echo -e "\n1. Creating order..."
ORDER_RESPONSE=$(curl -s -X POST http://127.0.0.1:8000/api/orders/multi \
  -H "Content-Type: application/json" \
  -d '{"items":[{"sku":"STEAM-TOPUP-500","quantity":1}]}')
ORDER_ID=$(echo $ORDER_RESPONSE | jq -r '.order_id')
echo "Order created: $ORDER_ID"

# 2. Process payment
echo -e "\n2. Processing payment..."
curl -s -X POST http://127.0.0.1:8000/api/webhook/payment \
  -H "Content-Type: application/json" \
  -d "{
    \"event_id\": \"evt_test_$(date +%s)\",
    \"order_id\": \"$ORDER_ID\",
    \"status\": \"paid\",
    \"amount\": 500,
    \"currency\": \"RUB\",
    \"created_at\": \"$(date -Iseconds)\"
  }" | jq '.'

sleep 2

# 3. Check order
echo -e "\n3. Checking order..."
curl -s http://127.0.0.1:8000/api/orders/multi/$ORDER_ID | jq '{
  order_id: .order.id,
  status: .order.status,
  items: .items | map({sku, status, key_code, key_verified})
}'

# 4. Check key uniqueness
echo -e "\n4. Checking key uniqueness in database..."
echo "SELECT code, order_id FROM keys WHERE status = 'used';" | psql -U postgres -d digital_store_v2_db

# 5. Check supplier logs
echo -e "\n5. Recent supplier logs..."
echo "SELECT request_id, order_id, status, key_code, attempt FROM supplier_logs ORDER BY created_at DESC LIMIT 5;" | psql -U postgres -d digital_store_v2_db

echo -e "\n✅ Test complete!"
