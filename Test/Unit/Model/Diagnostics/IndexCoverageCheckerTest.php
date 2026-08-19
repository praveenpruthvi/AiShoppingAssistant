<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Diagnostics;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Diagnostics\CatalogSkuProvider;
use Aavirbhava\AiShoppingAssistant\Model\Diagnostics\IndexCoverageChecker;
use Aavirbhava\AiShoppingAssistant\Model\Diagnostics\IndexedSkuProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndexCoverageChecker::class)]
final class IndexCoverageCheckerTest extends TestCase
{
    private function scope(): StoreScopeInterface
    {
        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn(1);
        $scope->method('storeCode')->willReturn('default');

        return $scope;
    }

    public function testFullyCoveredWhenCatalogAndIndexSkusMatchExactly(): void
    {
        $catalogProvider = $this->createMock(CatalogSkuProvider::class);
        $catalogProvider->method('salableVisibleEnabledSkus')->willReturn(['SKU-1', 'SKU-2']);

        $indexedProvider = $this->createMock(IndexedSkuProvider::class);
        $indexedProvider->method('indexedSkus')->willReturn(['SKU-1', 'SKU-2']);

        $report = (new IndexCoverageChecker($catalogProvider, $indexedProvider))->check($this->scope());

        self::assertSame(2, $report->catalogCount);
        self::assertSame(2, $report->indexCount);
        self::assertSame([], $report->missingFromIndex);
        self::assertSame([], $report->missingFromCatalog);
        self::assertTrue($report->isFullyCovered());
    }

    public function testListsCatalogSkusMissingFromTheIndex(): void
    {
        $catalogProvider = $this->createMock(CatalogSkuProvider::class);
        $catalogProvider->method('salableVisibleEnabledSkus')->willReturn(['SKU-1', 'SKU-2', 'SKU-3']);

        $indexedProvider = $this->createMock(IndexedSkuProvider::class);
        $indexedProvider->method('indexedSkus')->willReturn(['SKU-1']);

        $report = (new IndexCoverageChecker($catalogProvider, $indexedProvider))->check($this->scope());

        self::assertSame(['SKU-2', 'SKU-3'], $report->missingFromIndex);
        self::assertSame([], $report->missingFromCatalog);
    }

    public function testListsIndexedSkusNoLongerInTheRealCatalog(): void
    {
        $catalogProvider = $this->createMock(CatalogSkuProvider::class);
        $catalogProvider->method('salableVisibleEnabledSkus')->willReturn(['SKU-1']);

        $indexedProvider = $this->createMock(IndexedSkuProvider::class);
        $indexedProvider->method('indexedSkus')->willReturn(['SKU-1', 'SKU-STALE']);

        $report = (new IndexCoverageChecker($catalogProvider, $indexedProvider))->check($this->scope());

        self::assertSame([], $report->missingFromIndex);
        self::assertSame(['SKU-STALE'], $report->missingFromCatalog);
    }

    public function testIndexUnavailableWhenNoAliasExistsYet(): void
    {
        $catalogProvider = $this->createMock(CatalogSkuProvider::class);
        $catalogProvider->method('salableVisibleEnabledSkus')->willReturn(['SKU-1', 'SKU-2']);

        $indexedProvider = $this->createMock(IndexedSkuProvider::class);
        $indexedProvider->method('indexedSkus')->willReturn(null);

        $report = (new IndexCoverageChecker($catalogProvider, $indexedProvider))->check($this->scope());

        self::assertSame(2, $report->catalogCount);
        self::assertNull($report->indexCount);
        self::assertFalse($report->indexAvailable());
        self::assertSame([], $report->missingFromIndex);
        self::assertSame([], $report->missingFromCatalog);
    }
}
