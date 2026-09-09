# Installation

### Prerequisites

```bash
# Required
PHP 8.2+
Composer
PostgreSQL 14+
Redis (optional, for production)
```

## Step 1: Clone Repository
```bash
git clone https://github.com/yourusername/digital-store-v2.git
cd digital-store
```

## Step 2: Install Dependencies
```bash
composer install
```

## Step 3: Environment Configuration
```bash
cp .env.example .env
php artisan key:generate
```

## Step 4: Database Setup
```bash
# Run migrations and seeders
php artisan migrate:fresh --seed

# Verify installation
php artisan db:show
```

## Step 5: Start Services

```bash
# Terminal 1: Web Server
php artisan serve --port=8000
```


# Task 1: Multi-Item Order with Partial Delivery

## Run Automated Tests

```bash
# Run all multi-order tests
php artisan test --filter=MultiOrderTest

# Run specific test
php artisan test --filter=test_creates_multi_item_order
php artisan test --filter=test_handles_partial_delivery_and_refund
php artisan test --filter=test_maintains_financial_balance
php artisan test --filter=test_retries_failed_items
```

## Manual Test Scenarios
### Scenario 1: Complete Delivery (All Items Delivered)

```bash
# 1. Create order
curl -X POST http://127.0.0.1:8000/api/orders/multi \
  -H "Content-Type: application/json" \
  -d '{"items":[{"sku":"STEAM-TOPUP-500","quantity":1},{"sku":"KEY-CS2-PRIME","quantity":1}]}'

# 2. Process payment
curl -X POST http://127.0.0.1:8000/api/webhook/payment \
  -H "Content-Type: application/json" \
  -d '{"event_id":"evt_test","order_id":"ORD-xxx","status":"paid","amount":1790,"currency":"RUB","created_at":"2025-01-01T12:00:00Z"}'

# 3. Check order - should be "delivered"
curl http://127.0.0.1:8000/api/orders/multi/ORD-xxx
```

### Scenario 2: Partial Delivery (Some Items Fail)

```bash
# 1. Create order
curl -X POST http://127.0.0.1:8000/api/orders/multi \
  -H "Content-Type: application/json" \
  -d '{"items":[{"sku":"STEAM-TOPUP-500","quantity":1},{"sku":"KEY-CS2-PRIME","quantity":1},{"sku":"KEY-GTA5","quantity":1}]}'

# 2. Process payment
curl -X POST http://127.0.0.1:8000/api/webhook/payment \
  -H "Content-Type: application/json" \
  -d '{"event_id":"evt_test","order_id":"ORD-xxx","status":"paid","amount":3780,"currency":"RUB","created_at":"2025-01-01T12:00:00Z"}'

# 3. Check order - should be "partially_delivered"
curl http://127.0.0.1:8000/api/orders/multi/ORD-xxx
```

### Scenario 3: Duplicate Payment Webhook

```bash
# Send duplicate webhook with same event_id
curl -X POST http://127.0.0.1:8000/api/webhook/payment \
  -H "Content-Type: application/json" \
  -d '{"event_id":"evt_test","order_id":"ORD-xxx","status":"paid","amount":3780,"currency":"RUB","created_at":"2025-01-01T12:00:00Z"}'

# Should return "already_processed"
```

### Scenario 4: Payment Failure

```bash
# Send failed payment
curl -X POST http://127.0.0.1:8000/api/webhook/payment \
  -H "Content-Type: application/json" \
  -d '{"event_id":"evt_fail","order_id":"ORD-xxx","status":"failed","amount":3780,"currency":"RUB","created_at":"2025-01-01T12:00:00Z"}'

# Order status becomes "payment_failed"
curl http://127.0.0.1:8000/api/orders/multi/ORD-xxx
```

```bash
chmod +x test_multi_order.sh
./test_multi_order.sh
```

# Task 2: Untrustworthy Supplier

## Run Automated Tests

```bash
# Run all supplier untrustworthy tests
php artisan test --filter=SupplierUntrustworthyTest

# Run specific test
php artisan test --filter=test_supplier_returns_error_but_issued_key
php artisan test --filter=test_supplier_returns_duplicate_key
php artisan test --filter=test_same_key_not_assigned_to_two_orders
php artisan test --filter=test_retry_after_error_does_not_cause_double_issuance
```

## Manual Testing with cURL

### Test Scenario 1: Supplier Error But Issued Key

```bash
# 1. Create order
ORDER_RESPONSE=$(curl -s -X POST http://localhost:8000/api/orders/multi \
  -H "Content-Type: application/json" \
  -d '{"items":[{"sku":"STEAM-TOPUP-500","quantity":1}]}')
ORDER_ID=$(echo $ORDER_RESPONSE | jq -r '.order_id')
echo "Order created: $ORDER_ID"

# 2. Process payment
curl -s -X POST http://localhost:8000/api/webhook/payment \
  -H "Content-Type: application/json" \
  -d "{
    \"event_id\": \"evt_test\",
    \"order_id\": \"$ORDER_ID\",
    \"status\": \"paid\",
    \"amount\": 500,
    \"currency\": \"RUB\",
    \"created_at\": \"$(date -Iseconds)\"
  }" | jq '.'

sleep 2

# 3. Check order - should be delivered with a key
curl -s http://localhost:8000/api/orders/multi/$ORDER_ID | jq '.items[] | {sku, status, key_code, key_verified}'
```

### Test Scenario 2: Duplicate Key Detection

```bash
# Create multiple orders
for i in {1..3}; do
  RESPONSE=$(curl -s -X POST http://localhost:8000/api/orders/multi \
    -H "Content-Type: application/json" \
    -d '{"items":[{"sku":"STEAM-TOPUP-500","quantity":1}]}')
  ORDER_ID=$(echo $RESPONSE | jq -r '.order_id')
  echo "Order $i: $ORDER_ID"
  
  # Process payment
  curl -s -X POST http://localhost:8000/api/webhook/payment \
    -H "Content-Type: application/json" \
    -d "{
      \"event_id\": \"evt_${i}\",
      \"order_id\": \"$ORDER_ID\",
      \"status\": \"paid\",
      \"amount\": 500,
      \"currency\": \"RUB\",
      \"created_at\": \"$(date -Iseconds)\"
    }" > /dev/null
done

sleep 3

# Check keys uniqueness
echo -e "\nChecking keys..."
curl -s http://localhost:8000/api/orders/multi/ORD-xxx | jq '.items[].key_code'
```

### Test Scenario 3: Retry After Error

```bash
# 1. Create order
ORDER_RESPONSE=$(curl -s -X POST http://localhost:8000/api/orders/multi \
  -H "Content-Type: application/json" \
  -d '{"items":[{"sku":"STEAM-TOPUP-500","quantity":1}]}')
ORDER_ID=$(echo $ORDER_RESPONSE | jq -r '.order_id')
echo "Order created: $ORDER_ID"

# 2. Process payment
curl -s -X POST http://localhost:8000/api/webhook/payment \
  -H "Content-Type: application/json" \
  -d "{
    \"event_id\": \"evt_test\",
    \"order_id\": \"$ORDER_ID\",
    \"status\": \"paid\",
    \"amount\": 500,
    \"currency\": \"RUB\",
    \"created_at\": \"$(date -Iseconds)\"
  }" > /dev/null

sleep 2

# 3. Check order
echo "Order status:"
curl -s http://localhost:8000/api/orders/multi/$ORDER_ID | jq '.order.status, .items[] | {sku, status, key_code}'

# 4. Retry failed items (if any)
echo -e "\nRetrying failed items..."
curl -s -X POST http://localhost:8000/api/orders/multi/retry/$ORDER_ID | jq '.'

sleep 2

# 5. Check after retry
echo -e "\nAfter retry:"
curl -s http://localhost:8000/api/orders/multi/$ORDER_ID | jq '.order.status, .items[] | {sku, status, key_code}'
```

## Quick Test Script

```bash
chmod +x test_supplier_untrustworthy.sh
./test_supplier_untrustworthy.sh
```

# Task 3: Order Spike and Supplier Rate Limit - Complete Guide

## Automated Tests

### Run all tests

```bash
php artisan test --filter=QueueTest
```

### Run Individual Tests

```bash
# Test queueing when rate limit reached
php artisan test --filter=test_queue_order_when_rate_limit_reached

# Test paid orders have higher priority
php artisan test --filter=test_paid_orders_have_higher_priority

# Test queue status API
php artisan test --filter=test_queue_status_returns_correct_counts

# Test rate limit reset
php artisan test --filter=test_rate_limit_resets_after_minute

# Test batch processing
php artisan test --filter=test_process_next_batch
```

# Task 4: Point-in-Time Recovery

## Automated Tests

### Run All Tests

```bash
  php artisan test --filter=RecoveryTest
```

### Run Individual Tests

```bash
# Test event recording
php artisan test --filter=test_events_are_recorded_on_order_creation

# Test order reconstruction
php artisan test --filter=test_reconstruct_order_state

# Test balance at time
php artisan test --filter=test_get_balance_at_time

# Test API reconstruction
php artisan test --filter=test_api_reconstruct_order

# Test period summary
php artisan test --filter=test_period_summary
```

## Manual Testing Script

```bash
chmod +x test_recovery.sh
./test_recovery.sh
```
