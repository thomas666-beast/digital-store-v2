<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierRateLimit extends Model
{    
    protected $fillable = [
        'supplier',
        'max_requests_per_minute',
        'current_requests',
        'reset_at',
    ];
    
    protected $casts = [
        'reset_at' => 'datetime',
        'current_requests' => 'integer',
        'max_requests_per_minute' => 'integer',
    ];
}
