<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\CapabilitiesConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\BlogContentSearcherInterface;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Tool\CmsPageContentSearcher;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ContentSearchTextUtility;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ProductContentSearcher;
use Aavirbhava\AiShoppingAssistant\Model\Tool\SearchStoreContentTool;
use Aavirbhava\AiShoppingAssistant\Model\Tool\StoreContentMatch;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use ArrayIterator;
use IteratorAggregate;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Traversable;

/**
 * CmsPageContentSearcher/ProductContentSearcher are both `final` (this
 * module's universal convention for concrete implementation classes), so
 * — exactly like SearchProductsToolTest constructs a real
 * ProductContextResolver rather than mocking it — this test builds real
 * instances of both, backed by minimal fake Magento collections, instead
 * of mocking the searchers themselves. Their own query-building/escaping/
 * dedup logic is already covered by CmsPageContentSearcherTest/
 * ProductContentSearcherTest; this file only proves SearchStoreContentTool
 * merges their results, gates on the capability toggle, and feeds
 * verified products into ToolResult correctly.
 */
#[CoversClass(SearchStoreContentTool::class)]
final class SearchStoreContentToolTest extends TestCase
{
    private const STORE_ID = 5;

    public function testNameAndSchema(): void
    {
        $tool = $this->tool();

        self::assertSame('search_store_content', $tool->name());
        self::assertSame(['query'], $tool->inputSchema()['required']);
    }

    public function testAuthorizeThrowsWhenPolicySearchIsDisabled(): void
    {
        $tool = $this->tool(policySearchEnabled: false);

        $this->expectException(ToolAuthorizationException::class);
        $tool->authorize(new ToolContext(self::STORE_ID, null));
    }

    public function testAuthorizePassesWhenPolicySearchIsEnabled(): void
    {
        $tool = $this->tool();

        $tool->authorize(new ToolContext(self::STORE_ID, null));
        $this->expectNotToPerformAssertions();
    }

    public function testExecuteRejectsAMissingQuery(): void
    {
        $tool = $this->tool();

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), []);

        self::assertArrayHasKey('error', $result->data);
        self::assertSame([], $result->verifiedProducts);
    }

    public function testExecuteMergesCmsBlogAndProductMatches(): void
    {
        $cmsRow = new StoreContentToolFakeCmsPageRow(6, 'Customer Service', '<p>Delivery and returns policy.</p>');
        $blogMatch = new StoreContentMatch(StoreContentMatch::TYPE_BLOG_POST, '3', 'Waterproofing your jacket', 'Tips for...');
        $verified = new RevalidatedProduct(1, 'SKU-1', 'Rain Jacket', 89.0, null, 'https://store.test/rain-jacket', '2026-08-16T00:00:00+00:00');

        $blogSearcher = $this->createMock(BlogContentSearcherInterface::class);
        $blogSearcher->method('search')->with(self::STORE_ID, 'returns', 5)->willReturn([$blogMatch]);

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->with(self::STORE_ID, null, ['SKU-1'])->willReturn([$verified]);

        $tool = $this->tool(
            cmsRows: [$cmsRow],
            productSkuRows: [new StoreContentToolFakeProductRow('SKU-1')],
            blogSearcher: $blogSearcher,
            revalidationService: $revalidationService
        );

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['query' => 'returns']);

        self::assertSame([$verified], $result->verifiedProducts);
        self::assertCount(3, $result->data['results']);
        self::assertSame('cms_page', $result->data['results'][0]['type']);
        self::assertSame('blog_post', $result->data['results'][1]['type']);
        self::assertSame('product', $result->data['results'][2]['type']);
        self::assertSame('SKU-1', $result->data['results'][2]['id']);
        self::assertSame(89.0, $result->data['results'][2]['price']);
    }

    public function testProductSearchIsSkippedWhenNoCandidateSkusAreFound(): void
    {
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->expects(self::never())->method('revalidate');

        $tool = $this->tool(revalidationService: $revalidationService);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['query' => 'nonexistent']);

        self::assertSame([], $result->verifiedProducts);
        self::assertSame([], $result->data['results']);
    }

    /**
     * @param list<StoreContentToolFakeCmsPageRow> $cmsRows
     * @param list<StoreContentToolFakeProductRow> $productSkuRows
     */
    private function tool(
        bool $policySearchEnabled = true,
        array $cmsRows = [],
        array $productSkuRows = [],
        ?BlogContentSearcherInterface $blogSearcher = null,
        ?LiveRevalidationServiceInterface $revalidationService = null
    ): SearchStoreContentTool {
        $capabilities = $this->createMock(CapabilitiesConfigInterface::class);
        $capabilities->method('isPolicySearchEnabled')->willReturn($policySearchEnabled);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readCapabilities')->with(self::STORE_ID)->willReturn($capabilities);

        $textUtility = new ContentSearchTextUtility();

        $cmsFactory = $this->createMock(CmsPageCollectionFactory::class);
        $cmsFactory->method('create')->willReturn(new StoreContentToolFakeCmsPageCollection($cmsRows));
        $cmsSearcher = new CmsPageContentSearcher($cmsFactory, $textUtility);

        $productFactory = $this->createMock(ProductCollectionFactory::class);
        $productFactory->method('create')->willReturn(new StoreContentToolFakeProductCollection($productSkuRows));
        $categoryFactory = $this->createMock(CategoryCollectionFactory::class);
        $categoryFactory->method('create')->willReturn(new StoreContentToolFakeCategoryCollection([]));
        $productSearcher = new ProductContentSearcher($productFactory, $categoryFactory, $textUtility);

        $blogSearcher ??= $this->createMock(BlogContentSearcherInterface::class);
        $revalidationService ??= $this->createMock(LiveRevalidationServiceInterface::class);

        return new SearchStoreContentTool(
            $configurationReader,
            $cmsSearcher,
            $blogSearcher,
            $productSearcher,
            $revalidationService
        );
    }
}

final class StoreContentToolFakeCmsPageRow
{
    public function __construct(
        private readonly int $id,
        private readonly string $title,
        private readonly string $content
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}

final class StoreContentToolFakeCmsPageCollection implements IteratorAggregate
{
    /**
     * @param list<StoreContentToolFakeCmsPageRow> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    public function addStoreFilter(int $storeId): self
    {
        return $this;
    }

    public function addFieldToFilter($field, $condition): self
    {
        return $this;
    }

    public function setPageSize(int $size): self
    {
        return $this;
    }

    public function setCurPage(int $page): self
    {
        return $this;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }
}

final class StoreContentToolFakeProductRow
{
    public function __construct(private readonly string $sku)
    {
    }

    public function getSku(): string
    {
        return $this->sku;
    }
}

final class StoreContentToolFakeProductCollection implements IteratorAggregate
{
    /**
     * @param list<StoreContentToolFakeProductRow> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    public function addAttributeToSelect(array $attributes): self
    {
        return $this;
    }

    public function addStoreFilter(int $storeId): self
    {
        return $this;
    }

    public function addAttributeToFilter(array $conditions): self
    {
        return $this;
    }

    public function addCategoriesFilter(array $filter): self
    {
        return $this;
    }

    public function setPageSize(int $size): self
    {
        return $this;
    }

    public function setCurPage(int $page): self
    {
        return $this;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }
}

final class StoreContentToolFakeCategoryCollection
{
    /**
     * @param list<int> $ids
     */
    public function __construct(private readonly array $ids)
    {
    }

    public function addAttributeToSelect(string $attribute): self
    {
        return $this;
    }

    public function addAttributeToFilter(string $attribute, array $condition): self
    {
        return $this;
    }

    public function setPageSize(int $size): self
    {
        return $this;
    }

    /**
     * @return list<int>
     */
    public function getAllIds(): array
    {
        return $this->ids;
    }
}
