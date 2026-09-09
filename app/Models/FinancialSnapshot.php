<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialSnapshot extends Model
{    
    protected $fillable = [
        'order_id', 'balance', 'total_delivered', 'total_refunded',
        'snapshot_time', 'event_version'
    ];
    
    protected $casts = [
        'snapshot_time' => 'datetime',
    ];
    
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}
