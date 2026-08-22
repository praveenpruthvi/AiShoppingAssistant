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
     * Records a hard failure (Task 45's HardFailureClassifier — an
     * exhausted quota, an invalid/revoked key) — one confirmed to recur
     * identically on the very next request. Always opens the breaker on
     * this single occurrence, regardless of any configured
     * failure_threshold, and marks the open state as hard so
     * wasOpenedByHardFailure() can answer correctly for every later call
     * made while the breaker is still open — not just the one that
     * actually tripped it.
     */
    public function recordHardFailure(int $storeId, string $providerRole, int $cooldownSeconds): void;

    /**
     * True when the breaker is currently open BECAUSE OF a hard failure
     * (recordHardFailure()), false when it's open due to accumulated
     * transient failures (recordFailure()) or not open at all. Exists so
     * a caller that skips a provider entirely because its breaker is
     * already open — never re-attempting it, so no fresh exception of
     * either kind is available this call — can still tell a customer
     * "still confirmed down" rather than silently downgrading to "just a
     * transient hiccup" for every request made during the cooldown.
     */
    public function wasOpenedByHardFailure(int $storeId, string $providerRole): bool;

    /**
     * Resets the failure count and closes the breaker.
     */
    public function recordSuccess(int $storeId, string $providerRole): void;
}
