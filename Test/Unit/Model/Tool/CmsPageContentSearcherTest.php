<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Tool\CmsPageContentSearcher;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ContentSearchTextUtility;
use ArrayIterator;
use IteratorAggregate;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Traversable;

#[CoversClass(CmsPageContentSearcher::class)]
final class CmsPageContentSearcherTest extends TestCase
{
    public function testReturnsAMatchPerPageWithATypeIdTitleAndSnippet(): void
    {
        $collection = new FakeCmsPageCollection([
            new FakeCmsPageRow(6, 'Customer Service', '<p>We hope you love shopping. Here are our delivery and return policies.</p>'),
        ]);

        $searcher = $this->searcher($collection);

        $matches = $searcher->search(1, 'return', 5);

        self::assertCount(1, $matches);
        self::assertSame('cms_page', $matches[0]->type);
        self::assertSame('6', $matches[0]->id);
        self::assertSame('Customer Service', $matches[0]->title);
        self::assertStringContainsString('return', strtolower($matches[0]->snippet));
    }

    public function testScopesToTheGivenStoreAndActivePagesOnly(): void
    {
        $collection = new FakeCmsPageCollection([]);
        $searcher = $this->searcher($collection);

        $searcher->search(3, 'anything', 5);

        self::assertSame(3, $collection->storeFilterAppliedFor);
        self::assertSame(1, $collection->fieldFilters['is_active']);
    }

    public function testEscapesLikeWildcardsInTheQuery(): void
    {
        $collection = new FakeCmsPageCollection([]);
        $searcher = $this->searcher($collection);

        $searcher->search(1, '50% off', 5);

        self::assertStringContainsString('50\\% off', (string) $collection->fieldFilters['title_content'][0]['like']);
    }

    public function testCapsResultsAtTheGivenLimit(): void
    {
        $collection = new FakeCmsPageCollection([]);
        $searcher = $this->searcher($collection);

        $searcher->search(1, 'anything', 3);

        self::assertSame(3, $collection->pageSize);
    }

    private function searcher(FakeCmsPageCollection $collection): CmsPageContentSearcher
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new CmsPageContentSearcher($factory, new ContentSearchTextUtility());
    }
}

final class FakeCmsPageRow
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

final class FakeCmsPageCollection implements IteratorAggregate
{
    public ?int $storeFilterAppliedFor = null;

    /** @var array<string, mixed> */
    public array $fieldFilters = [];

    public ?int $pageSize = null;

    /**
     * @param list<FakeCmsPageRow> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    public function addStoreFilter(int $storeId): self
    {
        $this->storeFilterAppliedFor = $storeId;

        return $this;
    }

    /**
     * @param string|list<string> $field
     * @param array<int, mixed>|array<string, mixed> $condition
     */
    public function addFieldToFilter($field, $condition): self
    {
        $key = is_array($field) ? implode('_', $field) : $field;
        $this->fieldFilters[$key] = $condition;

        return $this;
    }

    public function setPageSize(int $size): self
    {
        $this->pageSize = $size;

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
