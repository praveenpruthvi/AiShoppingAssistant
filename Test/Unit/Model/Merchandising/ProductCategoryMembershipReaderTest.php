<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Merchandising;

use Aavirbhava\AiShoppingAssistant\Model\Merchandising\ProductCategoryMembershipReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductCategoryMembershipReader::class)]
final class ProductCategoryMembershipReaderTest extends TestCase
{
    public function testReturnsCategoryIdsKeyedByProductId(): void
    {
        $reader = $this->reader([
            ['product_id' => '1', 'category_id' => '10'],
            ['product_id' => '1', 'category_id' => '11'],
            ['product_id' => '2', 'category_id' => '10'],
        ]);

        $result = $reader->forProductIds([1, 2]);

        self::assertSame([1 => [10, 11], 2 => [10]], $result);
    }

    public function testAProductWithNoCategoryMembershipIsSimplyAbsent(): void
    {
        $reader = $this->reader([
            ['product_id' => '1', 'category_id' => '10'],
        ]);

        $result = $reader->forProductIds([1, 2]);

        self::assertSame([1 => [10]], $result);
        self::assertArrayNotHasKey(2, $result);
    }

    public function testEmptyProductIdListNeverQueries(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $reader = new ProductCategoryMembershipReader($resource);

        self::assertSame([], $reader->forProductIds([]));
    }

    public function testNonPositiveIdsAreFilteredOutBeforeQuerying(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $reader = new ProductCategoryMembershipReader($resource);

        self::assertSame([], $reader->forProductIds([0, -1]));
    }

    public function testRepeatedCallsWithTheSameProductIdSetAreMemoizedWithinOneInstance(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects(self::once())->method('fetchAll')->willReturn([
            ['product_id' => '5', 'category_id' => '20'],
        ]);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $reader = new ProductCategoryMembershipReader($resource);

        self::assertSame([5 => [20]], $reader->forProductIds([5]));
        // Same set again — fetchAll must not be called a second time.
        self::assertSame([5 => [20]], $reader->forProductIds([5]));
    }

    /**
     * @param list<array{product_id: string, category_id: string}> $fetchAllResult
     */
    private function reader(array $fetchAllResult): ProductCategoryMembershipReader
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($fetchAllResult);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new ProductCategoryMembershipReader($resource);
    }
}
