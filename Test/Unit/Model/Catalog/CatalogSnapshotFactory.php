<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\CategoryReference;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductSnapshot;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\SearchableAttribute;
use Magento\Catalog\Model\Product\Visibility;

final class CatalogSnapshotFactory
{
    /**
     * Builds a valid eligible product snapshot. Pass overrides for the keys
     * matching ProductSnapshot constructor parameter names.
     *
     * @param array<string, mixed> $overrides
     */
    public function create(array $overrides = []): ProductSnapshotInterface
    {
        $data = array_replace([
            'entityId' => 42,
            'sku' => 'SKU-42',
            'storeId' => 1,
            'websiteIds' => [2, 1],
            'productType' => 'simple',
            'name' => 'Test Product',
            'shortDescription' => 'A short description.',
            'longDescription' => 'A long description.',
            'isEnabled' => true,
            'visibility' => Visibility::VISIBILITY_BOTH,
            'categories' => [
                new CategoryReference(7, 'Shoes', 'Root Catalog / Shoes'),
            ],
            'attributes' => [
                new SearchableAttribute('material', 'Material', ['leather']),
            ],
            'updatedAt' => '2026-01-01T00:00:00+00:00',
            'ratingAverage' => 4.5,
            'reviewCount' => 12,
            'catalogRatingAverage' => 3.5,
        ], $overrides);

        return new ProductSnapshot(
            $data['entityId'],
            $data['sku'],
            $data['storeId'],
            $data['websiteIds'],
            $data['productType'],
            $data['name'],
            $data['shortDescription'],
            $data['longDescription'],
            $data['isEnabled'],
            $data['visibility'],
            $data['categories'],
            $data['attributes'],
            $data['updatedAt'],
            $data['ratingAverage'],
            $data['reviewCount'],
            $data['catalogRatingAverage']
        );
    }
}
