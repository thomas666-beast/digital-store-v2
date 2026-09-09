<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $primaryKey = 'sku';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'sku', 'name', 'type', 'price', 'currency', 
        'image', 'stock', 'sales_count', 'rating'
    ];
    
    public function keys()
    {
        return $this->hasMany(Key::class, 'sku', 'sku');
    }
    
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'sku', 'sku');
    }
    
    public function availableKeys()
    {
        return $this->keys()->where('status', 'available');
    }
}
