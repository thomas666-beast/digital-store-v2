<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderEvent;
use App\Models\Refund;
use App\Models\Product;
use App\Models\Key;
use App\Models\MoneyJournal;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class MultiOrderService
{
    protected SupplierService $supplierService;
    protected RateLimiterService $rateLimiter;
    protected EventService $eventService;

    public function __construct(
        SupplierService $supplierService,
        RateLimiterService $rateLimiter,
        EventService $eventService
    ) {
        $this->supplierService = $supplierService;
        $this->rateLimiter = $rateLimiter;
        $this->eventService = $eventService;
    }

    /**
     * Create a multi-item order
     */
    public function createOrder(array $items): array
    {
        $orderId = 'ORD-' . Str::uuid();
        $totalAmount = 0;
        $currency = 'RUB';
        $firstSku = null;
        
        DB::beginTransaction();
        
        try {
            foreach ($items as $item) {
                $product = Product::where('sku', $item['sku'])->first();
                
                if (!$product) {
                    DB::rollBack();
                    return ['error' => "Product not found: {$item['sku']}", 'code' => 404];
                }
                
                if (!$firstSku) {
                    $firstSku = $item['sku'];
                }
                
                $quantity = $item['quantity'] ?? 1;
                
                $availableKeys = Key::where('sku', $item['sku'])
                    ->where('status', 'available')
                    ->count();
                    
                if ($availableKeys < $quantity) {
                    DB::rollBack();
                    return [
                        'error' => "Insufficient keys for: {$item['sku']}",
                        'code' => 400,
                        'available' => $availableKeys,
                        'requested' => $quantity
                    ];
                }
                
                $totalAmount += $product->price * $quantity;
            }
            
            $order = Order::create([
                'id' => $orderId,
                'sku' => $firstSku,
                'amount' => $totalAmount,
                'currency' => $currency,
                'status' => Order::STATUS_CREATED,
                'type' => 'multi',
                'total_delivered_value' => 0,
                'total_refunded' => 0,
                'is_queued' => false,
            ]);
            
            $itemDetails = [];
            foreach ($items as $item) {
                $product = Product::where('sku', $item['sku'])->first();
                $quantity = $item['quantity'] ?? 1;
                $itemDetails[] = [
                    'sku' => $item['sku'],
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                ];
                
                for ($i = 0; $i < $quantity; $i++) {
                    OrderItem::create([
                        'order_id' => $orderId,
                        'sku' => $item['sku'],
                        'quantity' => 1,
                        'unit_price' => $product->price,
                        'total_price' => $product->price,
                        'currency' => $currency,
                        'status' => OrderItem::STATUS_PENDING,
                        'key_verified' => false,
                        'verification_attempts' => 0,
                    ]);
                }
            }
            
            DB::commit();
            
            // Record event
            $this->eventService->recordEvent(
                $orderId,
                OrderEvent::EVENT_ORDER_CREATED,
                [
                    'total_amount' => $totalAmount,
                    'items' => $itemDetails,
                    'currency' => $currency,
                ]
            );
            
            Log::info('Multi-item order created', [
                'order_id' => $orderId,
                'items' => count($items),
                'total' => $totalAmount
            ]);
            
            return [
                'order_id' => $orderId,
                'status' => Order::STATUS_CREATED,
                'total_amount' => $totalAmount,
                'items' => count($items),
                'currency' => $currency,
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create multi-order', ['error' => $e->getMessage()]);
            return ['error' => 'Failed to create order', 'code' => 500];
        }
    }

    /**
     * Get order with all items and refunds
     */
    public function getOrder(string $orderId): ?array
    {
        $order = Order::with(['items', 'refunds'])->find($orderId);
        
        if (!$order) {
            return null;
        }
        
        return [
            'order' => $order,
            'items' => $order->items,
            'refunds' => $order->refunds,
            'queue_status' => $order->is_queued ? [
                'position' => $order->queue_position,
                'queued_at' => $order->queued_at,
            ] : null,
            'summary' => [
                'total' => $order->amount,
                'delivered_value' => $order->total_delivered_value,
                'refunded_value' => $order->total_refunded,
                'balance' => $order->amount - $order->total_delivered_value - $order->total_refunded,
            ]
        ];
    }

    /**
     * Process payment webhook with rate limiting and event recording
     */
    public function processPayment(array $payload): array
    {
        $eventId = $payload['event_id'];
        $orderId = $payload['order_id'];
        $status = $payload['status'];
        
        // Check duplicate
        $existing = WebhookLog::find($eventId);
        if ($existing) {
            return ['status' => 'already_processed'];
        }
        
        $lockKey = "payment:{$orderId}";
        $lock = Cache::lock($lockKey, 10);
        
        if (!$lock->get()) {
            return ['error' => 'Order is being processed', 'code' => 409];
        }
        
        try {
            DB::beginTransaction();
            
            $order = Order::where('id', $orderId)->lockForUpdate()->first();
            
            if (!$order) {
                DB::rollBack();
                $lock->release();
                return ['error' => 'Order not found'];
            }
            
            $existingCheck = WebhookLog::find($eventId);
            if ($existingCheck) {
                DB::rollBack();
                $lock->release();
                return ['status' => 'already_processed'];
            }
            
            WebhookLog::create([
                'event_id' => $eventId,
                'order_id' => $orderId,
                'status' => $status,
                'payload' => $payload,
            ]);
            
            if ($status === 'failed') {
                $order->update(['status' => Order::STATUS_PAYMENT_FAILED]);
                
                // Record event
                $this->eventService->recordEvent(
                    $orderId,
                    OrderEvent::EVENT_PAYMENT_RECEIVED,
                    [
                        'amount' => $order->amount,
                        'event_id' => $eventId,
                        'status' => 'failed',
                    ]
                );
                
                DB::commit();
                $lock->release();
                return ['status' => 'payment_failed'];
            }
            
            if ($status === 'paid') {
                if (in_array($order->status, [
                    Order::STATUS_DELIVERED,
                    Order::STATUS_COMPLETED,
                    Order::STATUS_PARTIALLY_DELIVERED,
                    Order::STATUS_PAID,
                ])) {
                    DB::commit();
                    $lock->release();
                    return ['status' => 'already_paid', 'order_id' => $orderId];
                }
                
                if ($order->status !== Order::STATUS_CREATED) {
                    DB::rollBack();
                    $lock->release();
                    return ['error' => 'Invalid order state: ' . $order->status];
                }
                
                $order->update([
                    'status' => Order::STATUS_PAID,
                    'payment_event_id' => $eventId,
                    'paid_at' => now(),
                ]);
                
                // Record payment in money journal
                $balance = MoneyJournal::sum('balance_after') ?: 0;
                MoneyJournal::create([
                    'order_id' => $orderId,
                    'event_type' => 'payment_received',
                    'amount' => $order->amount,
                    'currency' => $order->currency,
                    'balance_before' => $balance,
                    'balance_after' => $balance + $order->amount,
                    'metadata' => ['event_id' => $eventId, 'type' => 'multi'],
                ]);
                
                // Record event
                $this->eventService->recordEvent(
                    $orderId,
                    OrderEvent::EVENT_PAYMENT_RECEIVED,
                    [
                        'amount' => $order->amount,
                        'event_id' => $eventId,
                        'status' => 'paid',
                    ]
                );
                
                DB::commit();
                $lock->release();
                
                Log::info('Multi-order payment processed', ['order_id' => $orderId]);
                
                // Check rate limit
                $hasCapacity = $this->rateLimiter->allowRequest();
                
                if ($hasCapacity) {
                    // Process immediately
                    $this->rateLimiter->increment();
                    $this->processOrderItems($orderId);
                    
                    // Take snapshot after processing
                    $this->eventService->takeSnapshot($orderId);
                    
                    return [
                        'status' => 'paid',
                        'order_id' => $orderId,
                    ];
                } else {
                    // Queue the order
                    try {
                        $queueService = app(OrderQueueService::class);
                        $queueResult = $queueService->queueOrder($orderId, $order->sku, true);
                        
                        // Record queue event
                        $this->eventService->recordEvent(
                            $orderId,
                            OrderEvent::EVENT_ORDER_QUEUED,
                            [
                                'queue_id' => $queueResult['queue_id'],
                                'position' => $queueResult['position'],
                                'reason' => 'rate_limit_reached',
                            ]
                        );
                        
                        Log::info('Order queued successfully', [
                            'order_id' => $orderId,
                            'queue_result' => $queueResult,
                        ]);
                        
                        return [
                            'status' => 'paid',
                            'order_id' => $orderId,
                            'queued' => true,
                            'message' => 'Order queued due to rate limit',
                        ];
                    } catch (\Exception $e) {
                        Log::error('Failed to queue order', [
                            'order_id' => $orderId,
                            'error' => $e->getMessage(),
                        ]);
                        
                        return [
                            'status' => 'paid',
                            'order_id' => $orderId,
                            'queued' => false,
                            'error' => 'Failed to queue: ' . $e->getMessage(),
                        ];
                    }
                }
            }
            
            DB::rollBack();
            $lock->release();
            return ['error' => 'Invalid webhook status: ' . $status];
            
        } catch (\Exception $e) {
            DB::rollBack();
            $lock->release();
            Log::error('Multi-order payment error', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            return ['error' => 'Payment processing failed: ' . $e->getMessage()];
        }
    }

    /**
     * Process all items in an order
     */
    protected function processOrderItems(string $orderId): void
    {
        $processingKey = "processing:{$orderId}";
        if (Cache::get($processingKey)) {
            return;
        }
        Cache::put($processingKey, true, 60);
        
        try {
            $items = OrderItem::where('order_id', $orderId)
                ->whereIn('status', [OrderItem::STATUS_PENDING, OrderItem::STATUS_FAILED])
                ->get();
            
            foreach ($items as $item) {
                $this->processSingleItem($item);
            }
            
            $order = Order::find($orderId);
            $order->updateOrderStatus();
            
            // Record completion event if fully delivered
            if ($order->status === Order::STATUS_DELIVERED) {
                $this->eventService->recordEvent(
                    $orderId,
                    OrderEvent::EVENT_ORDER_COMPLETED,
                    [
                        'final_status' => $order->status,
                        'delivered_amount' => $order->total_delivered_value,
                    ]
                );
                
                // Take final snapshot
                $this->eventService->takeSnapshot($orderId);
            }
            
        } finally {
            Cache::forget($processingKey);
        }
    }

    /**
     * Process a single order item
     */
    protected function processSingleItem(OrderItem $item): void
    {
        $lockKey = "item:{$item->id}";
        $lock = Cache::lock($lockKey, 30);
        
        if (!$lock->get()) {
            return;
        }
        
        try {
            DB::beginTransaction();
            
            $item = OrderItem::where('id', $item->id)->lockForUpdate()->first();
            
            if (!$item || $item->isFinal()) {
                DB::rollBack();
                $lock->release();
                return;
            }
            
            // Check if this order already has a key (prevent duplicates)
            $existingKey = Key::where('order_id', $item->order_id)
                ->where('status', 'used')
                ->first();
                
            if ($existingKey) {
                $item->update([
                    'status' => OrderItem::STATUS_DELIVERED,
                    'key_code' => $existingKey->code,
                    'delivered_at' => now(),
                    'key_verified' => true,
                    'key_verified_at' => now(),
                ]);
                
                $order = Order::find($item->order_id);
                $order->increment('total_delivered_value', $item->total_price);
                
                // Record delivery event
                $this->eventService->recordEvent(
                    $item->order_id,
                    OrderEvent::EVENT_ITEM_DELIVERED,
                    [
                        'item_id' => $item->id,
                        'sku' => $item->sku,
                        'amount' => $item->total_price,
                        'key' => $existingKey->code,
                        'source' => 'existing_key',
                    ]
                );
                
                DB::commit();
                $lock->release();
                
                Log::info('Item delivered with existing key', [
                    'item_id' => $item->id,
                    'key' => $existingKey->code,
                    'order_id' => $item->order_id,
                ]);
                return;
            }
            
            $item->update(['status' => OrderItem::STATUS_DELIVERING]);
            
            $requestId = 'REQ-' . Str::uuid();
            $item->update(['delivery_request_id' => $requestId]);
            
            DB::commit();
            $lock->release();
            
            // Call supplier
            $result = $this->supplierService->issue(
                $requestId,
                $item->sku,
                $item->order_id,
                config('app.supplier_timeout', 5),
                config('app.supplier_retries', 3),
                1
            );
            
            $this->handleItemResult($item, $result);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $lock->release();
            Log::error('Item processing failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage()
            ]);
            
            $item->update([
                'status' => OrderItem::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'failed_at' => now(),
                'retry_count' => $item->retry_count + 1,
            ]);
            
            // Record failure event
            $this->eventService->recordEvent(
                $item->order_id,
                OrderEvent::EVENT_ITEM_FAILED,
                [
                    'item_id' => $item->id,
                    'sku' => $item->sku,
                    'amount' => $item->total_price,
                    'reason' => $e->getMessage(),
                ]
            );
            
            $this->processRefund($item);
        }
    }

    /**
     * Handle supplier result for an item
     */
    protected function handleItemResult(OrderItem $item, array $result): void
    {
        try {
            DB::beginTransaction();
            
            $item = OrderItem::where('id', $item->id)->lockForUpdate()->first();
            
            if (!$item || $item->isFinal()) {
                DB::rollBack();
                return;
            }

            // Check if this order already has a key (prevent duplicates)
            $existingKey = Key::where('order_id', $item->order_id)
                ->where('status', 'used')
                ->first();
                
            if ($existingKey && $item->key_code !== $existingKey->code) {
                $item->update([
                    'status' => OrderItem::STATUS_DELIVERED,
                    'key_code' => $existingKey->code,
                    'delivered_at' => now(),
                    'key_verified' => true,
                    'key_verified_at' => now(),
                ]);
                
                $order = Order::find($item->order_id);
                $order->increment('total_delivered_value', $item->total_price);
                
                DB::commit();
                
                Log::info('Item delivered with existing key', [
                    'item_id' => $item->id,
                    'key' => $existingKey->code,
                    'order_id' => $item->order_id,
                ]);
                return;
            }

            // Handle different supplier response types
            switch ($result['status']) {
                case 'success':
                case 'timeout_but_issued':
                case 'error_but_issued':
                    if (isset($result['code'])) {
                        // Check if this key is already used by another order
                        $duplicateCheck = Key::where('code', $result['code'])
                            ->where('order_id', '!=', $item->order_id)
                            ->where('status', 'used')
                            ->exists();
                        
                        if ($duplicateCheck) {
                            Log::warning('Duplicate key detected, finding replacement', [
                                'item_id' => $item->id,
                                'key' => $result['code'],
                                'order_id' => $item->order_id,
                            ]);
                            
                            $replacement = $this->getReplacementKey($item->sku, $item->order_id);
                            
                            if ($replacement) {
                                $item->update([
                                    'status' => OrderItem::STATUS_DELIVERED,
                                    'key_code' => $replacement->code,
                                    'delivered_at' => now(),
                                    'key_verified' => true,
                                    'key_verified_at' => now(),
                                ]);
                                
                                $order = Order::find($item->order_id);
                                $order->increment('total_delivered_value', $item->total_price);
                                
                                DB::commit();
                                
                                // Record delivery event
                                $this->eventService->recordEvent(
                                    $item->order_id,
                                    OrderEvent::EVENT_ITEM_DELIVERED,
                                    [
                                        'item_id' => $item->id,
                                        'sku' => $item->sku,
                                        'amount' => $item->total_price,
                                        'key' => $replacement->code,
                                        'source' => 'replacement',
                                        'original_key' => $result['code'],
                                    ]
                                );
                                
                                Log::info('Item recovered with replacement key', [
                                    'item_id' => $item->id,
                                    'replacement_key' => $replacement->code,
                                    'order_id' => $item->order_id,
                                ]);
                                return;
                            }
                            
                            $item->update([
                                'status' => OrderItem::STATUS_FAILED,
                                'failure_reason' => 'Duplicate key from supplier, no replacement available',
                                'failed_at' => now(),
                                'retry_count' => $item->retry_count + 1,
                            ]);
                            
                            DB::commit();
                            
                            // Record failure event
                            $this->eventService->recordEvent(
                                $item->order_id,
                                OrderEvent::EVENT_ITEM_FAILED,
                                [
                                    'item_id' => $item->id,
                                    'sku' => $item->sku,
                                    'amount' => $item->total_price,
                                    'reason' => 'duplicate_key_no_replacement',
                                ]
                            );
                            
                            $this->processRefund($item);
                            return;
                        }
                        
                        // Key is not a duplicate - check if it exists or create it
                        $key = Key::where('code', $result['code'])->first();
                        
                        if (!$key) {
                            $key = Key::create([
                                'code' => $result['code'],
                                'sku' => $item->sku,
                                'status' => 'used',
                                'order_id' => $item->order_id,
                                'used_at' => now(),
                                'used_count' => 1,
                            ]);
                        }
                        
                        if ($key->status === 'used' && $key->order_id === $item->order_id) {
                            $item->update([
                                'status' => OrderItem::STATUS_DELIVERED,
                                'key_code' => $result['code'],
                                'delivered_at' => now(),
                                'key_verified' => true,
                                'key_verified_at' => now(),
                            ]);
                            
                            $order = Order::find($item->order_id);
                            $order->increment('total_delivered_value', $item->total_price);
                            
                            DB::commit();
                            
                            // Record delivery event
                            $this->eventService->recordEvent(
                                $item->order_id,
                                OrderEvent::EVENT_ITEM_DELIVERED,
                                [
                                    'item_id' => $item->id,
                                    'sku' => $item->sku,
                                    'amount' => $item->total_price,
                                    'key' => $result['code'],
                                    'source' => 'supplier',
                                ]
                            );
                            
                            Log::info('Item delivered with key', [
                                'item_id' => $item->id,
                                'key' => $result['code'],
                                'order_id' => $item->order_id,
                            ]);
                            return;
                        }
                    }
                    break;
                    
                case 'timeout':
                    DB::commit();
                    Log::warning('Supplier timeout', [
                        'item_id' => $item->id,
                        'order_id' => $item->order_id,
                    ]);
                    return;
                    
                case 'duplicate_key':
                    Log::warning('Supplier returned duplicate key, finding replacement', [
                        'item_id' => $item->id,
                        'key' => $result['code'],
                        'order_id' => $item->order_id,
                    ]);
                    
                    $replacement = $this->getReplacementKey($item->sku, $item->order_id);
                    
                    if ($replacement) {
                        $item->update([
                            'status' => OrderItem::STATUS_DELIVERED,
                            'key_code' => $replacement->code,
                            'delivered_at' => now(),
                            'key_verified' => true,
                            'key_verified_at' => now(),
                        ]);
                        
                        $order = Order::find($item->order_id);
                        $order->increment('total_delivered_value', $item->total_price);
                        
                        DB::commit();
                        
                        // Record delivery event
                        $this->eventService->recordEvent(
                            $item->order_id,
                            OrderEvent::EVENT_ITEM_DELIVERED,
                            [
                                'item_id' => $item->id,
                                'sku' => $item->sku,
                                'amount' => $item->total_price,
                                'key' => $replacement->code,
                                'source' => 'duplicate_replacement',
                            ]
                        );
                        
                        Log::info('Item recovered from duplicate key', [
                            'item_id' => $item->id,
                            'replacement_key' => $replacement->code,
                            'order_id' => $item->order_id,
                        ]);
                        return;
                    }
                    break;
                    
                case 'invalid_key':
                    Log::warning('Supplier returned invalid key', [
                        'item_id' => $item->id,
                        'order_id' => $item->order_id,
                    ]);
                    break;
            }
            
            // If we reach here, delivery failed
            $item->update([
                'status' => OrderItem::STATUS_FAILED,
                'failure_reason' => $result['reason'] ?? 'supplier_error',
                'failed_at' => now(),
                'retry_count' => $item->retry_count + 1,
            ]);
            
            DB::commit();
            
            // Record failure event
            $this->eventService->recordEvent(
                $item->order_id,
                OrderEvent::EVENT_ITEM_FAILED,
                [
                    'item_id' => $item->id,
                    'sku' => $item->sku,
                    'amount' => $item->total_price,
                    'reason' => $result['reason'] ?? 'supplier_error',
                ]
            );
            
            $this->processRefund($item);
            
            $order = Order::find($item->order_id);
            $order->updateOrderStatus();
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to handle item result', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get a replacement key for an order
     */
    protected function getReplacementKey(string $sku, string $orderId): ?Key
    {
        $existingKey = Key::where('order_id', $orderId)->where('status', 'used')->first();
        if ($existingKey) {
            return $existingKey;
        }
        
        $key = Key::where('sku', $sku)
            ->where('status', 'available')
            ->lockForUpdate()
            ->first();
        
        if ($key) {
            $key->update([
                'status' => 'used',
                'order_id' => $orderId,
                'used_at' => now(),
                'used_count' => ($key->used_count ?? 0) + 1,
            ]);
            return $key;
        }
        
        return null;
    }

    /**
     * Process refund for a failed item
     */
    public function processRefund(OrderItem $item): void
    {
        try {
            DB::beginTransaction();
            
            if ($item->status === OrderItem::STATUS_DELIVERED) {
                DB::rollBack();
                return;
            }
            
            $existing = Refund::where('order_item_id', $item->id)->first();
            if ($existing) {
                DB::rollBack();
                return;
            }
            
            if ($item->status !== OrderItem::STATUS_REFUNDED) {
                Refund::create([
                    'order_id' => $item->order_id,
                    'order_item_id' => $item->id,
                    'amount' => $item->total_price,
                    'currency' => $item->currency,
                    'status' => Refund::STATUS_COMPLETED,
                    'reason' => 'Item delivery failed: ' . ($item->failure_reason ?? 'Unknown'),
                    'completed_at' => now(),
                ]);
                
                $item->update([
                    'status' => OrderItem::STATUS_REFUNDED,
                    'refunded_at' => now(),
                ]);
                
                $order = Order::find($item->order_id);
                $order->recalculateTotals();
                
                $balance = MoneyJournal::sum('balance_after') ?: 0;
                MoneyJournal::create([
                    'order_id' => $item->order_id,
                    'event_type' => 'refund_issued',
                    'amount' => -$item->total_price,
                    'currency' => $item->currency,
                    'balance_before' => $balance,
                    'balance_after' => $balance - $item->total_price,
                    'metadata' => ['item_id' => $item->id],
                ]);
                
                // Record refund event
                $this->eventService->recordEvent(
                    $item->order_id,
                    OrderEvent::EVENT_REFUND_ISSUED,
                    [
                        'item_id' => $item->id,
                        'amount' => $item->total_price,
                        'reason' => $item->failure_reason ?? 'delivery_failed',
                    ]
                );
                
                // Take snapshot after refund
                $this->eventService->takeSnapshot($item->order_id);
                
                DB::commit();
                
                Log::info('Refund processed', [
                    'order_id' => $item->order_id,
                    'item_id' => $item->id,
                    'amount' => $item->total_price
                ]);
            } else {
                DB::rollBack();
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process refund', [
                'item_id' => $item->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Retry failed items
     */
    public function retryFailedItems(string $orderId): array
    {
        $results = [];
        
        $failedItems = OrderItem::where('order_id', $orderId)
            ->where('status', OrderItem::STATUS_FAILED)
            ->get();
        
        foreach ($failedItems as $item) {
            $existingKey = Key::where('order_id', $orderId)
                ->where('status', 'used')
                ->first();
            
            if ($existingKey) {
                $item->update([
                    'status' => OrderItem::STATUS_DELIVERED,
                    'key_code' => $existingKey->code,
                    'delivered_at' => now(),
                    'key_verified' => true,
                    'key_verified_at' => now(),
                    'failure_reason' => null,
                    'failed_at' => null,
                ]);
                
                $order = Order::find($orderId);
                $order->increment('total_delivered_value', $item->total_price);
                $order->decrement('total_refunded', $item->total_price);
                
                // Record retry success event
                $this->eventService->recordEvent(
                    $orderId,
                    OrderEvent::EVENT_ITEM_DELIVERED,
                    [
                        'item_id' => $item->id,
                        'sku' => $item->sku,
                        'amount' => $item->total_price,
                        'key' => $existingKey->code,
                        'source' => 'retry_existing_key',
                    ]
                );
                
                $results[] = [
                    'item_id' => $item->id,
                    'sku' => $item->sku,
                    'new_status' => OrderItem::STATUS_DELIVERED,
                    'key' => $existingKey->code,
                    'action' => 'reused_existing_key',
                ];
                
                continue;
            }
            
            $item->update([
                'status' => OrderItem::STATUS_PENDING,
                'failed_at' => null,
                'failure_reason' => null,
            ]);
            
            // Record retry event
            $this->eventService->recordEvent(
                $orderId,
                'order_item_retry',
                [
                    'item_id' => $item->id,
                    'sku' => $item->sku,
                    'attempt' => $item->retry_count + 1,
                ]
            );
            
            $this->processSingleItem($item);
            
            $results[] = [
                'item_id' => $item->id,
                'sku' => $item->sku,
                'new_status' => $item->fresh()->status,
            ];
        }
        
        $order = Order::find($orderId);
        $order->recalculateTotals();
        $order->updateOrderStatus();
        
        return $results;
    }
}
