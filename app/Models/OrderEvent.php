<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderEvent extends Model
{    
    protected $fillable = [
        'order_id', 'event_type', 'payload', 'snapshot',
        'event_time', 'version'
    ];
    
    protected $casts = [
        'payload' => 'array',
        'snapshot' => 'array',
        'event_time' => 'datetime',
    ];
    
    // Event Types
    const EVENT_ORDER_CREATED = 'order_created';
    const EVENT_PAYMENT_RECEIVED = 'payment_received';
    const EVENT_ITEM_DELIVERED = 'item_delivered';
    const EVENT_ITEM_FAILED = 'item_failed';
    const EVENT_REFUND_ISSUED = 'refund_issued';
    const EVENT_ORDER_COMPLETED = 'order_completed';
    const EVENT_ORDER_QUEUED = 'order_queued';
    const EVENT_ORDER_PROCESSED = 'order_processed';
    
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}
