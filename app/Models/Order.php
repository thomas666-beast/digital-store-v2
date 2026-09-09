<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'id', 'sku', 'amount', 'currency', 'status', 'type',
        'key_code', 'payment_event_id', 'total_delivered_value', 
        'total_refunded', 'paid_at', 'delivered_at', 'refunded_at', 'completed_at'
    ];
    
    protected $casts = [
        'paid_at' => 'datetime',
        'delivered_at' => 'datetime',
        'refunded_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
    
    const STATUS_CREATED = 'created';
    const STATUS_PAID = 'paid';
    const STATUS_DELIVERING = 'delivering';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_PARTIALLY_DELIVERED = 'partially_delivered';
    const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_COMPLETED = 'completed';
    const STATUS_PAYMENT_FAILED = 'payment_failed';
    
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }
    
    public function refunds()
    {
        return $this->hasMany(Refund::class, 'order_id', 'id');
    }
    
    public function deliveredItems()
    {
        return $this->items()->where('status', OrderItem::STATUS_DELIVERED);
    }
    
    public function failedItems()
    {
        return $this->items()->where('status', OrderItem::STATUS_FAILED);
    }
    
    public function refundedItems()
    {
        return $this->items()->where('status', OrderItem::STATUS_REFUNDED);
    }
    
    public function recalculateTotals(): void
    {
        $deliveredValue = $this->items()->where('status', OrderItem::STATUS_DELIVERED)->sum('total_price');
        $refundedValue = $this->items()->where('status', OrderItem::STATUS_REFUNDED)->sum('total_price');
        
        $this->update([
            'total_delivered_value' => $deliveredValue,
            'total_refunded' => $refundedValue,
        ]);
    }
    
    public function updateOrderStatus(): void
    {
        $this->recalculateTotals();
        
        $totalItems = $this->items()->count();
        $deliveredCount = $this->items()->where('status', OrderItem::STATUS_DELIVERED)->count();
        $refundedCount = $this->items()->where('status', OrderItem::STATUS_REFUNDED)->count();
        $failedCount = $this->items()->where('status', OrderItem::STATUS_FAILED)->count();
        $pendingCount = $this->items()->where('status', OrderItem::STATUS_PENDING)->count();
        
        if ($deliveredCount === $totalItems) {
            $this->update(['status' => self::STATUS_DELIVERED, 'completed_at' => now()]);
        } elseif ($deliveredCount > 0 && ($refundedCount + $failedCount) === ($totalItems - $deliveredCount)) {
            $this->update(['status' => self::STATUS_PARTIALLY_DELIVERED, 'completed_at' => now()]);
        } elseif ($refundedCount === $totalItems) {
            $this->update(['status' => self::STATUS_REFUNDED, 'completed_at' => now()]);
        } elseif ($refundedCount > 0 || $failedCount > 0) {
            $this->update(['status' => self::STATUS_PARTIALLY_REFUNDED]);
        } elseif ($pendingCount > 0 && $deliveredCount > 0) {
            // Still delivering
        }
    }
}
