<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MoneyJournal extends Model
{    
    protected $fillable = [
        'order_id', 'event_type', 'amount', 'currency',
        'balance_before', 'balance_after', 'metadata'
    ];
    
    protected $casts = [
        'metadata' => 'array',
    ];
    
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}
