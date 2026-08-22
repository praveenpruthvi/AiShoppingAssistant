<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Plugin\Catalog\DataProvider;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\CategoryBoostRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\CategoryBoostRow;
use Aavirbhava\AiShoppingAssistant\Plugin\Catalog\DataProvider\CategoryBoostDataProviderPlugin;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Category\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CategoryBoostDataProviderPlugin::class)]
final class CategoryBoostDataProviderPluginTest extends TestCase
{
    private const CATEGORY_ID = 42;

    public function testMergesTheSavedBoostFieldsIntoTheCurrentCategorysOwnResultRow(): void
    {
        $boost = new CategoryBoostRow(1, self::CATEGORY_ID, 0.6, '2026-03-01 00:00:00', '2026-03-31 00:00:00', true);
        $repository = $this->repository($boost);

        $result = (new CategoryBoostDataProviderPlugin($repository))->afterGetData(
            $this->subject(),
            [self::CATEGORY_ID => ['name' => 'Some Category']]
        );

        self::assertSame(0.6, $result[self::CATEGORY_ID]['aavirbhava_category_boost_weight']);
        self::assertSame('2026-03-01', $result[self::CATEGORY_ID]['aavirbhava_category_boost_start_date']);
        self::assertSame('2026-03-31', $result[self::CATEGORY_ID]['aavirbhava_category_boost_end_date']);
        // The rest of the row (e.g. the category's own real 'name') must
        // stay completely untouched by this plugin.
        self::assertSame('Some Category', $result[self::CATEGORY_ID]['name']);
    }

    public function testACategoryWithNoBoostGetsNullFieldsRatherThanBeingLeftAbsent(): void
    {
        $repository = $this->repository(null);

        $result = (new CategoryBoostDataProviderPlugin($repository))->afterGetData(
            $this->subject(),
            [self::CATEGORY_ID => ['name' => 'Some Category']]
        );

        self::assertNull($result[self::CATEGORY_ID]['aavirbhava_category_boost_weight']);
        self::assertNull($result[self::CATEGORY_ID]['aavirbhava_category_boost_start_date']);
        self::assertNull($result[self::CATEGORY_ID]['aavirbhava_category_boost_end_date']);
    }

    public function testAnOpenEndedBoostLeavesTheUnsetDateAsNull(): void
    {
        $boost = new CategoryBoostRow(1, self::CATEGORY_ID, 0.4, null, null, true);
        $repository = $this->repository($boost);

        $result = (new CategoryBoostDataProviderPlugin($repository))->afterGetData(
            $this->subject(),
            [self::CATEGORY_ID => []]
        );

        self::assertNull($result[self::CATEGORY_ID]['aavirbhava_category_boost_start_date']);
        self::assertNull($result[self::CATEGORY_ID]['aavirbhava_category_boost_end_date']);
    }

    public function testANewNeverSavedCategoryIsLeftUntouched(): void
    {
        $subject = $this->getMockBuilder(DataProvider::class)->disableOriginalConstructor()->getMock();
        $subject->method('getCurrentCategory')->willReturn(null);

        $repository = $this->createMock(CategoryBoostRepositoryInterface::class);
        $repository->expects(self::never())->method('findByCategoryId');

        $result = (new CategoryBoostDataProviderPlugin($repository))->afterGetData($subject, []);

        self::assertSame([], $result);
    }

    public function testACurrentCategoryNotYetPresentInTheResultArrayIsLeftUntouched(): void
    {
        // getCurrentCategory() found a real category, but its id isn't
        // (yet) a key in $result — defensive: never blindly write a new
        // top-level array key the real DataProvider::getData() never
        // produced itself.
        $repository = $this->createMock(CategoryBoostRepositoryInterface::class);
        $repository->expects(self::never())->method('findByCategoryId');

        $result = (new CategoryBoostDataProviderPlugin($repository))->afterGetData($this->subject(), []);

        self::assertSame([], $result);
    }

    private function repository(?CategoryBoostRow $boost): CategoryBoostRepositoryInterface
    {
        $repository = $this->createMock(CategoryBoostRepositoryInterface::class);
        $repository->method('findByCategoryId')->with(self::CATEGORY_ID)->willReturn($boost);

        return $repository;
    }

    private function subject(): DataProvider
    {
        $category = $this->getMockBuilder(Category::class)->disableOriginalConstructor()->getMock();
        $category->method('getId')->willReturn(self::CATEGORY_ID);

        $subject = $this->getMockBuilder(DataProvider::class)->disableOriginalConstructor()->getMock();
        $subject->method('getCurrentCategory')->willReturn($category);

        return $subject;
    }
}
