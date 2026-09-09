<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'sku', 'quantity', 'unit_price', 'total_price',
        'currency', 'status', 'key_code', 'supplier_used',
        'delivery_request_id', 'retry_count', 'failure_reason',
        'delivered_at', 'failed_at', 'refunded_at'
    ];
    
    const STATUS_PENDING = 'pending';
    const STATUS_DELIVERING = 'delivering';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_FAILED = 'failed';
    const STATUS_REFUNDED = 'refunded';
    
    protected $casts = [
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];
    
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
    
    public function product()
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }
    
    public function refund()
    {
        return $this->hasOne(Refund::class, 'order_item_id', 'id');
    }
    
    public function isFinal(): bool
    {
        return in_array($this->status, [
            self::STATUS_DELIVERED,
            self::STATUS_REFUNDED,
        ]);
    }
}
