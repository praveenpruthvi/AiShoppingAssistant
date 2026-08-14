<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductDocument;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductDocumentSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductDocument::class)]
final class ProductDocumentTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function makeDocument(array $overrides = []): ProductDocument
    {
        $data = array_replace([
            'schemaVersion' => ProductDocumentSchema::VERSION,
            'documentId' => '1_42',
            'entityId' => 42,
            'sku' => 'SKU-42',
            'storeId' => 1,
            'websiteIds' => [1, 2],
            'productType' => 'simple',
            'name' => 'Test Product',
            'shortDescription' => '',
            'longDescription' => '',
            'isEnabled' => true,
            'visibility' => 4,
            'categories' => [],
            'attributes' => [],
            'searchableText' => 'Test Product',
            'embeddingContentHash' => str_repeat('a', 64),
            'completeDocumentHash' => str_repeat('b', 64),
            'updatedAt' => null,
        ], $overrides);

        return new ProductDocument(
            $data['schemaVersion'],
            $data['documentId'],
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
            $data['searchableText'],
            $data['embeddingContentHash'],
            $data['completeDocumentHash'],
            $data['updatedAt']
        );
    }

    public function testAcceptsValidDocument(): void
    {
        $document = $this->makeDocument();

        self::assertSame('1_42', $document->documentId());
        self::assertSame(1, $document->schemaVersion());
    }

    public function testRejectsInvalidSchemaVersion(): void
    {
        $this->expectException(CatalogException::class);

        $this->makeDocument(['schemaVersion' => 0]);
    }

    public function testRejectsEmptyDocumentId(): void
    {
        $this->expectException(CatalogException::class);

        $this->makeDocument(['documentId' => '']);
    }

    public function testRejectsNonPositiveEntityId(): void
    {
        $this->expectException(CatalogException::class);

        $this->makeDocument(['entityId' => 0]);
    }

    public function testRejectsEmptySku(): void
    {
        $this->expectException(CatalogException::class);

        $this->makeDocument(['sku' => '']);
    }

    public function testRejectsNonPositiveStoreId(): void
    {
        $this->expectException(CatalogException::class);

        $this->makeDocument(['storeId' => 0]);
    }

    public function testRejectsEmptyWebsiteList(): void
    {
        $this->expectException(CatalogException::class);

        $this->makeDocument(['websiteIds' => []]);
    }

    public function testRejectsNonPositiveWebsiteId(): void
    {
        $this->expectException(CatalogException::class);

        $this->makeDocument(['websiteIds' => [1, 0]]);
    }

    public function testRejectsEmptyProductType(): void
    {
        $this->expectException(CatalogException::class);

        $this->makeDocument(['productType' => '']);
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(CatalogException::class);

        $this->makeDocument(['name' => '']);
    }

    public function testRejectsEmptySearchableText(): void
    {
        $this->expectException(CatalogException::class);

        $this->makeDocument(['searchableText' => '']);
    }

    public function testRejectsMalformedHashes(): void
    {
        $this->expectException(CatalogException::class);

        $this->makeDocument(['embeddingContentHash' => 'ZZZ']);
    }

    public function testRejectsUppercaseHash(): void
    {
        $this->expectException(CatalogException::class);

        $this->makeDocument(['completeDocumentHash' => str_repeat('A', 64)]);
    }
}