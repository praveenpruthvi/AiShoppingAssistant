<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityResultInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\CategoryReference;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ContentHashService;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductAttributePolicy;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductDocumentNormalizer;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductEligibilityContext;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductIndexEligibilityPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\SearchableAttribute;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\UntrustedContentSanitizer;
use Magento\Catalog\Model\Product\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductDocumentNormalizer::class)]
final class ProductDocumentNormalizerTest extends TestCase
{
    private ProductDocumentNormalizer $normalizer;
    private CatalogSnapshotFactory $factory;

    protected function setUp(): void
    {
        $this->normalizer = new ProductDocumentNormalizer(
            new ProductIndexEligibilityPolicy(),
            new UntrustedContentSanitizer(),
            new ProductAttributePolicy(),
            new ContentHashService()
        );
        $this->factory = new CatalogSnapshotFactory();
    }

    public function testNormalizesEligibleSnapshotIntoDocument(): void
    {
        $result = $this->normalizer->normalize(
            $this->factory->create(),
            new ProductEligibilityContext(1, 1)
        );

        self::assertTrue($result->eligible());
        self::assertNotNull($result->document());

        $document = $result->document();
        self::assertSame('1_42', $document->documentId());
        self::assertSame(42, $document->entityId());
        self::assertSame('SKU-42', $document->sku());
        self::assertSame('Test Product', $document->name());
        self::assertSame(1, $document->schemaVersion());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $document->embeddingContentHash());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $document->completeDocumentHash());
    }

    public function testReturnsIneligibleResultForDisabledProduct(): void
    {
        $result = $this->normalizer->normalize(
            $this->factory->create(['isEnabled' => false]),
            new ProductEligibilityContext(1, 1)
        );

        self::assertFalse($result->eligible());
        self::assertSame(ProductEligibilityResultInterface::REASON_DISABLED, $result->reasonCode());
        self::assertNull($result->document());
    }

    public function testReturnsIneligibleResultForCrossStoreSnapshot(): void
    {
        $result = $this->normalizer->normalize(
            $this->factory->create(['storeId' => 5]),
            new ProductEligibilityContext(1, 1)
        );

        self::assertFalse($result->eligible());
        self::assertSame(ProductEligibilityResultInterface::REASON_STORE_MISMATCH, $result->reasonCode());
        self::assertNull($result->document());
    }

    public function testIsDeterministicAndIdempotent(): void
    {
        $snapshot = $this->factory->create();
        $context = new ProductEligibilityContext(1, 1);

        $first = $this->normalizer->normalize($snapshot, $context)->document();
        $second = $this->normalizer->normalize($snapshot, $context)->document();

        self::assertSame($first->completeDocumentHash(), $second->completeDocumentHash());
        self::assertSame($first->embeddingContentHash(), $second->embeddingContentHash());
        self::assertSame($first->searchableText(), $second->searchableText());
    }

    public function testSortsWebsiteIdsAscending(): void
    {
        $document = $this->normalizer->normalize(
            $this->factory->create(['websiteIds' => [9, 2, 5]]),
            new ProductEligibilityContext(1, 5)
        )->document();

        self::assertSame([2, 5, 9], $document->websiteIds());
    }

    public function testSortsCategoriesByCategoryId(): void
    {
        $categories = [
            new CategoryReference(3, 'Men', 'Root / Men'),
            new CategoryReference(1, 'Shoes', 'Root / Shoes'),
        ];

        $document = $this->normalizer->normalize(
            $this->factory->create(['categories' => $categories]),
            new ProductEligibilityContext(1, 1)
        )->document();

        self::assertSame([1, 3], array_map(
            static fn (CategoryReference $category): int => $category->categoryId(),
            $document->categories()
        ));
    }

    public function testFiltersDeniedAttributesFromDocument(): void
    {
        $attributes = [
            new SearchableAttribute('material', 'Material', ['leather']),
            new SearchableAttribute('cost', 'Cost', ['99']),
        ];

        $document = $this->normalizer->normalize(
            $this->factory->create(['attributes' => $attributes]),
            new ProductEligibilityContext(1, 1)
        )->document();

        self::assertCount(1, $document->attributes());
        self::assertSame('material', $document->attributes()[0]->code());
    }

    public function testDropsSanitizedEmptyAttributeValuesAndDeduplicates(): void
    {
        $attributes = [
            new SearchableAttribute('material', 'Material', ['leather', 'leather']),
            new SearchableAttribute('note_html', 'Note', ["<script>alert(1)</script>"]),
        ];

        $document = $this->normalizer->normalize(
            $this->factory->create(['attributes' => $attributes]),
            new ProductEligibilityContext(1, 1)
        )->document();

        self::assertCount(1, $document->attributes());
        self::assertSame(['leather'], $document->attributes()[0]->values());
    }

    public function testAssemblesSearchableTextInFixedOrder(): void
    {
        $document = $this->normalizer->normalize(
            $this->factory->create(),
            new ProductEligibilityContext(1, 1)
        )->document();

        $searchableText = $document->searchableText();

        $namePos = strpos($searchableText, 'Test Product');
        $shortPos = strpos($searchableText, 'A short description.');
        $longPos = strpos($searchableText, 'A long description.');
        $categoryPos = strpos($searchableText, 'Shoes');
        $pathPos = strpos($searchableText, 'Root Catalog / Shoes');
        $labelPos = strpos($searchableText, 'Material');
        $valuePos = strpos($searchableText, 'leather');

        self::assertNotFalse($namePos);
        self::assertNotFalse($shortPos);
        self::assertNotFalse($longPos);
        self::assertNotFalse($categoryPos);
        self::assertNotFalse($pathPos);
        self::assertNotFalse($labelPos);
        self::assertNotFalse($valuePos);

        self::assertLessThan($shortPos, $namePos);
        self::assertLessThan($longPos, $shortPos);
        self::assertLessThan($categoryPos, $longPos);
        self::assertLessThan($pathPos, $categoryPos);
        self::assertLessThan($labelPos, $pathPos);
        self::assertLessThan($valuePos, $labelPos);
    }

    public function testStripsInjectionFromDescriptionBeforeHashing(): void
    {
        $snapshot = $this->factory->create([
            'longDescription' => "<p>Great product</p><script>alert('xss')</script>",
        ]);

        $document = $this->normalizer->normalize($snapshot, new ProductEligibilityContext(1, 1))->document();

        self::assertSame('Great product', $document->longDescription());
        self::assertStringNotContainsString('alert', $document->searchableText());
    }

    public function testEmbeddingHashIgnoresStatusAndScopeFields(): void
    {
        $context = new ProductEligibilityContext(1, 1);

        $base = $this->factory->create(['visibility' => Visibility::VISIBILITY_BOTH, 'websiteIds' => [1]]);
        $changed = $this->factory->create([
            'visibility' => Visibility::VISIBILITY_IN_SEARCH,
            'websiteIds' => [1, 2],
            'updatedAt' => '2026-02-02T00:00:00+00:00',
        ]);

        $baseDocument = $this->normalizer->normalize($base, $context)->document();
        $changedDocument = $this->normalizer->normalize($changed, $context)->document();

        self::assertSame($baseDocument->embeddingContentHash(), $changedDocument->embeddingContentHash());
        self::assertNotSame($baseDocument->completeDocumentHash(), $changedDocument->completeDocumentHash());
    }

    public function testThrowsWhenSkuBecomesEmptyAfterSanitization(): void
    {
        $this->expectException(CatalogException::class);

        $this->normalizer->normalize(
            $this->factory->create(['sku' => "<script>alert(1)</script>"]),
            new ProductEligibilityContext(1, 1)
        );
    }

    public function testThrowsWhenNameBecomesEmptyAfterSanitization(): void
    {
        $this->expectException(CatalogException::class);

        $this->normalizer->normalize(
            $this->factory->create(['name' => "<style>body{display:none}</style>"]),
            new ProductEligibilityContext(1, 1)
        );
    }

    public function testThrowsWhenSkuIsOnlyWhitespace(): void
    {
        $this->expectException(CatalogException::class);

        $this->normalizer->normalize(
            $this->factory->create(['sku' => "   \n\t "]),
            new ProductEligibilityContext(1, 1)
        );
    }

    public function testConvertsMysqlDatetimeUpdatedAtToIso8601(): void
    {
        $result = $this->normalizer->normalize(
            $this->factory->create(['updatedAt' => '2026-04-07 07:39:17']),
            new ProductEligibilityContext(1, 1)
        );

        self::assertTrue($result->eligible());
        self::assertSame('2026-04-07T07:39:17+00:00', $result->document()->updatedAt());
    }

    public function testReturnsNullUpdatedAtWhenSnapshotUpdatedAtIsNull(): void
    {
        $result = $this->normalizer->normalize(
            $this->factory->create(['updatedAt' => null]),
            new ProductEligibilityContext(1, 1)
        );

        self::assertTrue($result->eligible());
        self::assertNull($result->document()->updatedAt());
    }
}
