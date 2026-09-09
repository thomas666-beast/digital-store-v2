<?php

namespace App\Services;

use App\Models\SupplierRateLimit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RateLimiterService
{
    protected string $supplier;
    protected int $maxRequestsPerMinute;
    protected string $cacheKey;

    public function __construct(string $supplier = 'mock')
    {
        $this->supplier = $supplier;
        $this->cacheKey = "rate_limit:{$supplier}";
        $this->loadConfiguration();
    }

    protected function loadConfiguration(): void
    {
        $config = SupplierRateLimit::firstOrCreate(
            ['supplier' => $this->supplier],
            [
                'max_requests_per_minute' => config('supplier.rate_limit', 10),
                'current_requests' => 0,
            ]
        );
        
        $this->maxRequestsPerMinute = $config->max_requests_per_minute;
    }

    public function reset(): void
    {
        Cache::forget("{$this->cacheKey}:count");
        Cache::forget("{$this->cacheKey}:reset_at");
        
        SupplierRateLimit::updateOrCreate(
            ['supplier' => $this->supplier],
            [
                'current_requests' => 0,
                'reset_at' => null,
            ]
        );
    }

    /**
     * Check if request is allowed - IMMEDIATE RETURN, NO SLEEP
     */
    public function allowRequest(): bool
    {
        $current = $this->getCurrentRequests();
        $resetAt = $this->getResetTime();
        
        // If reset time passed, reset counter
        if ($resetAt && now()->greaterThan($resetAt)) {
            $this->resetCounter();
            return true;
        }
        
        return $current < $this->maxRequestsPerMinute;
    }

    public function getAvailableCapacity(): int
    {
        $current = $this->getCurrentRequests();
        return max(0, $this->maxRequestsPerMinute - $current);
    }

    public function getCurrentRequests(): int
    {
        return Cache::get("{$this->cacheKey}:count", 0);
    }

    public function getResetTime(): ?\DateTime
    {
        $resetAt = Cache::get("{$this->cacheKey}:reset_at");
        return $resetAt ? new \DateTime($resetAt) : null;
    }

    public function increment(): void
    {
        $count = $this->getCurrentRequests() + 1;
        Cache::put("{$this->cacheKey}:count", $count, 60);
        
        if (!$this->getResetTime()) {
            Cache::put("{$this->cacheKey}:reset_at", now()->addMinute()->toISOString(), 60);
        }
        
        $this->updateDatabase($count);
        
        Log::info('Rate limit incremented', [
            'supplier' => $this->supplier,
            'current' => $count,
            'max' => $this->maxRequestsPerMinute,
        ]);
    }

    public function resetCounter(): void
    {
        Cache::forget("{$this->cacheKey}:count");
        Cache::forget("{$this->cacheKey}:reset_at");
        $this->updateDatabase(0);
    }

    protected function updateDatabase(int $count): void
    {
        SupplierRateLimit::updateOrCreate(
            ['supplier' => $this->supplier],
            [
                'current_requests' => $count,
                'reset_at' => $this->getResetTime(),
            ]
        );
    }

    public function getStatus(): array
    {
        $resetAt = $this->getResetTime();

        return [
            'supplier' => $this->supplier,
            'max_requests_per_minute' => $this->maxRequestsPerMinute,
            'current_requests' => $this->getCurrentRequests(),
            'available_capacity' => $this->getAvailableCapacity(),
            'reset_at' => $resetAt ? $resetAt->format(\DateTime::ISO8601) : null,
            'is_limited' => $this->getCurrentRequests() >= $this->maxRequestsPerMinute,
        ];
    }
}
