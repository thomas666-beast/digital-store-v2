<?php

use App\Http\Controllers\MultiOrderController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\RecoveryController;
use Illuminate\Support\Facades\Route;

// Task 1 & 2: Multi-Item Orders
Route::post('/orders/multi', [MultiOrderController::class, 'createOrder']);
Route::get('/orders/multi/{orderId}', [MultiOrderController::class, 'getOrder']);
Route::post('/orders/multi/retry/{orderId}', [MultiOrderController::class, 'retryFailedItems']);
Route::post('/webhook/payment', [MultiOrderController::class, 'processPayment']);

// Task 3: Queue Management
Route::get('/queue/status', [QueueController::class, 'status']);
Route::post('/queue/process', [QueueController::class, 'process']);
Route::post('/queue/retry', [QueueController::class, 'retry']);
Route::get('/queue/rate-limit', [QueueController::class, 'rateLimit']);

// Task 4: Point-in-Time Recovery
Route::prefix('recovery')->group(function () {
    Route::get('/order/{orderId}', [RecoveryController::class, 'reconstructOrder']);
    Route::post('/order/{orderId}/at-time', [RecoveryController::class, 'reconstructOrderAtTime']);
    Route::get('/balance/{orderId}/at-time', [RecoveryController::class, 'getBalanceAtTime']);
    Route::get('/summary', [RecoveryController::class, 'getPeriodSummary']);
    Route::post('/snapshot/{orderId}', [RecoveryController::class, 'takeSnapshot']);
});
