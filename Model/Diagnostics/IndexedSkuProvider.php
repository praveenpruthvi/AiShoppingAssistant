<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Diagnostics;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\AssistantSearchClientInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexNamingServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface as Field;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * The real, currently-indexed SKU set for one store — the "actually
 * indexed" side of the index-coverage diagnostic (Console\Command\
 * IndexCoverageCommand, Task 23).
 *
 * Queries the store's live read alias directly (the same alias
 * HybridRetrievalService searches against), not is_enabled-filtered: every
 * document present already passed ProductIndexEligibilityPolicy's
 * enabled/visible gate at index time, so a plain per-store document count
 * is the right comparison. Capped at MAX_SCAN_SIZE per store — a plain
 * match-all query without OpenSearch's scroll/search_after API is
 * inherently bounded by index.max_result_window (10000 by default);
 * fine for a fast diagnostic against a real catalogue, not built to
 * reconcile a store with more SKUs than that.
 *
 * Not final (unlike most of this module's classes) so it stays mockable
 * in IndexCoverageChecker's own unit tests — see CatalogSkuProvider's
 * docblock for the same rationale.
 */
class IndexedSkuProvider
{
    public const MAX_SCAN_SIZE = 10000;

    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly IndexNamingServiceInterface $indexNamingService,
        private readonly AssistantSearchClientInterface $searchClient
    ) {
    }

    /**
     * Null when this store has no assistant index alias yet (never
     * indexed) — distinct from an empty array, which would mean the alias
     * exists but is genuinely empty.
     *
     * @return list<string>|null
     *
     * @throws ProductIndexingException when the backend is unreachable or the
     *     query fails for a reason other than a missing alias
     */
    public function indexedSkus(StoreScopeInterface $scope): ?array
    {
        $prefix = $this->configurationReader->readIndexing($scope->storeId())->indexPrefix();
        $alias = $this->indexNamingService->readAlias($prefix, $scope);

        if (!$this->searchClient->aliasExists($alias)) {
            return null;
        }

        $hits = $this->searchClient->search($alias, [
            'size' => self::MAX_SCAN_SIZE,
            '_source' => [Field::FIELD_SKU],
            'query' => [
                'term' => [Field::FIELD_STORE_ID => (string) $scope->storeId()],
            ],
        ]);

        $skus = [];
        foreach ($hits as $hit) {
            $sku = $hit['_source'][Field::FIELD_SKU] ?? null;
            if (is_string($sku) && $sku !== '') {
                $skus[] = $sku;
            }
        }

        return array_values(array_unique($skus));
    }
}
