<?php

namespace App\Services;

use App\Models\Key;
use App\Models\SupplierLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupplierService
{
    protected KeyVerificationService $verificationService;
    protected array $supplierCache = [];

    public function __construct(KeyVerificationService $verificationService)
    {
        $this->verificationService = $verificationService;
    }

    /**
     * Issue a key from supplier with verification
     */
    public function issue(string $requestId, string $sku, string $orderId, int $timeout, int $maxRetries, float $backoffBase): array
    {
        // Check cache for idempotency
        $cachedResult = Cache::get("supplier:{$requestId}");
        if ($cachedResult) {
            Log::info('Supplier: Returning cached result', ['request_id' => $requestId]);
            
            // Verify cached result is still valid
            $validated = $this->validateSupplierResult($cachedResult, $orderId, $sku);
            if ($validated['valid']) {
                return $cachedResult;
            }
            
            // Cached result is invalid - force re-issue
            Log::warning('Supplier: Cached result invalid, re-issuing', [
                'request_id' => $requestId,
                'reason' => $validated['reason'],
            ]);
            Cache::forget("supplier:{$requestId}");
        }

        // Simulate supplier call with various failure modes
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxRetries) {
            $attempt++;
            
            try {
                // Simulate supplier behavior
                $result = $this->simulateSupplierCall($requestId, $sku, $orderId, $timeout);
                
                // Verify the result
                $validated = $this->validateSupplierResult($result, $orderId, $sku);
                
                if ($validated['valid']) {
                    // Store in cache for idempotency
                    Cache::put("supplier:{$requestId}", $result, 3600);
                    
                    // Log success
                    $this->logSupplierAttempt($requestId, $orderId, $sku, $result, $attempt, true);
                    
                    return $result;
                }
                
                // Result is invalid - log and retry if appropriate
                Log::warning('Supplier: Invalid result', [
                    'request_id' => $requestId,
                    'reason' => $validated['reason'],
                    'attempt' => $attempt,
                ]);
                
                // If the result indicates a key was issued but invalid, we need to handle it
                if ($validated['action'] === 'recover_key') {
                    return $this->recoverIssuedKey($requestId, $orderId, $sku);
                }
                
                // If the result is a timeout, don't retry (timeout != failure)
                if ($result['status'] === 'timeout') {
                    Log::warning('Supplier: Timeout - key may have been issued', [
                        'request_id' => $requestId,
                    ]);
                    return $result;
                }
                
                $lastError = $result['reason'] ?? 'unknown_error';
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::error('Supplier: Exception', [
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Exponential backoff
            if ($attempt < $maxRetries) {
                $delay = $backoffBase * pow(2, $attempt - 1);
                sleep($delay);
            }
        }

        // All retries exhausted
        return [
            'status' => 'error',
            'reason' => $lastError ?? 'max_retries_exceeded',
            'request_id' => $requestId,
        ];
    }

    /**
     * Simulate supplier call with various failure modes
     */
    protected function simulateSupplierCall(string $requestId, string $sku, string $orderId, int $timeout): array
    {
        // Simulate random delay
        usleep(rand(100, 500) * 1000);

        // Random failure modes
        $random = mt_rand(1, 100);

        // 10% chance of timeout (supplier may have issued key)
        if ($random <= 10) {
            // 30% chance key was actually issued before timeout
            if (mt_rand(1, 100) <= 30) {
                $key = $this->getKey($sku, $orderId);
                if ($key) {
                    return [
                        'status' => 'timeout_but_issued',
                        'code' => $key->code,
                        'request_id' => $requestId,
                    ];
                }
            }
            sleep($timeout + 1);
            return [
                'status' => 'timeout',
                'request_id' => $requestId,
            ];
        }

        // 15% chance of returning error but actually issuing
        if ($random > 10 && $random <= 25) {
            $key = $this->getKey($sku, $orderId);
            if ($key) {
                return [
                    'status' => 'error_but_issued',
                    'code' => $key->code,
                    'reason' => 'supplier_error',
                    'request_id' => $requestId,
                ];
            }
            return [
                'status' => 'error',
                'reason' => 'out_of_stock',
                'request_id' => $requestId,
            ];
        }

        // 10% chance of returning a duplicate key (someone else's code)
        if ($random > 25 && $random <= 35) {
            $usedKey = Key::where('status', 'used')->orderBy('id', 'desc')->first();
            if ($usedKey) {
                return [
                    'status' => 'duplicate_key',
                    'code' => $usedKey->code,
                    'reason' => 'duplicate_key',
                    'request_id' => $requestId,
                ];
            }
        }

        // 10% chance of returning invalid key
        if ($random > 35 && $random <= 45) {
            return [
                'status' => 'invalid_key',
                'code' => 'INVALID-' . Str::random(8),  // Now works with the import
                'reason' => 'invalid_key',
                'request_id' => $requestId,
            ];
        }

        // Normal success path
        $key = $this->getKey($sku, $orderId);
        if (!$key) {
            return [
                'status' => 'error',
                'reason' => 'out_of_stock',
                'request_id' => $requestId,
            ];
        }

        return [
            'status' => 'success',
            'code' => $key->code,
            'request_id' => $requestId,
        ];
    }

    /**
     * Validate supplier result
     */
    protected function validateSupplierResult(array $result, string $orderId, string $sku): array
    {
        $valid = false;
        $reason = null;
        $action = null;

        // Success with valid key
        if ($result['status'] === 'success' && isset($result['code'])) {
            $key = Key::where('code', $result['code'])->first();
            
            if ($key && $key->status === 'used' && $key->order_id === $orderId) {
                $valid = true;
            } elseif ($key && $key->status === 'used' && $key->order_id !== $orderId) {
                $reason = 'Key assigned to different order';
                $action = 'recover_key';
            } else {
                $reason = 'Key invalid or not assigned';
                $action = 'retry';
            }
            return ['valid' => $valid, 'reason' => $reason, 'action' => $action];
        }

        // Error but key was actually issued
        if ($result['status'] === 'error_but_issued' && isset($result['code'])) {
            $key = Key::where('code', $result['code'])->first();
            if ($key && $key->status === 'used' && $key->order_id === $orderId) {
                $valid = true;
                $action = 'keep_key';
            } else {
                $reason = 'Issued key invalid';
                $action = 'retry';
            }
            return ['valid' => $valid, 'reason' => $reason, 'action' => $action];
        }

        // Timeout - key may or may not have been issued
        if ($result['status'] === 'timeout' || $result['status'] === 'timeout_but_issued') {
            // Check if a key was actually issued
            $existingKey = Key::where('order_id', $orderId)->where('status', 'used')->first();
            if ($existingKey) {
                $valid = true;
                return ['valid' => $valid, 'reason' => null, 'action' => 'keep_key'];
            }
            return ['valid' => false, 'reason' => 'timeout', 'action' => 'retry'];
        }

        // Duplicate key
        if ($result['status'] === 'duplicate_key' && isset($result['code'])) {
            $key = Key::where('code', $result['code'])->first();
            if ($key && $key->status === 'used' && $key->order_id !== $orderId) {
                $reason = 'Duplicate key from supplier';
                $action = 'recover_key';
                return ['valid' => false, 'reason' => $reason, 'action' => $action];
            }
        }

        // Invalid key
        if ($result['status'] === 'invalid_key') {
            return ['valid' => false, 'reason' => 'invalid_key', 'action' => 'retry'];
        }

        // Regular error
        if ($result['status'] === 'error') {
            return ['valid' => false, 'reason' => $result['reason'] ?? 'supplier_error', 'action' => 'retry'];
        }

        return ['valid' => false, 'reason' => 'unknown_result', 'action' => 'retry'];
    }

    /**
     * Recover a key that was issued but is invalid
     */
    public function recoverIssuedKey(string $requestId, string $orderId, string $sku): array
    {
        Log::warning('Supplier: Recovering issued key', [
            'order_id' => $orderId,
            'request_id' => $requestId,
        ]);

        // Check if we already have a key for this order
        $existingKey = Key::where('order_id', $orderId)->where('status', 'used')->first();
        if ($existingKey) {
            return [
                'status' => 'success',
                'code' => $existingKey->code,
                'request_id' => $requestId,
                'recovered' => true,
            ];
        }

        // Find an available key
        $key = Key::where('sku', $sku)
            ->where('status', 'available')
            ->lockForUpdate()
            ->first();
            
        if ($key) {
            $key->update([
                'status' => 'used',
                'order_id' => $orderId,
                'used_at' => now(),
                'used_count' => ($key->used_count ?? 0) + 1,
            ]);
            
            return [
                'status' => 'success',
                'code' => $key->code,
                'request_id' => $requestId,
                'recovered' => true,
            ];
        }

        return [
            'status' => 'error',
            'reason' => 'no_key_available',
            'request_id' => $requestId,
        ];
    }

    /**
     * Get and reserve a key
     */
    protected function getKey(string $sku, string $orderId): ?Key
    {
        $key = Key::where('sku', $sku)
            ->where('status', 'available')
            ->lockForUpdate()
            ->first();

        if (!$key) {
            return null;
        }

        $key->update([
            'status' => 'used',
            'order_id' => $orderId,
            'used_at' => now(),
            'used_count' => ($key->used_count ?? 0) + 1,
        ]);

        return $key;
    }

    /**
     * Log supplier attempt
     */
    protected function logSupplierAttempt(string $requestId, string $orderId, string $sku, array $result, int $attempt, bool $success): void
    {
        SupplierLog::create([
            'request_id' => $requestId,
            'order_id' => $orderId,
            'sku' => $sku,
            'supplier' => 'mock',
            'status' => $success ? 'success' : 'failed',
            'key_code' => $result['code'] ?? null,
            'attempt' => $attempt,
            'error_message' => $result['reason'] ?? null,
            'response_time' => rand(100, 1000),
        ]);
    }
}
