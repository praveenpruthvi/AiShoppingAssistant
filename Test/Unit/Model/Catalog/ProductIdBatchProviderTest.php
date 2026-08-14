<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductIdBatchProvider;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use InvalidArgumentException;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductIdBatchProvider::class)]
final class ProductIdBatchProviderTest extends TestCase
{
    /**
     * @param list<int> $allIds
     */
    private function providerWithIds(array $allIds): ProductIdBatchProvider
    {
        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')
            ->willReturnCallback(function () use ($allIds) {
                return new FakeProductCollection($allIds);
            });

        return new ProductIdBatchProvider($factory);
    }

    private function scope(): StoreScopeInterface
    {
        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn(1);
        $scope->method('websiteId')->willReturn(1);

        return $scope;
    }

    public function testYieldsAscendingDisjointBatches(): void
    {
        $provider = $this->providerWithIds([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        $scope = $this->scope();

        $batches = [];
        foreach ($provider->batches($scope, 4) as $batch) {
            $batches[] = $batch;
        }

        self::assertSame([[1, 2, 3, 4], [5, 6, 7, 8], [9, 10]], $batches);
    }

    public function testSingleBatchForSmallCatalogue(): void
    {
        $provider = $this->providerWithIds([7, 3]);
        $scope = $this->scope();

        $batches = [];
        foreach ($provider->batches($scope, 10) as $batch) {
            $batches[] = $batch;
        }

        self::assertSame([[3, 7]], $batches);
    }

    public function testEmptyCatalogueYieldsNoBatches(): void
    {
        $provider = $this->providerWithIds([]);
        $scope = $this->scope();

        self::assertSame([], iterator_to_array($provider->batches($scope, 10)));
    }

    public function testRejectsOutOfRangeBatchSize(): void
    {
        $provider = $this->providerWithIds([1, 2, 3]);
        $scope = $this->scope();

        $this->expectException(InvalidArgumentException::class);

        iterator_to_array($provider->batches($scope, 0));
    }
}

final class FakeProductCollection
{
    private int $lastId = 0;

    /**
     * @param list<int> $allIds
     */
    public function __construct(private array $allIds)
    {
    }

    public function setStoreId(int $storeId): self
    {
        return $this;
    }

    public function addWebsiteFilter(int $websiteId): self
    {
        return $this;
    }

    public function addFieldToFilter(string $field, array $condition): self
    {
        if ($field === 'entity_id' && isset($condition['gt'])) {
            $this->lastId = (int) $condition['gt'];
        }

        return $this;
    }

    public function setOrder(string $field, string $direction): self
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

    /**
     * @return list<int>
     */
    public function getAllIds(int $limit = null, int $offset = null): array
    {
        $pageSize = $limit ?? count($this->allIds);
        $remaining = array_values(array_filter($this->allIds, fn (int $id): bool => $id > $this->lastId));

        return array_slice($remaining, 0, $pageSize);
    }
}