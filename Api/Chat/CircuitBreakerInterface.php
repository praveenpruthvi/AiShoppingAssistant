<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Chat;

/**
 * Per-store, per-provider-role circuit breaker for chat generation.
 *
 * State persists across requests (a single request's retry attempts never
 * see enough failures on their own to matter) — implementations must use a
 * cross-process store, not an in-memory counter, since Magento serves each
 * request in its own process.
 */
interface CircuitBreakerInterface
{
    public const ROLE_PRIMARY = 'primary';
    public const ROLE_FALLBACK = 'fallback';

    /**
     * True when the breaker is open for this store/role and the caller
     * should skip straight past this provider without attempting it.
     */
    public function isOpen(int $storeId, string $providerRole): bool;

    /**
     * Records a transient-availability failure. Opens the breaker for
     * cooldownSeconds once failureThreshold consecutive failures accumulate.
     */
    public function recordFailure(int $storeId, string $providerRole, int $failureThreshold, int $cooldownSeconds): void;

    /**
     * Resets the failure count and closes the breaker.
     */
    public function recordSuccess(int $storeId, string $providerRole): void;
}
