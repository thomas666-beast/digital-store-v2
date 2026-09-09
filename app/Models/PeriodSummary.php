<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodSummary extends Model
{    
    protected $fillable = [
        'period_type', 'period_date', 'total_orders', 'total_revenue',
        'total_refunds', 'net_revenue', 'delivered_count', 'failed_count',
        'refunded_count', 'top_products', 'summary'
    ];
    
    protected $casts = [
        'period_date' => 'date',
        'top_products' => 'array',
        'summary' => 'array',
    ];
    
    const PERIOD_DAILY = 'daily';
    const PERIOD_WEEKLY = 'weekly';
    const PERIOD_MONTHLY = 'monthly';
}
