<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Store;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Store\Exception\StoreScopeException;
use Aavirbhava\AiShoppingAssistant\Model\Store\StoreScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StoreScope::class)]
final class StoreScopeTest extends TestCase
{
    public function testExposesScopeValues(): void
    {
        $scope = new StoreScope(3, 1, 'default', 'en_US');

        self::assertInstanceOf(StoreScopeInterface::class, $scope);
        self::assertSame(3, $scope->storeId());
        self::assertSame(1, $scope->websiteId());
        self::assertSame('default', $scope->storeCode());
        self::assertSame('en_US', $scope->localeCode());
    }

    public function testLocaleIsNullable(): void
    {
        $scope = new StoreScope(3, 1, 'default');
        self::assertNull($scope->localeCode());
    }

    public function testRejectsNonPositiveStoreId(): void
    {
        $this->expectException(StoreScopeException::class);
        new StoreScope(0, 1, 'default');
    }

    public function testRejectsNonPositiveWebsiteId(): void
    {
        $this->expectException(StoreScopeException::class);
        new StoreScope(3, 0, 'default');
    }

    public function testRejectsEmptyStoreCode(): void
    {
        $this->expectException(StoreScopeException::class);
        new StoreScope(3, 1, '');
    }
}
