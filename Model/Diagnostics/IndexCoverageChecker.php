<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Diagnostics;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;

/**
 * Composes CatalogSkuProvider and IndexedSkuProvider into one
 * IndexCoverageReport per store (Task 23) — the orchestration seam
 * Console\Command\IndexCoverageCommand delegates to, kept separate from the
 * command itself so the comparison logic is unit-testable without a real
 * product collection or a real OpenSearch client.
 *
 * Not final so it stays mockable in IndexCoverageCommand's own unit
 * tests — see CatalogSkuProvider's docblock for the same rationale.
 */
class IndexCoverageChecker
{
    public function __construct(
        private readonly CatalogSkuProvider $catalogSkuProvider,
        private readonly IndexedSkuProvider $indexedSkuProvider
    ) {
    }

    public function check(StoreScopeInterface $scope): IndexCoverageReport
    {
        $catalogSkus = $this->catalogSkuProvider->salableVisibleEnabledSkus($scope);
        $indexedSkus = $this->indexedSkuProvider->indexedSkus($scope);

        return new IndexCoverageReport(
            $scope->storeId(),
            $scope->storeCode(),
            count($catalogSkus),
            $indexedSkus === null ? null : count($indexedSkus),
            $indexedSkus === null ? [] : array_values(array_diff($catalogSkus, $indexedSkus)),
            $indexedSkus === null ? [] : array_values(array_diff($indexedSkus, $catalogSkus))
        );
    }
}
