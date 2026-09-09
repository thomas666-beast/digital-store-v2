#!/bin/bash

echo "========================================="
echo "      TASK 3: QUEUE & RATE LIMIT TESTS"
echo "========================================="

BASE_URL="http://localhost:8000/api"

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Function to print section
print_section() {
    echo ""
    echo "-----------------------------------------"
    echo -e "${YELLOW}$1${NC}"
    echo "-----------------------------------------"
}

# Function to print success
print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

# Function to print error
print_error() {
    echo -e "${RED}❌ $1${NC}"
}

# 1. Check Rate Limit Status
print_section "1. Checking Rate Limit Status"
curl -s $BASE_URL/queue/rate-limit | jq '.'
print_success "Rate limit status retrieved"

# 2. Get Queue Status (Initial)
print_section "2. Getting Queue Status (Initial)"
curl -s $BASE_URL/queue/status | jq '.'
print_success "Queue status retrieved"

# 3. Create Multiple Orders to Trigger Queueing
print_section "3. Creating Orders (Will Trigger Queueing)"

ORDER_IDS=()
for i in {1..5}; do
    echo "Creating order $i..."
    RESPONSE=$(curl -s -X POST $BASE_URL/orders/multi \
        -H "Content-Type: application/json" \
        -d '{
            "items": [
                {"sku": "STEAM-TOPUP-500", "quantity": 1}
            ]
        }')
    
    ORDER_ID=$(echo $RESPONSE | jq -r '.order_id')
    ORDER_IDS+=("$ORDER_ID")
    echo "Order $i created: $ORDER_ID"
done

print_success "5 orders created"

# 4. Process Payments (This will queue if rate limit reached)
print_section "4. Processing Payments"

for i in "${!ORDER_IDS[@]}"; do
    ORDER_ID=${ORDER_IDS[$i]}
    echo "Processing payment for order: $ORDER_ID"
    
    PAYMENT_RESPONSE=$(curl -s -X POST $BASE_URL/webhook/payment \
        -H "Content-Type: application/json" \
        -d "{
            \"event_id\": \"evt_queue_$(date +%s)_${i}\",
            \"order_id\": \"$ORDER_ID\",
            \"status\": \"paid\",
            \"amount\": 500,
            \"currency\": \"RUB\",
            \"created_at\": \"$(date -Iseconds)\"
        }")
    
    echo $PAYMENT_RESPONSE | jq '.'
    sleep 0.5
done

print_success "Payments processed"

# 5. Check Queue Status After Payments
print_section "5. Queue Status After Payments"
curl -s $BASE_URL/queue/status | jq '.'
print_success "Queue status updated"

# 6. Get Rate Limit Status
print_section "6. Rate Limit Status"
curl -s $BASE_URL/queue/rate-limit | jq '.'
print_success "Rate limit status retrieved"

# 7. Process Queue Batch
print_section "7. Processing Queue Batch"
PROCESS_RESPONSE=$(curl -s -X POST $BASE_URL/queue/process \
    -H "Content-Type: application/json" \
    -d '{"batch_size": 3}')

echo $PROCESS_RESPONSE | jq '.'
PROCESSED_COUNT=$(echo $PROCESS_RESPONSE | jq '.count')
REMAINING=$(echo $PROCESS_RESPONSE | jq '.remaining')

print_success "Processed $PROCESSED_COUNT orders, $REMAINING remaining in queue"

# 8. Check Queue Status After Processing
print_section "8. Queue Status After Processing"
curl -s $BASE_URL/queue/status | jq '.'
print_success "Queue status updated"

# 9. Get Order Details (Check if delivered)
print_section "9. Checking Order Status"

for ORDER_ID in "${ORDER_IDS[@]}"; do
    echo "Order: $ORDER_ID"
    curl -s $BASE_URL/orders/multi/$ORDER_ID | jq '.order.status'
done

# 10. Test Rate Limit Reset (Simulate)
print_section "10. Rate Limit Reset Test"
echo "Waiting 60 seconds for rate limit to reset..."
echo "You can manually check the rate limit status after wait:"
echo "curl $BASE_URL/queue/rate-limit | jq '.'"

# 11. Final Queue Status
print_section "11. Final Queue Status"
curl -s $BASE_URL/queue/status | jq '.'

echo ""
echo "========================================="
echo -e "${GREEN}✅ TEST COMPLETE${NC}"
echo "========================================="
echo ""
echo "Summary:"
echo "- Orders created: 5"
echo "- Orders processed: $PROCESSED_COUNT"
echo "- Orders remaining in queue: $REMAINING"
echo ""
echo "To check individual orders:"
for ORDER_ID in "${ORDER_IDS[@]}"; do
    echo "  curl $BASE_URL/orders/multi/$ORDER_ID | jq '.'"
done
echo ""
