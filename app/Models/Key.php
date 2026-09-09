<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Key extends Model
{
    protected $fillable = [
        'code', 'sku', 'status', 'order_id', 'used_at'
    ];
    
    protected $casts = [
        'used_at' => 'datetime',
    ];
    
    public function product()
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }
    
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}
