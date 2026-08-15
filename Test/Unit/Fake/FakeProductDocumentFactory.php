<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Fake;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\CategoryReference;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductDocument;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductDocumentSchema;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\SearchableAttribute;

/**
 * Builds normalized ProductDocument fixtures for indexing tests.
 */
final class FakeProductDocumentFactory
{
    public function make(int $storeId = 1, int $entityId = 42, string $sku = 'SKU-42'): ProductDocument
    {
        return new ProductDocument(
            ProductDocumentSchema::VERSION,
            $storeId . '_' . $entityId,
            $entityId,
            $sku,
            $storeId,
            [1, 2],
            'simple',
            'Test Product',
            'Short',
            'Long description',
            true,
            4,
            [new CategoryReference(7, 'Shoes', 'Root / Men / Shoes')],
            [new SearchableAttribute('color', 'Color', ['blue'])],
            'Test Product Shoes blue',
            str_repeat('a', 64),
            str_repeat('b', 64),
            '2026-01-01T00:00:00+00:00'
        );
    }
}
