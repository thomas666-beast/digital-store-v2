<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Supplier Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration for supplier integrations including
    | timeouts, retry logic, and rate limiting.
    |
    */

    'timeout' => env('SUPPLIER_TIMEOUT', 5),
    'retry_attempts' => env('SUPPLIER_RETRY_ATTEMPTS', 3),
    'backoff_base' => env('SUPPLIER_BACKOFF_BASE', 1),
    'rate_limit' => env('SUPPLIER_RATE_LIMIT', 1000),
    
    /*
    |--------------------------------------------------------------------------
    | Supplier A Configuration
    |--------------------------------------------------------------------------
    |
    | Supplier A is the primary supplier with configurable error and timeout rates.
    | Higher values mean more frequent failures.
    |
    */
    'a' => [
        'error_rate' => env('SUPPLIER_A_ERROR_RATE', 0.3),
        'timeout_rate' => env('SUPPLIER_A_TIMEOUT_RATE', 0.2),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Supplier B Configuration
    |--------------------------------------------------------------------------
    |
    | Supplier B is the fallback supplier with lower error/timeout rates.
    |
    */
    'b' => [
        'error_rate' => env('SUPPLIER_B_ERROR_RATE', 0.1),
        'timeout_rate' => env('SUPPLIER_B_TIMEOUT_RATE', 0.05),
    ],
];
