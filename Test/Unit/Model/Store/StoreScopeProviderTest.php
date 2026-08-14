<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Store;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Store\Exception\StoreScopeException;
use Aavirbhava\AiShoppingAssistant\Model\Store\StoreScopeProvider;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StoreScopeProvider::class)]
final class StoreScopeProviderTest extends TestCase
{
    /**
     * @param list<Store> $stores
     */
    private function store(
        int $id,
        int $websiteId,
        string $code,
        bool $active,
        ?string $locale = 'en_US'
    ): Store {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getWebsiteId')->willReturn($websiteId);
        $store->method('getCode')->willReturn($code);
        $store->method('isActive')->willReturn($active);
        $store->method('getConfig')->willReturn($locale);

        return $store;
    }

    public function testReturnsActiveStoresSortedByStoreIdExcludingAdmin(): void
    {
        $admin = $this->store(0, 0, 'admin', true);
        $storeB = $this->store(2, 1, 'second', true);
        $storeA = $this->store(1, 1, 'default', true);
        $inactive = $this->store(3, 1, 'inactive', false);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->with(false)->willReturn([$admin, $storeB, $storeA, $inactive]);

        $provider = new StoreScopeProvider($storeManager);

        $scopes = $provider->activeStores();

        self::assertCount(2, $scopes);
        self::assertSame(1, $scopes[0]->storeId());
        self::assertSame(2, $scopes[1]->storeId());
        self::assertInstanceOf(StoreScopeInterface::class, $scopes[0]);
    }

    public function testLocaleFallsBackToNullWhenNotConfigured(): void
    {
        $store = $this->store(1, 1, 'default', true, null);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->with(false)->willReturn([$store]);

        $provider = new StoreScopeProvider($storeManager);

        self::assertNull($provider->activeStores()[0]->localeCode());
    }

    public function testRequireActiveReturnsScopeForActiveStore(): void
    {
        $store = $this->store(4, 2, 'french', true, 'fr_FR');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with(4)->willReturn($store);

        $provider = new StoreScopeProvider($storeManager);

        $scope = $provider->requireActive(4);
        self::assertSame(4, $scope->storeId());
        self::assertSame(2, $scope->websiteId());
        self::assertSame('fr_FR', $scope->localeCode());
    }

    public function testRequireActiveRejectsNonPositiveStoreId(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);

        $provider = new StoreScopeProvider($storeManager);

        $this->expectException(StoreScopeException::class);
        $provider->requireActive(0);
    }

    public function testRequireActiveRejectsInactiveStore(): void
    {
        $store = $this->store(5, 1, 'inactive', false);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with(5)->willReturn($store);

        $provider = new StoreScopeProvider($storeManager);

        $this->expectException(StoreScopeException::class);
        $provider->requireActive(5);
    }
}