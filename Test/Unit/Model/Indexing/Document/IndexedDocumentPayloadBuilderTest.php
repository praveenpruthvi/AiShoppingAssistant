<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Document;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingVector;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Document\IndexedDocumentPayloadBuilder;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Document\IndexedProductDocument;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeProductDocumentFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndexedDocumentPayloadBuilder::class)]
final class IndexedDocumentPayloadBuilderTest extends TestCase
{
    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const FINGERPRINT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private IndexedDocumentPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new IndexedDocumentPayloadBuilder();
    }

    public function testBuildsFlatMappingPayload(): void
    {
        $document = (new FakeProductDocumentFactory())->make();
        $vector = new EmbeddingVector([0.1, 0.2, 0.3, 0.4], 4);
        $indexed = new IndexedProductDocument($document, $vector, self::HASH, self::FINGERPRINT, '2026-01-01T00:00:00+00:00');

        $storage = $this->builder->build($indexed);
        $payload = $storage->source();

        self::assertSame('1_42', $storage->id());
        self::assertArrayNotHasKey('_id', $payload);
        self::assertArrayNotHasKey('_index', $payload);
        self::assertSame('1_42', $payload[ProductIndexMappingInterface::FIELD_DOCUMENT_ID]);
        self::assertSame(42, $payload[ProductIndexMappingInterface::FIELD_ENTITY_ID]);
        self::assertSame('SKU-42', $payload[ProductIndexMappingInterface::FIELD_SKU]);
        self::assertSame('1', $payload[ProductIndexMappingInterface::FIELD_STORE_ID]);
        self::assertSame(['1', '2'], $payload[ProductIndexMappingInterface::FIELD_WEBSITE_IDS]);
        self::assertSame('simple', $payload[ProductIndexMappingInterface::FIELD_PRODUCT_TYPE]);
        self::assertSame('Test Product', $payload[ProductIndexMappingInterface::FIELD_NAME]);
        self::assertTrue($payload[ProductIndexMappingInterface::FIELD_IS_ENABLED]);
        self::assertSame(4, $payload[ProductIndexMappingInterface::FIELD_VISIBILITY]);
        self::assertSame([0.1, 0.2, 0.3, 0.4], $payload[ProductIndexMappingInterface::FIELD_EMBEDDING]);
        self::assertSame(self::HASH, $payload[ProductIndexMappingInterface::FIELD_EMBEDDING_HASH]);
        self::assertSame(self::FINGERPRINT, $payload[ProductIndexMappingInterface::FIELD_EMBEDDING_FINGERPRINT]);
        self::assertSame('2026-01-01T00:00:00+00:00', $payload[ProductIndexMappingInterface::FIELD_INDEXED_AT]);
        self::assertSame('2026-01-01T00:00:00+00:00', $payload[ProductIndexMappingInterface::FIELD_UPDATED_AT]);
    }

    public function testBuildsNestedCategoriesAndAttributes(): void
    {
        $document = (new FakeProductDocumentFactory())->make();
        $vector = new EmbeddingVector([0.1, 0.2, 0.3, 0.4], 4);
        $indexed = new IndexedProductDocument($document, $vector, self::HASH, self::FINGERPRINT, '2026-01-01T00:00:00+00:00');

        $payload = $this->builder->build($indexed)->source();

        self::assertSame(
            [[
                ProductIndexMappingInterface::FIELD_CATEGORY_ID => 7,
                ProductIndexMappingInterface::FIELD_CATEGORY_NAME => 'Shoes',
                ProductIndexMappingInterface::FIELD_CATEGORY_PATH => 'Root / Men / Shoes',
            ]],
            $payload[ProductIndexMappingInterface::FIELD_CATEGORIES]
        );
        self::assertSame(
            [[
                ProductIndexMappingInterface::FIELD_ATTRIBUTE_CODE => 'color',
                ProductIndexMappingInterface::FIELD_ATTRIBUTE_LABEL => 'Color',
                ProductIndexMappingInterface::FIELD_ATTRIBUTE_VALUES => ['blue'],
            ]],
            $payload[ProductIndexMappingInterface::FIELD_ATTRIBUTES]
        );
    }

    public function testOmitsUpdatedAtWhenAbsent(): void
    {
        $document = (new FakeProductDocumentFactory())->make();
        $vector = new EmbeddingVector([0.1, 0.2, 0.3, 0.4], 4);

        $documentWithoutUpdate = new \Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductDocument(
            $document->schemaVersion(),
            $document->documentId(),
            $document->entityId(),
            $document->sku(),
            $document->storeId(),
            $document->websiteIds(),
            $document->productType(),
            $document->name(),
            $document->shortDescription(),
            $document->longDescription(),
            $document->isEnabled(),
            $document->visibility(),
            $document->categories(),
            $document->attributes(),
            $document->searchableText(),
            $document->embeddingContentHash(),
            $document->completeDocumentHash(),
            null
        );
        $indexed = new IndexedProductDocument($documentWithoutUpdate, $vector, self::HASH, self::FINGERPRINT, '2026-01-01T00:00:00+00:00');

        $payload = $this->builder->build($indexed)->source();

        self::assertArrayNotHasKey(ProductIndexMappingInterface::FIELD_UPDATED_AT, $payload);
    }

    public function testRejectsEmptyEmbedding(): void
    {
        $document = (new FakeProductDocumentFactory())->make();
        $vector = $this->createMock(\Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingVectorInterface::class);
        $vector->method('values')->willReturn([]);
        $vector->method('dimension')->willReturn(0);

        $this->expectException(IndexCompatibilityMismatchException::class);
        $this->builder->build(
            new IndexedProductDocument($document, $vector, self::HASH, self::FINGERPRINT, '2026-01-01T00:00:00+00:00')
        );
    }
}