<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Merchandising;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\ActiveCategoryBoostReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveCategoryBoostReader::class)]
final class ActiveCategoryBoostReaderTest extends TestCase
{
    public function testReturnsBoostsKeyedByCategoryId(): void
    {
        $reader = $this->reader(['1' => '0.5000', '3' => '0.2500']);

        $result = $reader->forCategoryIds([1, 2, 3]);

        self::assertSame([1 => 0.5, 3 => 0.25], $result);
    }

    public function testEmptyCategoryIdListNeverQueries(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $reader = new ActiveCategoryBoostReader($resource, $this->clock());

        self::assertSame([], $reader->forCategoryIds([]));
    }

    public function testNonPositiveIdsAreFilteredOutBeforeQuerying(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $reader = new ActiveCategoryBoostReader($resource, $this->clock());

        self::assertSame([], $reader->forCategoryIds([0, -1]));
    }

    public function testRepeatedCallsWithTheSameCategoryIdSetAreMemoizedWithinOneInstance(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects(self::once())->method('fetchPairs')->willReturn(['5' => '0.3000']);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $reader = new ActiveCategoryBoostReader($resource, $this->clock());

        self::assertSame([5 => 0.3], $reader->forCategoryIds([5]));
        // Same set again — fetchPairs must not be called a second time.
        self::assertSame([5 => 0.3], $reader->forCategoryIds([5]));
    }

    public function testADifferentCategoryIdSetIsNotServedFromAnUnrelatedCacheEntry(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects(self::exactly(2))->method('fetchPairs')->willReturnOnConsecutiveCalls(
            ['5' => '0.3000'],
            ['7' => '0.9000']
        );

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $reader = new ActiveCategoryBoostReader($resource, $this->clock());

        self::assertSame([5 => 0.3], $reader->forCategoryIds([5]));
        self::assertSame([7 => 0.9], $reader->forCategoryIds([7]));
    }

    private function reader(array $fetchPairsResult): ActiveCategoryBoostReader
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchPairs')->willReturn($fetchPairsResult);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new ActiveCategoryBoostReader($resource, $this->clock());
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-08-20 12:00:00'));

        return $clock;
    }
}
