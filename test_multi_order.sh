#!/bin/bash

echo "=== Testing Multi-Item Orders ==="

# 1. Create order
echo -e "\n1. Creating order..."
ORDER_RESPONSE=$(curl -s -X POST http://127.0.0.1:8000/api/orders/multi \
  -H "Content-Type: application/json" \
  -d '{
    "items": [
      {"sku": "STEAM-TOPUP-500", "quantity": 2},
      {"sku": "KEY-CS2-PRIME", "quantity": 1},
      {"sku": "KEY-GTA5", "quantity": 1}
    ]
  }')

ORDER_ID=$(echo $ORDER_RESPONSE | jq -r '.order_id')
echo "Order created: $ORDER_ID"

# 2. Get order details
echo -e "\n2. Getting order details..."
curl -s http://127.0.0.1:8000/api/orders/multi/$ORDER_ID | jq '.'

# 3. Process payment
echo -e "\n3. Processing payment..."
curl -s -X POST http://127.0.0.1:8000/api/webhook/payment \
  -H "Content-Type: application/json" \
  -d "{
    \"event_id\": \"evt_multi_test\",
    \"order_id\": \"$ORDER_ID\",
    \"status\": \"paid\",
    \"amount\": 3780,
    \"currency\": \"RUB\",
    \"created_at\": \"$(date -Iseconds)\"
  }" | jq '.'

sleep 2

# 4. Check final order
echo -e "\n4. Final order status..."
curl -s http://127.0.0.1:8000/api/orders/multi/$ORDER_ID | jq '.order.status, .summary'

echo -e "\n✅ Test complete!"
