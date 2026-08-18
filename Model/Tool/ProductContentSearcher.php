<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;

/**
 * Keyword (LIKE-based) product candidate search — deliberately NOT the
 * assistant's own OpenSearch/embedding retrieval path (HybridRetrievalService,
 * used by search_products): that path always issues a vector query
 * alongside the keyword one, which requires a live embedding provider
 * call this tool must not make, and it only ever sees products already
 * eligible for and present in the assistant's own index. This searcher
 * uses Magento's own core product/category collections directly instead,
 * so search_store_content works in any install with this module active,
 * independent of whether the assistant's own indexing pipeline has ever
 * run. Only candidate SKUs are returned — every fact shown to the model
 * still comes from LiveRevalidationServiceInterface, never from this
 * collection scan, matching this module's "raw catalogue data is never
 * ground truth" discipline.
 */
final class ProductContentSearcher
{
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ContentSearchTextUtility $textUtility
    ) {
    }

    /**
     * @return list<string> candidate SKUs, deduplicated, capped at $limit
     */
    public function searchSkus(int $storeId, string $query, int $limit): array
    {
        $escaped = $this->textUtility->escapeLike($query);

        /** @var array<string, true> $skus */
        $skus = [];

        foreach ($this->byNameDescriptionOrSku($storeId, $escaped, $limit) as $sku) {
            $skus[$sku] = true;
        }

        if (count($skus) < $limit) {
            foreach ($this->byCategoryName($storeId, $escaped, $limit) as $sku) {
                $skus[$sku] = true;
            }
        }

        return array_slice(array_keys($skus), 0, $limit);
    }

    /**
     * @return list<string>
     */
    private function byNameDescriptionOrSku(int $storeId, string $escapedQuery, int $limit): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['sku']);
        $collection->addStoreFilter($storeId);
        // The third argument (join type) matters here and must stay 'left':
        // addAttributeToFilter()'s default 'inner' join type turns every
        // attribute in this OR-array into a row that MUST exist for a
        // product to appear at all — description/short_description are
        // optional attributes, so any product without one (common in real
        // catalogues) would be silently excluded even when it matches on
        // sku/name, collapsing the whole OR to zero results. Confirmed
        // live against this store's own sample data before fixing.
        $collection->addAttributeToFilter(
            [
                ['attribute' => 'sku', 'like' => "%{$escapedQuery}%"],
                ['attribute' => 'name', 'like' => "%{$escapedQuery}%"],
                ['attribute' => 'description', 'like' => "%{$escapedQuery}%"],
                ['attribute' => 'short_description', 'like' => "%{$escapedQuery}%"],
            ],
            null,
            'left'
        );
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        return $this->skusOf($collection);
    }

    /**
     * @return list<string>
     */
    private function byCategoryName(int $storeId, string $escapedQuery, int $limit): array
    {
        $categoryCollection = $this->categoryCollectionFactory->create();
        $categoryCollection->addAttributeToSelect('name');
        $categoryCollection->addAttributeToFilter('name', ['like' => "%{$escapedQuery}%"]);
        $categoryCollection->setPageSize($limit);
        $categoryIds = $categoryCollection->getAllIds();

        if ($categoryIds === []) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['sku']);
        $collection->addStoreFilter($storeId);
        $collection->addCategoriesFilter(['in' => $categoryIds]);
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        return $this->skusOf($collection);
    }

    /**
     * @return list<string>
     */
    private function skusOf(iterable $collection): array
    {
        $skus = [];
        foreach ($collection as $product) {
            $skus[] = (string) $product->getSku();
        }

        return $skus;
    }
}
