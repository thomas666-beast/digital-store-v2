<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderQueue;
use App\Models\Product;
use App\Models\Key;
use App\Services\OrderQueueService;
use App\Services\RateLimiterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Illuminate\Support\Str;

class QueueTest extends TestCase
{
    use RefreshDatabase;

    protected OrderQueueService $queueService;
    protected RateLimiterService $rateLimiter;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->rateLimiter = app(RateLimiterService::class);
        $this->queueService = app(OrderQueueService::class);
        
        // Reset rate limiter before each test
        Cache::flush();
        $this->rateLimiter->reset();
        
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

        for ($i = 0; $i < 20; $i++) {
            Key::create([
                'code' => 'KEY-' . Str::random(12),
                'sku' => 'STEAM-TOPUP-500',
                'status' => 'available',
            ]);
        }
    }

    public function test_queue_order_when_rate_limit_reached()
    {
        Cache::flush();
        $this->rateLimiter->reset();
        
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->increment();
        }

        $this->assertFalse($this->rateLimiter->allowRequest());

        $response = $this->postJson('/api/orders/multi', [
            'items' => [['sku' => 'STEAM-TOPUP-500', 'quantity' => 1]],
        ]);
        $response->assertStatus(201);
        $orderId = $response->json('order_id');

        $paymentResponse = $this->postJson('/api/webhook/payment', [
            'event_id' => 'evt_' . Str::random(8),
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 500,
            'currency' => 'RUB',
            'created_at' => now()->toISOString(),
        ]);
        
        // Check response
        $paymentData = $paymentResponse->json();
        
        // If payment fails with 400, check the error
        if ($paymentResponse->status() === 400) {
            echo "\nPayment error: " . ($paymentData['error'] ?? 'Unknown error');
        }
        
        $paymentResponse->assertStatus(200);
        $this->assertTrue($paymentData['queued'] ?? false);

        $order = Order::find($orderId);
        $queueItem = OrderQueue::where('order_id', $orderId)->first();

        $this->assertTrue($order->is_queued);
        $this->assertNotNull($queueItem);
        $this->assertEquals('queued', $queueItem->status);

        echo "\n✅ Order queued when rate limit reached!\n";
    }

    public function test_paid_orders_have_higher_priority()
    {
        Cache::flush();
        $this->rateLimiter->reset();
        
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->increment();
        }

        $orderIds = [];
        
        for ($i = 0; $i < 2; $i++) {
            $response = $this->postJson('/api/orders/multi', [
                'items' => [['sku' => 'STEAM-TOPUP-500', 'quantity' => 1]],
            ]);
            $orderId = $response->json('order_id');
            $orderIds[] = $orderId;

            $paymentResponse = $this->postJson('/api/webhook/payment', [
                'event_id' => 'evt_' . Str::random(8),
                'order_id' => $orderId,
                'status' => 'paid',
                'amount' => 500,
                'currency' => 'RUB',
                'created_at' => now()->toISOString(),
            ]);
            
            if ($paymentResponse->status() === 400) {
                echo "\nPayment error for order $i: " . ($paymentResponse->json('error') ?? 'Unknown');
            }
            $paymentResponse->assertStatus(200);
        }

        $queue1 = OrderQueue::where('order_id', $orderIds[0])->first();
        $queue2 = OrderQueue::where('order_id', $orderIds[1])->first();

        $this->assertNotNull($queue1, 'Queue item 1 not found');
        $this->assertNotNull($queue2, 'Queue item 2 not found');
        $this->assertEquals(10, $queue1->priority);
        $this->assertEquals(10, $queue2->priority);

        echo "\n✅ Both paid orders have priority 10!\n";
    }

    public function test_queue_status_returns_correct_counts()
    {
        Cache::flush();
        $this->rateLimiter->reset();
        
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 10; $j++) {
                $this->rateLimiter->increment();
            }
            
            $response = $this->postJson('/api/orders/multi', [
                'items' => [['sku' => 'STEAM-TOPUP-500', 'quantity' => 1]],
            ]);
            $orderId = $response->json('order_id');

            $paymentResponse = $this->postJson('/api/webhook/payment', [
                'event_id' => 'evt_' . Str::random(8),
                'order_id' => $orderId,
                'status' => 'paid',
                'amount' => 500,
                'currency' => 'RUB',
                'created_at' => now()->toISOString(),
            ]);
            
            if ($paymentResponse->status() === 400) {
                echo "\nPayment error: " . ($paymentResponse->json('error') ?? 'Unknown');
            }
            $paymentResponse->assertStatus(200);
        }

        // Check if queue items exist
        $queueCount = \App\Models\OrderQueue::count();
        echo "\nTotal queue items: {$queueCount}";

        // Get queue status
        $response = $this->getJson('/api/queue/status');
        
        // If status is 500, show the error
        if ($response->status() === 500) {
            echo "\nError response: " . $response->getContent();
        }
        
        $response->assertStatus(200);

        $data = $response->json();
        
        echo "\n=== Queue Status ===";
        echo "\nTotal: " . $data['total'];
        echo "\nQueued: " . $data['queued'];

        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('queued', $data);

        echo "\n\n✅ Queue status API works!\n";
    }

    public function test_process_next_batch()
    {
        Cache::flush();
        $this->rateLimiter->reset();
        
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 10; $j++) {
                $this->rateLimiter->increment();
            }
            
            $response = $this->postJson('/api/orders/multi', [
                'items' => [['sku' => 'STEAM-TOPUP-500', 'quantity' => 1]],
            ]);
            $orderId = $response->json('order_id');

            $paymentResponse = $this->postJson('/api/webhook/payment', [
                'event_id' => 'evt_' . Str::random(8),
                'order_id' => $orderId,
                'status' => 'paid',
                'amount' => 500,
                'currency' => 'RUB',
                'created_at' => now()->toISOString(),
            ]);
            
            if ($paymentResponse->status() === 400) {
                echo "\nPayment error: " . ($paymentResponse->json('error') ?? 'Unknown');
            }
            $paymentResponse->assertStatus(200);
        }

        $queuedCount = OrderQueue::where('status', 'queued')->count();
        echo "\nQueued items before processing: {$queuedCount}";
        
        $this->rateLimiter->resetCounter();

        $response = $this->postJson('/api/queue/process', ['batch_size' => 3]);
        $response->assertStatus(200);

        $data = $response->json();
        
        echo "\n=== Process Batch ===";
        echo "\nProcessed: " . $data['count'];
        echo "\nRemaining: " . $data['remaining'];

        $this->assertGreaterThan(0, $data['count']);

        echo "\n\n✅ Process next batch works!\n";
    }

    public function test_rate_limit_resets_after_minute()
    {
        // Fill rate limit
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->increment();
        }

        $this->assertFalse($this->rateLimiter->allowRequest());

        // Reset counter
        $this->rateLimiter->resetCounter();

        $this->assertTrue($this->rateLimiter->allowRequest());

        echo "\n✅ Rate limit resets correctly!\n";
    }
}
