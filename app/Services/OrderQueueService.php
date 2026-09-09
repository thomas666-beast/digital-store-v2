<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderQueue;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderQueueService
{
    protected RateLimiterService $rateLimiter;
    protected EventService $eventService;

    public function __construct(RateLimiterService $rateLimiter, EventService $eventService)
    {
        $this->rateLimiter = $rateLimiter;
        $this->eventService = $eventService;
    }

    public function queueOrder(string $orderId, string $sku, bool $isPaid = false): array
    {
        $priority = $isPaid ? 10 : 0;
        
        $queueItem = OrderQueue::create([
            'order_id' => $orderId,
            'sku' => $sku,
            'priority' => $priority,
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        $position = $this->getQueuePosition($orderId);
        
        Order::where('id', $orderId)->update([
            'is_queued' => true,
            'queued_at' => now(),
            'queue_position' => $position,
        ]);

        Log::info('Order queued', [
            'order_id' => $orderId,
            'queue_id' => $queueItem->id,
            'priority' => $priority,
            'position' => $position,
        ]);

        return [
            'order_id' => $orderId,
            'queue_id' => $queueItem->id,
            'status' => 'queued',
            'position' => $position,
            'message' => 'Order queued for processing',
        ];
    }

    public function processNextBatch(int $batchSize = 5): array
    {
        $processed = [];
        $availableCapacity = $this->rateLimiter->getAvailableCapacity();
        
        if ($availableCapacity <= 0) {
            return [
                'processed' => [],
                'message' => 'Rate limit reached',
                'available_capacity' => 0,
                'count' => 0,
                'remaining' => OrderQueue::where('status', 'queued')->count(),
            ];
        }

        $limit = min($batchSize, $availableCapacity);
        
        $queueItems = OrderQueue::where('status', 'queued')
            ->orderBy('priority', 'desc')
            ->orderBy('queued_at', 'asc')
            ->limit($limit)
            ->get();

        foreach ($queueItems as $item) {
            try {
                $result = $this->processQueueItem($item);
                $processed[] = $result;
            } catch (\Exception $e) {
                Log::error('Failed to process queue item', [
                    'queue_id' => $item->id,
                    'order_id' => $item->order_id,
                    'error' => $e->getMessage(),
                ]);
                
                $item->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'processed' => $processed,
            'count' => count($processed),
            'remaining' => OrderQueue::where('status', 'queued')->count(),
        ];
    }

    protected function processQueueItem(OrderQueue $item): array
    {
        if (!$this->rateLimiter->allowRequest()) {
            return [
                'queue_id' => $item->id,
                'order_id' => $item->order_id,
                'status' => 'skipped',
                'reason' => 'Rate limit reached',
            ];
        }

        $item->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            $order = Order::find($item->order_id);
            
            if (!$order) {
                throw new \Exception('Order not found');
            }

            $orderItems = OrderItem::where('order_id', $item->order_id)->get();
            
            foreach ($orderItems as $orderItem) {
                // Simulate supplier call
                $success = rand(1, 10) > 2;
                if (!$success) {
                    throw new \Exception('Supplier error');
                }
            }

            $this->rateLimiter->increment();

            $order->update([
                'is_queued' => false,
                'fulfilled_at' => now(),
                'queue_position' => null,
            ]);

            $item->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            Log::info('Queue item processed', [
                'queue_id' => $item->id,
                'order_id' => $item->order_id,
            ]);

            return [
                'queue_id' => $item->id,
                'order_id' => $item->order_id,
                'status' => 'completed',
                'order_status' => $order->status,
            ];

        } catch (\Exception $e) {
            $item->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'attempts' => $item->attempts + 1,
            ]);

            throw $e;
        }
    }

    public function getQueuePosition(string $orderId): int
    {
        return OrderQueue::where('status', 'queued')
            ->where('id', '<=', function ($query) use ($orderId) {
                $query->select('id')
                    ->from('order_queues')
                    ->where('order_id', $orderId)
                    ->where('status', 'queued')
                    ->orderBy('id', 'desc')
                    ->limit(1);
            })
            ->count();
    }

    public function getQueueStatus(): array
    {
        try {
            $queued = OrderQueue::where('status', 'queued')->count();
            $processing = OrderQueue::where('status', 'processing')->count();
            $completed = OrderQueue::where('status', 'completed')->count();
            $failed = OrderQueue::where('status', 'failed')->count();

            $rateLimitStatus = $this->rateLimiter->getStatus();

            return [
                'total' => $queued + $processing + $completed + $failed,
                'queued' => $queued,
                'processing' => $processing,
                'completed' => $completed,
                'failed' => $failed,
                'rate_limit' => $rateLimitStatus,
            ];
        } catch (\Exception $e) {
            \Log::error('GetQueueStatus error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function retryFailed(): array
    {
        $failed = OrderQueue::where('status', 'failed')
            ->where('attempts', '<', 5)
            ->get();

        $retried = [];

        foreach ($failed as $item) {
            $item->update([
                'status' => 'queued',
                'error_message' => null,
            ]);
            
            $retried[] = [
                'queue_id' => $item->id,
                'order_id' => $item->order_id,
                'attempt' => $item->attempts + 1,
            ];
        }

        return $retried;
    }
}
