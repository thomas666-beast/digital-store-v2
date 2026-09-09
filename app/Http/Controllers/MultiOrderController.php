<?php

namespace App\Http\Controllers;

use App\Services\MultiOrderService;
use Illuminate\Http\Request;

class MultiOrderController extends Controller
{
    protected MultiOrderService $multiOrderService;
    
    public function __construct(MultiOrderService $multiOrderService)
    {
        $this->multiOrderService = $multiOrderService;
    }
    
    public function createOrder(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string',
            'items.*.quantity' => 'sometimes|integer|min:1',
        ]);
        
        $result = $this->multiOrderService->createOrder($request->items);
        
        if (isset($result['error'])) {
            return response()->json($result, $result['code'] ?? 400);
        }
        
        return response()->json($result, 201);
    }
    
    public function getOrder(string $orderId)
    {
        $result = $this->multiOrderService->getOrder($orderId);
        
        if (!$result) {
            return response()->json(['error' => 'Order not found'], 404);
        }
        
        return response()->json($result);
    }
    
    public function retryFailedItems(Request $request, string $orderId)
    {
        $result = $this->multiOrderService->retryFailedItems($orderId);
        return response()->json([
            'order_id' => $orderId,
            'retried' => $result,
            'count' => count($result),
        ]);
    }
    
    public function processPayment(Request $request)
    {
        $request->validate([
            'event_id' => 'required|string',
            'order_id' => 'required|string',
            'status' => 'required|in:paid,failed',
            'amount' => 'required|integer',
            'currency' => 'required|string',
            'created_at' => 'required|string',
        ]);
        
        $result = $this->multiOrderService->processPayment($request->all());
        
        if (isset($result['error'])) {
            return response()->json($result, 400);
        }
        
        return response()->json($result);
    }
}
