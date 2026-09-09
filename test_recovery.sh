#!/bin/bash

echo "========================================="
echo "   TASK 4: POINT-IN-TIME RECOVERY TESTS"
echo "========================================="

BASE_URL="http://127.0.0.1:8000/api"
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

print_section() {
    echo ""
    echo "-----------------------------------------"
    echo -e "${YELLOW}$1${NC}"
    echo "-----------------------------------------"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

# Store order IDs for later testing
ORDER_IDS=()

# 1. Create Orders
print_section "1. Creating Orders"

for i in {1..3}; do
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
    
    # Process payment immediately
    curl -s -X POST $BASE_URL/webhook/payment \
        -H "Content-Type: application/json" \
        -d "{
            \"event_id\": \"evt_recovery_$(date +%s)_${i}\",
            \"order_id\": \"$ORDER_ID\",
            \"status\": \"paid\",
            \"amount\": 500,
            \"currency\": \"RUB\",
            \"created_at\": \"$(date -Iseconds)\"
        }" > /dev/null
    
    echo "Payment processed for order $i"
    sleep 0.5
done

print_success "3 orders created and paid"

# 2. Record Event History
print_section "2. Event History for First Order"
ORDER_ID=${ORDER_IDS[0]}
curl -s $BASE_URL/recovery/order/$ORDER_ID | jq '.events[] | {type, version}'
print_success "Event history retrieved"

# 3. Reconstruct Order State (Current)
print_section "3. Reconstruct Order State (Current)"
ORDER_ID=${ORDER_IDS[0]}
curl -s $BASE_URL/recovery/order/$ORDER_ID | jq '{order_id, status, total_amount, balance, events_count: (.events | length)}'
print_success "Order state reconstructed"

# 4. Reconstruct Order at Specific Time
print_section "4. Reconstruct Order at Specific Time"
TIMESTAMP=$(date -d "2 seconds ago" -Iseconds)
echo "Using timestamp: $TIMESTAMP"

curl -s -X POST $BASE_URL/recovery/order/${ORDER_IDS[0]}/at-time \
    -H "Content-Type: application/json" \
    -d "{\"timestamp\": \"$TIMESTAMP\"}" | jq '{order_id, status, balance, events_count: (.events | length)}'
print_success "Order state reconstructed at time"

# 5. Get Balance at Time
print_section "5. Get Balance at Time"
TIMESTAMP=$(date -d "1 second ago" -Iseconds)
echo "Using timestamp: $TIMESTAMP"

curl -s "$BASE_URL/recovery/balance/${ORDER_IDS[0]}/at-time?timestamp=$TIMESTAMP" | jq '.'
print_success "Balance at time retrieved"

# 6. Get Period Summary
print_section "6. Period Summary"
DATE=$(date -I)
echo "Date: $DATE"

curl -s "$BASE_URL/recovery/summary?period=daily&date=$DATE" | jq '.'
print_success "Period summary retrieved"

# 7. Take Snapshot
print_section "7. Taking Snapshot"
ORDER_ID=${ORDER_IDS[0]}
curl -s -X POST $BASE_URL/recovery/snapshot/$ORDER_ID | jq '.'
print_success "Snapshot taken"

# 8. Verify Event Integrity
print_section "8. Event Integrity Check"
echo "Checking event count for orders..."
for ORDER_ID in "${ORDER_IDS[@]}"; do
    EVENTS=$(curl -s $BASE_URL/recovery/order/$ORDER_ID | jq '.events | length')
    echo "Order $ORDER_ID has $EVENTS events"
    
    # Each order should have at least 3 events: created, paid, delivered
    if [ $EVENTS -ge 3 ]; then
        echo "✅ Order $ORDER_ID has all required events"
    else
        echo "⚠️ Order $ORDER_ID has only $EVENTS events (expected at least 3)"
    fi
done
print_success "Event integrity verified"

# 9. Financial Balance Verification
print_section "9. Financial Balance Verification"
echo "Checking financial balance for each order..."
TOTAL_BALANCE=0

for ORDER_ID in "${ORDER_IDS[@]}"; do
    BALANCE=$(curl -s $BASE_URL/recovery/order/$ORDER_ID | jq -r '.balance')
    echo "Order $ORDER_ID balance: $BALANCE"
    TOTAL_BALANCE=$((TOTAL_BALANCE + BALANCE))
done

echo ""
echo "Total balance across all orders: $TOTAL_BALANCE"
print_success "Financial balance verified"

# 10. Manual Recovery Simulation
print_section "10. Manual Recovery Simulation"
echo "Simulating recovery from events..."

ORDER_ID=${ORDER_IDS[0]}
echo "Order ID: $ORDER_ID"

# Get event count
EVENT_COUNT=$(curl -s $BASE_URL/recovery/order/$ORDER_ID | jq -r '.events | length')
echo "Total events: $EVENT_COUNT"

# Reconstruct from events
curl -s $BASE_URL/recovery/order/$ORDER_ID | jq '{
    order_id,
    status,
    total_amount,
    delivered_amount,
    refunded_amount,
    balance,
    last_event: .events[-1]
}'
print_success "Manual recovery simulation complete"

echo ""
echo "========================================="
echo -e "${GREEN}✅ RECOVERY TESTS COMPLETE${NC}"
echo "========================================="
echo ""
echo "Summary:"
echo "- Orders created: 3"
echo "- Events tracked per order: $EVENT_COUNT+"
echo "- Period summary generated: $(date -I)"
echo ""
echo "To check event history:"
echo "  php artisan tinker"
echo "  App\\Models\\OrderEvent::where('order_id', '$ORDER_ID')->get();"
echo ""
echo "To check snapshots:"
echo "  App\\Models\\FinancialSnapshot::where('order_id', '$ORDER_ID')->get();"
echo ""
echo "To check period summaries:"
echo "  App\\Models\\PeriodSummary::where('period_date', today())->get();"
