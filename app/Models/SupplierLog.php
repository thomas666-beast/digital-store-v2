<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierLog extends Model
{    
    protected $fillable = [
        'request_id',
        'order_id',
        'sku',
        'supplier',
        'status',
        'key_code',
        'attempt',
        'error_message',
        'response_time',
    ];
    
    protected $casts = [
        'response_time' => 'integer',
        'attempt' => 'integer',
    ];
    
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}
