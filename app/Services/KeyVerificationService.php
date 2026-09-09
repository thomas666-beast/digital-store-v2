<?php

namespace App\Services;

use App\Models\Key;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class KeyVerificationService
{
    /**
     * Verify a key is valid and uniquely assigned
     */
    public function verifyKey(Key $key, OrderItem $item): array
    {
        $result = [
            'is_valid' => false,
            'is_unique' => false,
            'issues' => [],
            'resolution' => null,
        ];

        // 1. Check if key exists in our database
        if (!$key) {
            $result['issues'][] = 'Key not found in our database';
            return $result;
        }

        // 2. Check if key is already used by another order
        $existingUsage = Key::where('code', $key->code)
            ->where('order_id', '!=', $item->order_id)
            ->where('status', 'used')
            ->first();

        if ($existingUsage) {
            $result['issues'][] = 'Key already used by order: ' . $existingUsage->order_id;
            return $result;
        }

        // 3. Check if key is assigned to this order
        if ($key->order_id !== $item->order_id) {
            $result['issues'][] = 'Key not assigned to this order';
            return $result;
        }

        // 4. Check if key status is 'used'
        if ($key->status !== 'used') {
            $result['issues'][] = 'Key status is not "used"';
            return $result;
        }

        // All checks passed
        $result['is_valid'] = true;
        $result['is_unique'] = true;

        return $result;
    }

    /**
     * Resolve duplicate key issues automatically
     */
    public function resolveDuplicateKey(Key $key, array $verificationResult): array
    {
        $resolution = [
            'action' => null,
            'message' => null,
            'resolved' => false,
        ];

        if (empty($verificationResult['issues'])) {
            return $resolution;
        }

        foreach ($verificationResult['issues'] as $issue) {
            if (str_contains($issue, 'already used by order')) {
                // Key is duplicated - we need to find a replacement
                $replacement = $this->findReplacementKey($key->sku, $key->order_id);
                
                if ($replacement) {
                    $resolution['action'] = 'replaced';
                    $resolution['message'] = 'Key replaced with: ' . $replacement->code;
                    $resolution['resolved'] = true;
                    
                    // Log the replacement
                    Log::warning('Duplicate key replaced', [
                        'old_key' => $key->code,
                        'new_key' => $replacement->code,
                        'order_id' => $key->order_id,
                    ]);
                } else {
                    $resolution['action'] = 'pending_manual';
                    $resolution['message'] = 'No replacement key available';
                    $resolution['resolved'] = false;
                }
            }
        }

        return $resolution;
    }

    /**
     * Find a replacement key for an order
     */
    protected function findReplacementKey(string $sku, string $orderId): ?Key
    {
        return Key::where('sku', $sku)
            ->where('status', 'available')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Verify supplier response consistency
     */
    public function verifySupplierResponse(array $response, OrderItem $item): array
    {
        $verification = [
            'consistent' => false,
            'issues' => [],
            'recommended_action' => null,
        ];

        // Check if key from supplier matches what we have
        if (isset($response['code'])) {
            $key = Key::where('code', $response['code'])->first();
            
            if (!$key) {
                $verification['issues'][] = 'Supplier returned unknown key';
                $verification['recommended_action'] = 'reject_and_retry';
                return $verification;
            }

            if ($key->order_id !== $item->order_id) {
                $verification['issues'][] = 'Supplier returned key assigned to different order';
                $verification['recommended_action'] = 'reject_and_retry';
                return $verification;
            }
        }

        // Check if response status matches actual state
        if (isset($response['status'])) {
            if ($response['status'] === 'error' && $item->key_code) {
                // Supplier says error but we have a key - it was actually issued
                $verification['issues'][] = 'Supplier error but key was issued';
                $verification['recommended_action'] = 'keep_key';
                $verification['consistent'] = true;
                return $verification;
            }
        }

        $verification['consistent'] = true;
        return $verification;
    }
}
