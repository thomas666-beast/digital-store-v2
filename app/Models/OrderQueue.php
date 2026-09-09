<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderQueue extends Model
{    
    protected $fillable = [
        'order_id',
        'sku',
        'priority',
        'status',
        'attempts',
        'error_message',
        'queued_at',
        'started_at',
        'completed_at',
    ];
    
    protected $casts = [
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'priority' => 'integer',
        'attempts' => 'integer',
    ];
    
    const STATUS_QUEUED = 'queued';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}
