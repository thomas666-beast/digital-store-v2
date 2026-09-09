<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\FinancialSnapshot;
use App\Models\PeriodSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EventService
{
    /**
     * Record an event for an order
     */
    public function recordEvent(string $orderId, string $eventType, array $payload = []): ?OrderEvent
    {
        try {
            $event = OrderEvent::create([
                'order_id' => $orderId,
                'event_type' => $eventType,
                'payload' => $payload,
                'event_time' => now(),
                'version' => $this->getNextVersion($orderId),
            ]);

            Log::info('Event recorded', [
                'order_id' => $orderId,
                'event_type' => $eventType,
                'version' => $event->version,
            ]);

            return $event;
        } catch (\Exception $e) {
            Log::error('Failed to record event', [
                'order_id' => $orderId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get next version number for an order
     */
    protected function getNextVersion(string $orderId): int
    {
        $lastEvent = OrderEvent::where('order_id', $orderId)
            ->orderBy('version', 'desc')
            ->first();
            
        return $lastEvent ? $lastEvent->version + 1 : 1;
    }

    /**
     * Reconstruct order state at a specific time
     */
    public function reconstructOrder(string $orderId, ?\DateTime $timestamp = null): array
    {
        $query = OrderEvent::where('order_id', $orderId)
            ->orderBy('version', 'asc');
            
        if ($timestamp) {
            $query->where('event_time', '<=', $timestamp);
        }
        
        $events = $query->get();
        
        $state = [
            'order_id' => $orderId,
            'status' => 'unknown',
            'items' => [],
            'total_amount' => 0,
            'delivered_amount' => 0,
            'refunded_amount' => 0,
            'balance' => 0,
            'events' => [],
        ];
        
        foreach ($events as $event) {
            $state = $this->applyEvent($state, $event);
            $state['events'][] = [
                'type' => $event->event_type,
                'time' => $event->event_time->toISOString(),
                'version' => $event->version,
            ];
        }
        
        return $state;
    }

    /**
     * Apply an event to the state
     */
    protected function applyEvent(array $state, OrderEvent $event): array
    {
        $payload = $event->payload;
        
        switch ($event->event_type) {
            case OrderEvent::EVENT_ORDER_CREATED:
                $state['status'] = 'created';
                $state['total_amount'] = $payload['total_amount'] ?? 0;
                $state['items'] = $payload['items'] ?? [];
                break;
                
            case OrderEvent::EVENT_PAYMENT_RECEIVED:
                if (isset($payload['status']) && $payload['status'] === 'failed') {
                    $state['status'] = 'payment_failed';
                } else {
                    $state['status'] = 'paid';
                    $state['balance'] += $payload['amount'] ?? 0;
                }
                break;
                
            case OrderEvent::EVENT_ITEM_DELIVERED:
                $state['delivered_amount'] += $payload['amount'] ?? 0;
                $state['status'] = $state['delivered_amount'] >= $state['total_amount'] 
                    ? 'delivered' 
                    : 'partially_delivered';
                break;
                
            case OrderEvent::EVENT_ITEM_FAILED:
                $state['status'] = 'partially_failed';
                break;
                
            case OrderEvent::EVENT_REFUND_ISSUED:
                $state['refunded_amount'] += abs($payload['amount'] ?? 0);
                $state['balance'] -= abs($payload['amount'] ?? 0);
                $state['status'] = $state['balance'] <= 0 ? 'refunded' : 'partially_refunded';
                break;
                
            case OrderEvent::EVENT_ORDER_COMPLETED:
                $state['status'] = 'completed';
                break;
        }
        
        return $state;
    }

    /**
     * Get financial balance at a specific time
     */
    public function getBalanceAtTime(string $orderId, \DateTime $timestamp): int
    {
        $events = OrderEvent::where('order_id', $orderId)
            ->where('event_time', '<=', $timestamp)
            ->orderBy('version', 'asc')
            ->get();
            
        $balance = 0;
        
        foreach ($events as $event) {
            if ($event->event_type === OrderEvent::EVENT_PAYMENT_RECEIVED) {
                $balance += $event->payload['amount'] ?? 0;
            } elseif ($event->event_type === OrderEvent::EVENT_REFUND_ISSUED) {
                $balance -= abs($event->payload['amount'] ?? 0);
            }
        }
        
        return $balance;
    }

    /**
     * Take a snapshot of current state
     */
    public function takeSnapshot(string $orderId): void
    {
        try {
            $state = $this->reconstructOrder($orderId);
            
            FinancialSnapshot::create([
                'order_id' => $orderId,
                'balance' => $state['balance'],
                'total_delivered' => $state['delivered_amount'],
                'total_refunded' => $state['refunded_amount'],
                'snapshot_time' => now(),
                'event_version' => $this->getNextVersion($orderId) - 1,
            ]);
            
            Log::info('Snapshot taken', ['order_id' => $orderId]);
        } catch (\Exception $e) {
            Log::error('Failed to take snapshot', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate period summary
     */
    public function generatePeriodSummary(string $periodType, \DateTime $date): array
    {
        $startDate = clone $date;
        $endDate = clone $date;
        
        switch ($periodType) {
            case 'periodic_daily':
                $startDate->setTime(0, 0, 0);
                $endDate->setTime(23, 59, 59);
                break;
            case 'periodic_weekly':
                $startDate->modify('monday this week')->setTime(0, 0, 0);
                $endDate->modify('sunday this week')->setTime(23, 59, 59);
                break;
            case 'periodic_monthly':
                $startDate->modify('first day of this month')->setTime(0, 0, 0);
                $endDate->modify('last day of this month')->setTime(23, 59, 59);
                break;
            default:
                $startDate->setTime(0, 0, 0);
                $endDate->setTime(23, 59, 59);
                break;
        }
        
        $orders = Order::whereBetween('created_at', [$startDate, $endDate])->get();
        
        $summary = [
            'total_orders' => $orders->count(),
            'total_revenue' => $orders->sum('amount'),
            'total_refunds' => $orders->sum('total_refunded'),
            'net_revenue' => $orders->sum('amount') - $orders->sum('total_refunded'),
            'delivered_count' => $orders->where('status', Order::STATUS_DELIVERED)->count(),
            'failed_count' => $orders->where('status', Order::STATUS_PAYMENT_FAILED)->count(),
            'refunded_count' => $orders->where('status', Order::STATUS_REFUNDED)->count(),
            'top_products' => $this->getTopProducts($startDate, $endDate),
        ];
        
        // Save summary
        try {
            PeriodSummary::updateOrCreate(
                ['period_type' => $periodType, 'period_date' => $date->format('Y-m-d')],
                $summary
            );
        } catch (\Exception $e) {
            Log::error('Failed to save period summary', ['error' => $e->getMessage()]);
        }
        
        return $summary;
    }

    /**
     * Get top products for period
     */
    protected function getTopProducts(\DateTime $startDate, \DateTime $endDate): array
    {
        try {
            return DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereBetween('orders.created_at', [$startDate, $endDate])
                ->select(
                    'order_items.sku',
                    DB::raw('SUM(order_items.quantity) as total_quantity'),
                    DB::raw('SUM(order_items.total_price) as total_revenue')
                )
                ->groupBy('order_items.sku')
                ->orderBy('total_revenue', 'desc')
                ->limit(5)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }
}
