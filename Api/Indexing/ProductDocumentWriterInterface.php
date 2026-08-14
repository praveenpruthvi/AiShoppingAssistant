<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;

/**
 * Two-phase, backend-neutral persistence target for normalized product documents.
 *
 * Lifecycle for one run:
 *
 *   beginRun(context) -> [ beginStore(scope) -> writeBatch(docs)* -> finishStore() ]* -> activateRun()
 *
 * Writes go to isolated, non-live targets during the run. The live assistant
 * index is never replaced until activateRun() is called as the distinct final
 * operation. A failed run is cleaned up with abortRun(), which is idempotent.
 *
 * Implementations receive only immutable ProductDocument DTOs. They never see
 * Magento Product models, API keys, LLM configuration, or prompt content. The
 * interface deliberately exposes no OpenSearch or storage-specific types.
 */
interface ProductDocumentWriterInterface
{
    /**
     * Opens a new rebuild run in a non-live target.
     *
     * @throws \Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException
     */
    public function beginRun(RebuildRunContextInterface $context): void;

    /**
     * Prepares the non-live target for one store scope.
     *
     * @throws \Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException
     */
    public function beginStore(StoreScopeInterface $scope): void;

    /**
     * Persists one batch of eligible documents for the current store scope.
     *
     * @param list<ProductDocumentInterface> $documents immutable documents, already validated
     *
     * @throws \Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException
     */
    public function writeBatch(array $documents): void;

    /**
     * Finalizes the current store scope. No new batches may follow.
     *
     * @throws \Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException
     */
    public function finishStore(): void;

    /**
     * Atomically promotes the run's non-live targets to the live index.
     *
     * Must be the final operation of a successful run and must never run after
     * a failure. Only an activated run may be considered indexed.
     *
     * @throws \Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException
     */
    public function activateRun(): void;

    /**
     * Safely discards the current run's non-live targets.
     *
     * Idempotent: calling it multiple times, or when no run is open, is safe.
     * Never throws.
     */
    public function abortRun(): void;
}
