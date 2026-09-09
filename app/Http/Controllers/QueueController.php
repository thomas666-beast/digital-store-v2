<?php

namespace App\Http\Controllers;

use App\Services\OrderQueueService;
use App\Services\RateLimiterService;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    protected OrderQueueService $queueService;
    protected RateLimiterService $rateLimiter;

    public function __construct(OrderQueueService $queueService, RateLimiterService $rateLimiter)
    {
        $this->queueService = $queueService;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * GET /api/queue/status
     * Get queue status
     */
    public function status()
    {
        try {
            $status = $this->queueService->getQueueStatus();
            return response()->json($status);
        } catch (\Exception $e) {
            \Log::error('Queue status error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to get queue status',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/queue/process
     * Manually trigger queue processing
     */
    public function process(Request $request)
    {
        $batchSize = $request->input('batch_size', 5);
        $result = $this->queueService->processNextBatch($batchSize);
        return response()->json($result);
    }

    /**
     * POST /api/queue/retry
     * Retry failed items
     */
    public function retry()
    {
        $result = $this->queueService->retryFailed();
        return response()->json([
            'retried' => $result,
            'count' => count($result),
        ]);
    }

    /**
     * GET /api/queue/rate-limit
     * Get rate limit status
     */
    public function rateLimit()
    {
        return response()->json($this->rateLimiter->getStatus());
    }
}
