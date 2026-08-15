<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

interface IncrementalWorkLedgerInterface
{
    /**
     * @param list<int> $productIds
     */
    public function recordProductChanges(array $productIds): void;

    public function claimDueWork(int $productId): ?IncrementalWorkClaimInterface;

    public function complete(IncrementalWorkClaimInterface $claim): bool;

    public function recordRetry(IncrementalWorkClaimInterface $claim, string $errorCode, int $delaySeconds): bool;

    public function recordTerminal(IncrementalWorkClaimInterface $claim, string $errorCode): bool;

    public function recoverExpiredLeases(int $limit): int;

    /**
     * @return list<int>
     */
    public function dueProductIds(int $limit): array;

    /**
     * @return IncrementalWorkClaimInterface|null queued generation marker
     */
    public function markQueuedForWakeup(int $productId, int $visibilityTimeoutSeconds): ?IncrementalWorkClaimInterface;

    public function releaseQueuedWakeup(IncrementalWorkClaimInterface $claim): bool;
}
