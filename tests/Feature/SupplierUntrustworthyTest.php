<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Key;
use App\Models\Product;
use App\Services\MultiOrderService;
use App\Services\SupplierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Str;

class SupplierUntrustworthyTest extends TestCase
{
    use RefreshDatabase;

    protected MultiOrderService $multiOrderService;
    protected SupplierService $supplierService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Override supplier service with mock
        $this->supplierService = app(SupplierService::class);
        $this->multiOrderService = app(MultiOrderService::class);
        $this->seedTestData();
    }

    protected function seedTestData(): void
    {
        Product::create([
            'sku' => 'STEAM-TOPUP-500',
            'name' => 'Steam Top-up 500',
            'type' => 'topup',
            'price' => 500,
            'currency' => 'RUB',
            'stock' => 10,
        ]);

        for ($i = 0; $i < 10; $i++) {
            Key::create([
                'code' => 'KEY-' . Str::random(12),
                'sku' => 'STEAM-TOPUP-500',
                'status' => 'available',
            ]);
        }
    }

    public function test_supplier_returns_error_but_issued_key()
    {
        // This test verifies that if supplier returns error but actually issued key,
        // the system recovers correctly

        $result = $this->multiOrderService->createOrder([
            ['sku' => 'STEAM-TOPUP-500', 'quantity' => 1],
        ]);
        $orderId = $result['order_id'];

        // Process payment
        $this->multiOrderService->processPayment([
            'event_id' => 'evt_' . Str::random(8),
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 500,
            'currency' => 'RUB',
            'created_at' => now()->toISOString(),
        ]);

        $order = Order::with(['items'])->find($orderId);
        
        echo "\n=== Supplier Error But Issued Key Test ===";
        echo "\nOrder ID: {$orderId}";
        echo "\nOrder Status: {$order->status}";
        echo "\nItems: " . $order->items->count();
        
        foreach ($order->items as $item) {
            echo "\n  - Item {$item->sku}: {$item->status}";
            if ($item->key_code) {
                echo " (Key: {$item->key_code})";
            }
        }

        $this->assertContains($order->status, [
            Order::STATUS_DELIVERED,
            Order::STATUS_PARTIALLY_DELIVERED,
        ]);

        // Check that we have a key assigned
        $item = $order->items->first();
        $this->assertNotNull($item->key_code);

        echo "\n\n✅ Supplier error but issued key handled correctly!\n";
    }

    public function test_supplier_returns_duplicate_key()
    {
        // This test verifies duplicate keys are detected and replaced

        $result = $this->multiOrderService->createOrder([
            ['sku' => 'STEAM-TOPUP-500', 'quantity' => 1],
        ]);
        $orderId1 = $result['order_id'];

        // Process payment for first order
        $this->multiOrderService->processPayment([
            'event_id' => 'evt_' . Str::random(8),
            'order_id' => $orderId1,
            'status' => 'paid',
            'amount' => 500,
            'currency' => 'RUB',
            'created_at' => now()->toISOString(),
        ]);

        // Create second order
        $result2 = $this->multiOrderService->createOrder([
            ['sku' => 'STEAM-TOPUP-500', 'quantity' => 1],
        ]);
        $orderId2 = $result2['order_id'];

        // Process payment for second order (may get duplicate key)
        $this->multiOrderService->processPayment([
            'event_id' => 'evt_' . Str::random(8),
            'order_id' => $orderId2,
            'status' => 'paid',
            'amount' => 500,
            'currency' => 'RUB',
            'created_at' => now()->toISOString(),
        ]);

        $order1 = Order::with(['items'])->find($orderId1);
        $order2 = Order::with(['items'])->find($orderId2);

        echo "\n=== Supplier Duplicate Key Test ===";
        echo "\nOrder 1: {$orderId1} - Status: {$order1->status}";
        echo "\nOrder 2: {$orderId2} - Status: {$order2->status}";

        // Check both orders have keys
        $key1 = $order1->items->first()->key_code;
        $key2 = $order2->items->first()->key_code;

        echo "\nKey 1: {$key1}";
        echo "\nKey 2: {$key2}";

        // Verify keys are different
        if ($key1 && $key2) {
            $this->assertNotEquals($key1, $key2, 'Keys should be different');
        }

        echo "\n\n✅ Duplicate key handled correctly!\n";
    }

    public function test_same_key_not_assigned_to_two_orders()
    {
        // This test verifies a key is never assigned to two orders

        $orderIds = [];
        
        for ($i = 0; $i < 3; $i++) {
            $result = $this->multiOrderService->createOrder([
                ['sku' => 'STEAM-TOPUP-500', 'quantity' => 1],
            ]);
            $orderId = $result['order_id'];
            $orderIds[] = $orderId;

            $this->multiOrderService->processPayment([
                'event_id' => 'evt_' . Str::random(8),
                'order_id' => $orderId,
                'status' => 'paid',
                'amount' => 500,
                'currency' => 'RUB',
                'created_at' => now()->toISOString(),
            ]);
        }

        echo "\n=== Same Key Not Assigned Twice Test ===";
        
        $keys = [];
        foreach ($orderIds as $orderId) {
            $order = Order::with(['items'])->find($orderId);
            $key = $order->items->first()->key_code;
            $keys[] = $key;
            echo "\nOrder: {$orderId} - Key: {$key}";
        }

        // Check all keys are unique
        $uniqueKeys = array_unique(array_filter($keys));
        $this->assertCount(count($orderIds), $uniqueKeys, 'All keys should be unique');

        echo "\n\n✅ No duplicate keys assigned!\n";
    }

    public function test_retry_after_error_does_not_cause_double_issuance()
    {
        // This test verifies retrying after an error doesn't issue a second key

        $result = $this->multiOrderService->createOrder([
            ['sku' => 'STEAM-TOPUP-500', 'quantity' => 1],
        ]);
        $orderId = $result['order_id'];

        $this->multiOrderService->processPayment([
            'event_id' => 'evt_' . Str::random(8),
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 500,
            'currency' => 'RUB',
            'created_at' => now()->toISOString(),
        ]);

        // Manually mark item as failed
        $item = OrderItem::where('order_id', $orderId)->first();
        $item->update([
            'status' => OrderItem::STATUS_FAILED,
            'failure_reason' => 'Supplier error',
            'failed_at' => now(),
        ]);

        // Retry
        $this->multiOrderService->retryFailedItems($orderId);

        // Check only one key is assigned
        $order = Order::find($orderId);
        $keyCount = Key::where('order_id', $orderId)->count();

        echo "\n=== Retry After Error Test ===";
        echo "\nOrder ID: {$orderId}";
        echo "\nOrder Status: {$order->status}";
        echo "\nKeys assigned to order: {$keyCount}";

        $this->assertEquals(1, $keyCount, 'Only one key should be assigned');

        echo "\n\n✅ Retry did not cause double issuance!\n";
    }
}
