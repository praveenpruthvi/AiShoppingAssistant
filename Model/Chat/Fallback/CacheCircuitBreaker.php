<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Fallback;

use Aavirbhava\AiShoppingAssistant\Api\Chat\CircuitBreakerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Magento\Framework\App\CacheInterface;

/**
 * Circuit breaker backed by Magento's generic application cache.
 *
 * Chosen over a dedicated database table (the pattern used for durable
 * indexing state elsewhere in this module, e.g. the rebuild fence and
 * incremental work ledger) because a circuit breaker's state is a simple
 * counter-with-TTL — exactly what a cache entry already models — and a new
 * schema/migration for that would be disproportionate to this task. This
 * is a first cut: cache read-modify-write is not atomic, so concurrent
 * requests failing at the same instant could under-count toward the
 * threshold. Acceptable here since a missed trip just means one extra
 * provider attempt, not a safety issue — the eventual next failure trips
 * it.
 */
final class CacheCircuitBreaker implements CircuitBreakerInterface
{
    private const CACHE_ID_PREFIX = 'aavirbhava_ai_circuit_breaker_';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock
    ) {
    }

    public function isOpen(int $storeId, string $providerRole): bool
    {
        $state = $this->readState($storeId, $providerRole);

        return $state['opened_until'] !== null && $state['opened_until'] > $this->now();
    }

    public function recordFailure(int $storeId, string $providerRole, int $failureThreshold, int $cooldownSeconds): void
    {
        $state = $this->readState($storeId, $providerRole);
        $failures = $state['failures'] + 1;

        $openedUntil = $failures >= max(1, $failureThreshold)
            ? $this->now() + max(1, $cooldownSeconds)
            : null;

        $this->writeState($storeId, $providerRole, $failures, $openedUntil, max(1, $cooldownSeconds));
    }

    public function recordSuccess(int $storeId, string $providerRole): void
    {
        $this->cache->remove($this->cacheId($storeId, $providerRole));
    }

    /**
     * @return array{failures: int, opened_until: int|null}
     */
    private function readState(int $storeId, string $providerRole): array
    {
        $raw = $this->cache->load($this->cacheId($storeId, $providerRole));

        if (!is_string($raw) || $raw === '') {
            return ['failures' => 0, 'opened_until' => null];
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['failures' => 0, 'opened_until' => null];
        }

        if (!is_array($decoded) || !isset($decoded['failures']) || !is_int($decoded['failures'])) {
            return ['failures' => 0, 'opened_until' => null];
        }

        $openedUntil = $decoded['opened_until'] ?? null;

        return [
            'failures' => $decoded['failures'],
            'opened_until' => is_int($openedUntil) ? $openedUntil : null,
        ];
    }

    private function writeState(int $storeId, string $providerRole, int $failures, ?int $openedUntil, int $lifeTimeSeconds): void
    {
        $data = json_encode(['failures' => $failures, 'opened_until' => $openedUntil], JSON_THROW_ON_ERROR);

        // Life time bounds how long a stale failure count can linger once
        // nothing records a success; always at least the cooldown so an
        // open breaker doesn't expire from the cache before its cooldown
        // does.
        $this->cache->save($data, $this->cacheId($storeId, $providerRole), [], max($lifeTimeSeconds, 300));
    }

    private function cacheId(int $storeId, string $providerRole): string
    {
        return self::CACHE_ID_PREFIX . $storeId . '_' . $providerRole;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
