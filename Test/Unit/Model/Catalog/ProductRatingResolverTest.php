<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductRatingResolver;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Review\Model\ResourceModel\Review\Summary as ReviewSummaryResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductRatingResolver::class)]
final class ProductRatingResolverTest extends TestCase
{
    public function testAppendToCollectionDelegatesToMagentosOwnSummaryResourceMechanism(): void
    {
        $collection = $this->createMock(Collection::class);
        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn(2);

        $summaryResource = $this->createMock(ReviewSummaryResource::class);
        $summaryResource->expects(self::once())
            ->method('appendSummaryFieldsToCollection')
            ->with($collection, 2, 'product');

        $resolver = new ProductRatingResolver($summaryResource);

        $resolver->appendToCollection($collection, $scope);
    }

    public function testCatalogAverageConvertsThePercentageAggregateToTheFivePointScale(): void
    {
        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn(2);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('88.0');

        $summaryResource = $this->createMock(ReviewSummaryResource::class);
        $summaryResource->method('getConnection')->willReturn($connection);
        $summaryResource->method('getMainTable')->willReturn('review_entity_summary');
        $summaryResource->method('getTable')->willReturn('review_entity');

        $resolver = new ProductRatingResolver($summaryResource);

        self::assertSame(4.4, $resolver->catalogAverage($scope));
    }

    public function testCatalogAverageIsZeroWhenTheStoreHasNoReviewsAtAll(): void
    {
        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn(2);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn(false);

        $summaryResource = $this->createMock(ReviewSummaryResource::class);
        $summaryResource->method('getConnection')->willReturn($connection);
        $summaryResource->method('getMainTable')->willReturn('review_entity_summary');
        $summaryResource->method('getTable')->willReturn('review_entity');

        $resolver = new ProductRatingResolver($summaryResource);

        self::assertSame(0.0, $resolver->catalogAverage($scope));
    }

    public function testPercentToStarsConvertsAndClampsToTheZeroToFiveRange(): void
    {
        $summaryResource = $this->createMock(ReviewSummaryResource::class);
        $resolver = new ProductRatingResolver($summaryResource);

        self::assertSame(4.5, $resolver->percentToStars(90.0));
        self::assertSame(0.0, $resolver->percentToStars(0.0));
        self::assertSame(5.0, $resolver->percentToStars(150.0));
        self::assertSame(0.0, $resolver->percentToStars(-10.0));
    }
}
