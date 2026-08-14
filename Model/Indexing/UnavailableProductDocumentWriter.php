<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductDocumentWriterInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBackendUnavailableException;

/**
 * Default writer until an assistant-index backend (OpenSearch) is implemented.
 *
 * Indexing must never fail open: if at least one store requires indexing and no
 * backend exists, the writer refuses with a sanitized exception instead of
 * pretending documents were persisted. The index is never marked activated.
 *
 * abortRun() is safe and idempotent so failure cleanup always succeeds.
 */
final class UnavailableProductDocumentWriter implements ProductDocumentWriterInterface
{
    public function beginRun(RebuildRunContextInterface $context): void
    {
        throw new ProductIndexBackendUnavailableException();
    }

    public function beginStore(StoreScopeInterface $scope): void
    {
        throw new ProductIndexBackendUnavailableException();
    }

    public function writeBatch(array $documents): void
    {
        throw new ProductIndexBackendUnavailableException();
    }

    public function finishStore(): void
    {
        throw new ProductIndexBackendUnavailableException();
    }

    public function activateRun(): void
    {
        throw new ProductIndexBackendUnavailableException();
    }

    public function abortRun(): void
    {
        // Safe idempotent cleanup: nothing was ever written.
    }
}
