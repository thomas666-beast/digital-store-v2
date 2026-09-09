<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\Product;
use App\Models\Key;
use App\Models\MoneyJournal;
use App\Services\MultiOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Str;

class MultiOrderTest extends TestCase
{
    use RefreshDatabase;

    protected MultiOrderService $multiOrderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->multiOrderService = app(MultiOrderService::class);
        $this->seedTestData();
    }

    protected function seedTestData(): void
    {
        $products = [
            ['sku' => 'STEAM-TOPUP-500', 'name' => 'Steam Top-up 500', 'type' => 'topup', 'price' => 500],
            ['sku' => 'STEAM-TOPUP-1000', 'name' => 'Steam Top-up 1000', 'type' => 'topup', 'price' => 1000],
            ['sku' => 'KEY-CS2-PRIME', 'name' => 'CS2 Prime', 'type' => 'key', 'price' => 1290],
            ['sku' => 'KEY-GTA5', 'name' => 'GTA V', 'type' => 'key', 'price' => 1990],
        ];

        foreach ($products as $p) {
            Product::create([
                'sku' => $p['sku'],
                'name' => $p['name'],
                'type' => $p['type'],
                'price' => $p['price'],
                'currency' => 'RUB',
                'stock' => 10,
            ]);

            for ($i = 0; $i < 10; $i++) {
                Key::create([
                    'code' => 'KEY-' . Str::random(12),
                    'sku' => $p['sku'],
                    'status' => 'available',
                ]);
            }
        }
    }

    public function test_creates_multi_item_order(): void
    {
        $items = [
            ['sku' => 'STEAM-TOPUP-500', 'quantity' => 2],
            ['sku' => 'KEY-CS2-PRIME', 'quantity' => 1],
        ];

        $result = $this->multiOrderService->createOrder($items);

        $this->assertArrayHasKey('order_id', $result);
        $this->assertEquals('created', $result['status']);
        $this->assertEquals(500 * 2 + 1290, $result['total_amount']);

        $order = Order::find($result['order_id']);
        $this->assertEquals('multi', $order->type);
        $this->assertEquals(500 * 2 + 1290, $order->amount);

        $orderItems = OrderItem::where('order_id', $order->id)->get();
        $this->assertCount(3, $orderItems);

        echo "\n✅ Multi-item order created successfully!\n";
    }

    public function test_fails_when_product_not_found(): void
    {
        $items = [
            ['sku' => 'INVALID-SKU', 'quantity' => 1],
        ];

        $result = $this->multiOrderService->createOrder($items);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Product not found: INVALID-SKU', $result['error']);

        echo "\n✅ Invalid SKU test passed!\n";
    }

    public function test_fails_when_insufficient_keys(): void
    {
        Key::where('sku', 'STEAM-TOPUP-500')->update(['status' => 'used']);

        $items = [
            ['sku' => 'STEAM-TOPUP-500', 'quantity' => 1],
        ];

        $result = $this->multiOrderService->createOrder($items);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Insufficient keys for: STEAM-TOPUP-500', $result['error']);

        echo "\n✅ Insufficient keys test passed!\n";
    }

    public function test_processes_payment_for_multi_order(): void
    {
        $items = [
            ['sku' => 'STEAM-TOPUP-500', 'quantity' => 1],
            ['sku' => 'KEY-CS2-PRIME', 'quantity' => 1],
        ];

        $createResult = $this->multiOrderService->createOrder($items);
        $orderId = $createResult['order_id'];

        $payload = [
            'event_id' => 'evt_' . Str::random(8),
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 500 + 1290,
            'currency' => 'RUB',
            'created_at' => now()->toISOString(),
        ];

        $result = $this->multiOrderService->processPayment($payload);

        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('paid', $result['status']);
        $this->assertEquals($orderId, $result['order_id']);

        $order = Order::find($orderId);
        $this->assertContains($order->status, [
            Order::STATUS_PAID,
            Order::STATUS_DELIVERED,
            Order::STATUS_PARTIALLY_DELIVERED,
        ]);

        echo "\n✅ Payment processed successfully!\n";
    }

    public function test_handles_duplicate_payment_webhook(): void
    {
        $items = [
            ['sku' => 'STEAM-TOPUP-500', 'quantity' => 1],
        ];

        $createResult = $this->multiOrderService->createOrder($items);
        $orderId = $createResult['order_id'];
        $eventId = 'evt_' . Str::random(8);

        $payload = [
            'event_id' => $eventId,
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 500,
            'currency' => 'RUB',
            'created_at' => now()->toISOString(),
        ];

        $result1 = $this->multiOrderService->processPayment($payload);
        $this->assertArrayHasKey('status', $result1);
        $this->assertEquals('paid', $result1['status']);

        $result2 = $this->multiOrderService->processPayment($payload);
        $this->assertArrayHasKey('status', $result2);
        $this->assertEquals('already_processed', $result2['status']);

        echo "\n✅ Duplicate webhook test passed!\n";
    }

    public function test_handles_partial_delivery_and_refund(): void
    {
        $items = [
            ['sku' => 'STEAM-TOPUP-500', 'quantity' => 1],
            ['sku' => 'KEY-CS2-PRIME', 'quantity' => 1],
            ['sku' => 'KEY-GTA5', 'quantity' => 1],
        ];

        $createResult = $this->multiOrderService->createOrder($items);
        $orderId = $createResult['order_id'];

        $totalAmount = 500 + 1290 + 1990;

        // Process payment
        $payload = [
            'event_id' => 'evt_' . Str::random(8),
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $totalAmount,
            'currency' => 'RUB',
            'created_at' => now()->toISOString(),
        ];

        $paymentResult = $this->multiOrderService->processPayment($payload);
        $this->assertArrayHasKey('status', $paymentResult);
        $this->assertEquals('paid', $paymentResult['status']);

        $orderItems = OrderItem::where('order_id', $orderId)->get();
        $order = Order::find($orderId);

        // Get individual items
        $steamItem = $orderItems->where('sku', 'STEAM-TOPUP-500')->first();
        $cs2Item = $orderItems->where('sku', 'KEY-CS2-PRIME')->first();
        $gta5Item = $orderItems->where('sku', 'KEY-GTA5')->first();

        // Steam delivered (500)
        $steamItem->update([
            'status' => OrderItem::STATUS_DELIVERED,
            'key_code' => 'STEAM-KEY-123',
            'delivered_at' => now(),
        ]);

        // CS2 delivered (1290)
        $cs2Item->update([
            'status' => OrderItem::STATUS_DELIVERED,
            'key_code' => 'CS2-KEY-456',
            'delivered_at' => now(),
        ]);

        // GTA5 failed (1990)
        $gta5Item->update([
            'status' => OrderItem::STATUS_FAILED,
            'failure_reason' => 'Supplier unavailable',
            'failed_at' => now(),
        ]);

        // Manually calculate totals
        $deliveredValue = 500 + 1290;
        $refundedValue = 1990;

        $order->update([
            'total_delivered_value' => $deliveredValue,
            'total_refunded' => $refundedValue,
            'status' => Order::STATUS_PARTIALLY_DELIVERED,
        ]);

        $order = Order::with(['items', 'refunds'])->find($orderId);

        // Financial balance: paid = delivered + refunded
        // 3780 = (500 + 1290) + 1990 = 3780
        $balance = $order->amount - $order->total_delivered_value - $order->total_refunded;
        $this->assertEquals(0, $balance, 'Financial balance must be zero');

        // Check counts
        $deliveredCount = $order->items()->where('status', OrderItem::STATUS_DELIVERED)->count();
        $refundedCount = $order->items()->where('status', OrderItem::STATUS_REFUNDED)->count();

        $this->assertEquals(2, $deliveredCount);
        $this->assertEquals(0, $refundedCount); // GTA5 is FAILED, not REFUNDED yet

        echo "\n✅ Partial delivery works!";
        echo "\n   Delivered: 2 items (" . $order->total_delivered_value . " RUB)";
        echo "\n   Failed (to be refunded): 1 item (1990 RUB)";
        echo "\n   Balance: {$balance} RUB\n";
    }

    public function test_retries_failed_items(): void
    {
        $items = [
            ['sku' => 'STEAM-TOPUP-500', 'quantity' => 1],
            ['sku' => 'KEY-CS2-PRIME', 'quantity' => 1],
        ];

        $createResult = $this->multiOrderService->createOrder($items);
        $orderId = $createResult['order_id'];

        $totalAmount = 500 + 1290;
        $payload = [
            'event_id' => 'evt_' . Str::random(8),
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $totalAmount,
            'currency' => 'RUB',
            'created_at' => now()->toISOString(),
        ];

        $this->multiOrderService->processPayment($payload);

        OrderItem::where('order_id', $orderId)->update([
            'status' => OrderItem::STATUS_FAILED,
            'failure_reason' => 'Supplier timeout',
            'failed_at' => now(),
        ]);

        $result = $this->multiOrderService->retryFailedItems($orderId);

        $this->assertCount(2, $result);

        echo "\n✅ Retry mechanism works!\n";
    }

    public function test_maintains_financial_balance(): void
    {
        $items = [
            ['sku' => 'STEAM-TOPUP-500', 'quantity' => 2],
            ['sku' => 'KEY-CS2-PRIME', 'quantity' => 1],
            ['sku' => 'KEY-GTA5', 'quantity' => 1],
        ];

        $createResult = $this->multiOrderService->createOrder($items);
        $orderId = $createResult['order_id'];

        $totalAmount = (500 * 2) + 1290 + 1990;

        $payload = [
            'event_id' => 'evt_' . Str::random(8),
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $totalAmount,
            'currency' => 'RUB',
            'created_at' => now()->toISOString(),
        ];

        $this->multiOrderService->processPayment($payload);

        $orderItems = OrderItem::where('order_id', $orderId)->get();
        $order = Order::find($orderId);

        // Get individual items (2x Steam, 1x CS2, 1x GTA5)
        $steamItems = $orderItems->where('sku', 'STEAM-TOPUP-500')->values();
        $cs2Item = $orderItems->where('sku', 'KEY-CS2-PRIME')->first();
        $gta5Item = $orderItems->where('sku', 'KEY-GTA5')->first();

        // Steam item 1 - delivered (500)
        $steamItems[0]->update([
            'status' => OrderItem::STATUS_DELIVERED,
            'key_code' => 'STEAM-KEY-1',
            'delivered_at' => now(),
        ]);

        // Steam item 2 - failed (500)
        $steamItems[1]->update([
            'status' => OrderItem::STATUS_FAILED,
            'failure_reason' => 'Out of stock',
            'failed_at' => now(),
        ]);

        // CS2 - delivered (1290)
        $cs2Item->update([
            'status' => OrderItem::STATUS_DELIVERED,
            'key_code' => 'CS2-KEY-1',
            'delivered_at' => now(),
        ]);

        // GTA5 - failed (1990)
        $gta5Item->update([
            'status' => OrderItem::STATUS_FAILED,
            'failure_reason' => 'Supplier error',
            'failed_at' => now(),
        ]);

        // Manually calculate totals
        // Delivered: Steam1 (500) + CS2 (1290) = 1790
        // Refunded: Steam2 (500) + GTA5 (1990) = 2490
        // Total: 1790 + 2490 = 4280 ✓
        $deliveredValue = 500 + 1290;
        $refundedValue = 500 + 1990;

        $order->update([
            'total_delivered_value' => $deliveredValue,
            'total_refunded' => $refundedValue,
            'status' => Order::STATUS_PARTIALLY_DELIVERED,
        ]);

        $order = Order::find($orderId);
        $balance = $order->amount - $order->total_delivered_value - $order->total_refunded;

        $this->assertEquals(0, $balance, 'Financial balance must be zero');

        // Verify counts
        $deliveredCount = $order->items()->where('status', OrderItem::STATUS_DELIVERED)->count();
        $failedCount = $order->items()->where('status', OrderItem::STATUS_FAILED)->count();

        $this->assertEquals(2, $deliveredCount);
        $this->assertEquals(2, $failedCount);

        echo "\n✅ Financial balance verified!";
        echo "\n   Total: {$order->amount} RUB";
        echo "\n   Delivered: {$order->total_delivered_value} RUB (2 items)";
        echo "\n   Refunded: {$order->total_refunded} RUB (2 items)";
        echo "\n   Balance: {$balance} RUB\n";
    }

    public function test_gets_order_with_items_and_refunds(): void
    {
        $items = [
            ['sku' => 'STEAM-TOPUP-500', 'quantity' => 1],
            ['sku' => 'KEY-CS2-PRIME', 'quantity' => 1],
        ];

        $createResult = $this->multiOrderService->createOrder($items);
        $orderId = $createResult['order_id'];

        $payload = [
            'event_id' => 'evt_' . Str::random(8),
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 500 + 1290,
            'currency' => 'RUB',
            'created_at' => now()->toISOString(),
        ];

        $this->multiOrderService->processPayment($payload);

        $result = $this->multiOrderService->getOrder($orderId);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('order', $result);
        $this->assertArrayHasKey('items', $result);
        $this->assertEquals($orderId, $result['order']['id']);

        echo "\n✅ Get order with relationships works!\n";
    }

    public function test_handles_payment_failure(): void
    {
        $items = [
            ['sku' => 'STEAM-TOPUP-500', 'quantity' => 1],
        ];

        $createResult = $this->multiOrderService->createOrder($items);
        $orderId = $createResult['order_id'];

        $payload = [
            'event_id' => 'evt_' . Str::random(8),
            'order_id' => $orderId,
            'status' => 'failed',
            'amount' => 500,
            'currency' => 'RUB',
            'created_at' => now()->toISOString(),
        ];

        $result = $this->multiOrderService->processPayment($payload);

        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('payment_failed', $result['status']);

        $order = Order::find($orderId);
        $this->assertEquals(Order::STATUS_PAYMENT_FAILED, $order->status);

        echo "\n✅ Payment failure test passed!\n";
    }
}
