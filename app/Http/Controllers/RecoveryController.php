<?php

namespace App\Http\Controllers;

use App\Services\EventService;
use Illuminate\Http\Request;

class RecoveryController extends Controller
{
    protected EventService $eventService;
    
    public function __construct(EventService $eventService)
    {
        $this->eventService = $eventService;
    }

    /**
     * GET /api/recovery/order/{orderId}
     * Reconstruct order state at current time
     */
    public function reconstructOrder(string $orderId)
    {
        $state = $this->eventService->reconstructOrder($orderId);
        return response()->json($state);
    }

    /**
     * POST /api/recovery/order/{orderId}/at-time
     * Reconstruct order state at specific time
     */
    public function reconstructOrderAtTime(Request $request, string $orderId)
    {
        $request->validate([
            'timestamp' => 'required|date',
        ]);
        
        $timestamp = new \DateTime($request->timestamp);
        $state = $this->eventService->reconstructOrder($orderId, $timestamp);
        return response()->json($state);
    }

    /**
     * GET /api/recovery/balance/{orderId}/at-time
     * Get financial balance at specific time
     */
    public function getBalanceAtTime(Request $request, string $orderId)
    {
        $request->validate([
            'timestamp' => 'required|date',
        ]);
        
        $timestamp = new \DateTime($request->timestamp);
        $balance = $this->eventService->getBalanceAtTime($orderId, $timestamp);
        
        return response()->json([
            'order_id' => $orderId,
            'timestamp' => $timestamp->format(\DateTime::ISO8601),
            'balance' => $balance,
        ]);
    }

    /**
     * GET /api/recovery/summary
     * Get period summary
     */
    public function getPeriodSummary(Request $request)
    {
        $request->validate([
            'period' => 'required|in:daily,weekly,monthly',
            'date' => 'required|date',
        ]);
        
        $periodType = 'periodic_' . $request->period;
        $date = new \DateTime($request->date);
        
        $summary = $this->eventService->generatePeriodSummary($periodType, $date);
        return response()->json($summary);
    }

    /**
     * POST /api/recovery/snapshot/{orderId}
     * Take a snapshot of current state
     */
    public function takeSnapshot(string $orderId)
    {
        $this->eventService->takeSnapshot($orderId);
        return response()->json([
            'message' => 'Snapshot taken successfully',
            'order_id' => $orderId,
        ]);
    }
}
