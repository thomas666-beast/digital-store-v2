<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    protected $primaryKey = 'event_id';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'event_id', 'order_id', 'status', 'payload'
    ];
    
    protected $casts = [
        'payload' => 'array',
    ];
    
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}
