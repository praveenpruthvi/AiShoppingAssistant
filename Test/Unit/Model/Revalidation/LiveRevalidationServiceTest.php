<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Revalidation;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\LiveRevalidationService;
use Aavirbhava\AiShoppingAssistant\Model\Store\Exception\StoreScopeException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Block\Product\Image as ImageBlock;
use Magento\Catalog\Block\Product\ImageFactory as ProductImageFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Customer\Model\Group;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(LiveRevalidationService::class)]
final class LiveRevalidationServiceTest extends TestCase
{
    private const STORE_ID = 3;
    private const WEBSITE_ID = 1;
    private const VERIFIED_AT = '2026-08-16T00:00:00+00:00';
    private const IMAGE_URL = 'https://store.test/media/catalog/product/cache/blue-shoe.jpg';

    private function product(
        bool $enabled = true,
        int $visibility = Visibility::VISIBILITY_BOTH,
        array $websiteIds = [self::WEBSITE_ID],
        bool $salable = true,
        float $price = 49.99,
        float $finalPrice = 49.99
    ): Product {
        // setCustomerGroupId()/getCustomerGroupId() are DataObject magic
        // accessors (not declared methods), so createMock() alone can't
        // stub them — addMethods() is required to make them mockable.
        $product = $this->getMockBuilder(Product::class)
            ->onlyMethods([
                'getId', 'getStatus', 'getVisibility', 'getWebsiteIds', 'isSalable',
                'getName', 'getProductUrl', 'getPriceInfo', 'getSku',
            ])
            ->addMethods(['setCustomerGroupId'])
            ->disableOriginalConstructor()
            ->getMock();
        $product->method('getId')->willReturn(101);
        $product->method('getStatus')->willReturn($enabled ? Status::STATUS_ENABLED : Status::STATUS_DISABLED);
        $product->method('getVisibility')->willReturn($visibility);
        $product->method('getWebsiteIds')->willReturn($websiteIds);
        $product->method('isSalable')->willReturn($salable);
        $product->method('getName')->willReturn('Blue Shoe');
        $product->method('getProductUrl')->willReturn('https://store.test/blue-shoe');
        $product->method('getPriceInfo')->willReturn($this->priceInfo($price, $finalPrice));
        $product->method('getSku')->willReturn('SKU-1');

        return $product;
    }

    private function productImageFactoryReturning(?string $url): ProductImageFactory
    {
        $imageBlock = $this->getMockBuilder(ImageBlock::class)
            ->addMethods(['getImageUrl'])
            ->disableOriginalConstructor()
            ->getMock();
        $imageBlock->method('getImageUrl')->willReturn($url);

        $factory = $this->createMock(ProductImageFactory::class);
        $factory->method('create')->willReturn($imageBlock);

        return $factory;
    }

    private function throwingProductImageFactory(): ProductImageFactory
    {
        $factory = $this->createMock(ProductImageFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('image resolution failed'));

        return $factory;
    }

    /**
     * Mirrors what Product::getPriceInfo() dispatches through in real
     * Magento for both simple and configurable products — see
     * LiveRevalidationService's own docblock at the price-resolution call
     * site for why this replaced mocking getPrice()/getFinalPrice()
     * directly.
     */
    private function priceInfo(float $regularPrice, float $finalPrice): PriceInfoInterface
    {
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturnCallback(
            function (string $code) use ($regularPrice, $finalPrice): PriceInterface {
                $value = $code === RegularPrice::PRICE_CODE ? $regularPrice : $finalPrice;

                $amount = $this->createMock(AmountInterface::class);
                $amount->method('getValue')->willReturn($value);

                $price = $this->createMock(PriceInterface::class);
                $price->method('getAmount')->willReturn($amount);

                return $price;
            }
        );

        return $priceInfo;
    }

    private function inStockItem(bool $inStock = true): StockItemInterface
    {
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn($inStock);

        return $stockItem;
    }

    private function service(
        ProductRepositoryInterface $repository,
        ?StockRegistryInterface $stockRegistry = null,
        ?StoreScopeProviderInterface $storeScope = null,
        ?ProductImageFactory $productImageFactory = null
    ): LiveRevalidationService {
        $stockRegistry ??= $this->stockRegistryReturning($this->inStockItem());

        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable(self::VERIFIED_AT));

        return new LiveRevalidationService(
            $storeScope ?? $this->activeStoreScope(),
            $repository,
            $stockRegistry,
            $clock,
            $productImageFactory ?? $this->productImageFactoryReturning(self::IMAGE_URL),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function activeStoreScope(): StoreScopeProviderInterface
    {
        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn(self::STORE_ID);
        $scope->method('websiteId')->willReturn(self::WEBSITE_ID);

        $storeScope = $this->createMock(StoreScopeProviderInterface::class);
        $storeScope->method('requireActive')->with(self::STORE_ID)->willReturn($scope);

        return $storeScope;
    }

    private function stockRegistryReturning(StockItemInterface $stockItem): StockRegistryInterface
    {
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItem')->willReturn($stockItem);

        return $stockRegistry;
    }

    public function testEmptySkuListReturnsEmptyWithoutTouchingTheRepository(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::never())->method('get');

        $service = $this->service($repository);

        self::assertSame([], $service->revalidate(self::STORE_ID, null, []));
    }

    public function testAvailableProductIsReturnedWithLiveData(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->with('SKU-1', false, self::STORE_ID, true)->willReturn($this->product());

        $service = $this->service($repository);

        $results = $service->revalidate(self::STORE_ID, null, ['SKU-1']);

        self::assertCount(1, $results);
        self::assertSame('SKU-1', $results[0]->sku);
        self::assertSame(101, $results[0]->entityId);
        self::assertSame('Blue Shoe', $results[0]->name);
        self::assertSame(49.99, $results[0]->price);
        self::assertNull($results[0]->specialPrice);
        self::assertSame('https://store.test/blue-shoe', $results[0]->url);
        self::assertSame(self::VERIFIED_AT, $results[0]->verifiedAt);
        self::assertSame(self::IMAGE_URL, $results[0]->imageUrl);
    }

    public function testImageUrlIsNullWhenTheImageFactoryReturnsNoUsableUrl(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($this->product());

        $service = $this->service($repository, productImageFactory: $this->productImageFactoryReturning(''));

        $results = $service->revalidate(self::STORE_ID, null, ['SKU-1']);

        self::assertNull($results[0]->imageUrl);
    }

    public function testImageUrlIsNullWithoutDroppingTheProductWhenImageResolutionThrows(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($this->product());

        $service = $this->service($repository, productImageFactory: $this->throwingProductImageFactory());

        $results = $service->revalidate(self::STORE_ID, null, ['SKU-1']);

        self::assertCount(1, $results);
        self::assertSame('SKU-1', $results[0]->sku);
        self::assertNull($results[0]->imageUrl);
    }

    public function testFinalPriceBelowRegularPriceBecomesSpecialPrice(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($this->product(price: 49.99, finalPrice: 39.99));

        $service = $this->service($repository);

        $results = $service->revalidate(self::STORE_ID, null, ['SKU-1']);

        self::assertSame(49.99, $results[0]->price);
        self::assertSame(39.99, $results[0]->specialPrice);
    }

    /**
     * A configurable product's own `price` attribute is 0/unset — only its
     * child simple products carry a real price — so the price this test
     * asserts on can only come from Product::getPriceInfo() dispatching to
     * the type-specific pricing model (minimum salable child price, exactly
     * Magento's own "As low as" PDP/catalog-listing logic), never from the
     * raw getPrice()/getFinalPrice() accessors this class no longer calls.
     * Directly regression-guards the fix: reverting to getPrice()/
     * getFinalPrice() would make this assert 0.0, not 22.00.
     */
    public function testConfigurableProductPriceUsesTheAsLowAsPriceInfoResolution(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($this->product(price: 22.00, finalPrice: 22.00));

        $service = $this->service($repository);

        $results = $service->revalidate(self::STORE_ID, null, ['SKU-1']);

        self::assertSame(22.00, $results[0]->price);
        self::assertNull($results[0]->specialPrice);
    }

    public function testSetsTheResolvedCustomerGroupBeforeReadingPrice(): void
    {
        $product = $this->product();
        $product->expects(self::once())->method('setCustomerGroupId')->with(55);

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($product);

        $service = $this->service($repository);
        $service->revalidate(self::STORE_ID, 55, ['SKU-1']);
    }

    public function testNullCustomerGroupResolvesToNotLoggedIn(): void
    {
        $product = $this->product();
        $product->expects(self::once())->method('setCustomerGroupId')->with(Group::NOT_LOGGED_IN_ID);

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($product);

        $service = $this->service($repository);
        $service->revalidate(self::STORE_ID, null, ['SKU-1']);
    }

    public function testMissingSkuIsDroppedNotThrown(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willThrowException(new NoSuchEntityException(new Phrase('not found')));

        $service = $this->service($repository);

        self::assertSame([], $service->revalidate(self::STORE_ID, null, ['SKU-GONE']));
    }

    public function testDisabledProductIsDropped(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($this->product(enabled: false));

        $service = $this->service($repository);

        self::assertSame([], $service->revalidate(self::STORE_ID, null, ['SKU-1']));
    }

    public function testNotSearchVisibleProductIsDropped(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($this->product(visibility: Visibility::VISIBILITY_NOT_VISIBLE));

        $service = $this->service($repository);

        self::assertSame([], $service->revalidate(self::STORE_ID, null, ['SKU-1']));
    }

    public function testCatalogOnlyVisibilityIsDropped(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($this->product(visibility: Visibility::VISIBILITY_IN_CATALOG));

        $service = $this->service($repository);

        self::assertSame([], $service->revalidate(self::STORE_ID, null, ['SKU-1']));
    }

    public function testProductNotAssignedToTheStoreWebsiteIsDropped(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($this->product(websiteIds: [999]));

        $service = $this->service($repository);

        self::assertSame([], $service->revalidate(self::STORE_ID, null, ['SKU-1']));
    }

    public function testOutOfStockProductIsDropped(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($this->product());

        $service = $this->service($repository, $this->stockRegistryReturning($this->inStockItem(false)));

        self::assertSame([], $service->revalidate(self::STORE_ID, null, ['SKU-1']));
    }

    public function testNotSalableProductIsDropped(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($this->product(salable: false));

        $service = $this->service($repository);

        self::assertSame([], $service->revalidate(self::STORE_ID, null, ['SKU-1']));
    }

    public function testDuplicateSkusAreOnlyRevalidatedOnce(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::once())->method('get')->willReturn($this->product());

        $service = $this->service($repository);

        $results = $service->revalidate(self::STORE_ID, null, ['SKU-1', 'SKU-1']);

        self::assertCount(1, $results);
    }

    public function testOneMissingSkuDoesNotBlockOthersFromBeingReturned(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(
            function (string $sku) {
                if ($sku === 'SKU-GONE') {
                    throw new NoSuchEntityException(new Phrase('not found'));
                }

                return $this->product();
            }
        );

        $service = $this->service($repository);

        $results = $service->revalidate(self::STORE_ID, null, ['SKU-GONE', 'SKU-1']);

        self::assertCount(1, $results);
        self::assertSame('SKU-1', $results[0]->sku);
    }

    public function testCheckAvailabilityEmptySkuListReturnsEmptyWithoutTouchingTheRepository(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::never())->method('get');

        $service = $this->service($repository);

        self::assertSame([], $service->checkAvailability(self::STORE_ID, null, []));
    }

    public function testCheckAvailabilityReportsFoundAndInStockForAnAvailableProduct(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->with('SKU-1', false, self::STORE_ID, true)->willReturn($this->product());

        $service = $this->service($repository);

        $results = $service->checkAvailability(self::STORE_ID, null, ['SKU-1']);

        self::assertCount(1, $results);
        self::assertSame('SKU-1', $results[0]->sku);
        self::assertTrue($results[0]->found);
        self::assertTrue($results[0]->inStock);
        self::assertSame('Blue Shoe', $results[0]->name);
    }

    public function testCheckAvailabilityReportsFoundButNotInStockForAnOutOfStockProduct(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->with('SKU-1', false, self::STORE_ID, true)->willReturn($this->product());

        $service = $this->service($repository, $this->stockRegistryReturning($this->inStockItem(false)));

        $results = $service->checkAvailability(self::STORE_ID, null, ['SKU-1']);

        self::assertCount(1, $results);
        self::assertSame('SKU-1', $results[0]->sku);
        self::assertTrue($results[0]->found);
        self::assertFalse($results[0]->inStock);
        self::assertSame('Blue Shoe', $results[0]->name);
    }

    public function testCheckAvailabilityReportsNotFoundForAMissingSku(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willThrowException(new NoSuchEntityException(new Phrase('not found')));

        $service = $this->service($repository);

        $results = $service->checkAvailability(self::STORE_ID, null, ['SKU-GONE']);

        self::assertCount(1, $results);
        self::assertSame('SKU-GONE', $results[0]->sku);
        self::assertFalse($results[0]->found);
        self::assertFalse($results[0]->inStock);
        self::assertNull($results[0]->name);
    }

    public function testCheckAvailabilityReportsOneEntryPerRequestedSkuEvenWhenMixed(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(
            function (string $sku) {
                if ($sku === 'SKU-GONE') {
                    throw new NoSuchEntityException(new Phrase('not found'));
                }

                return $this->product();
            }
        );

        $service = $this->service($repository);

        $results = $service->checkAvailability(self::STORE_ID, null, ['SKU-1', 'SKU-GONE']);

        self::assertCount(2, $results);
        self::assertSame('SKU-1', $results[0]->sku);
        self::assertTrue($results[0]->found);
        self::assertSame('SKU-GONE', $results[1]->sku);
        self::assertFalse($results[1]->found);
    }

    public function testCheckAvailabilityReportsFoundButNotInStockForADisabledProduct(): void
    {
        // A disabled product fails revalidate()'s availability checks (so
        // it's absent from the "available" set) but still exists in the
        // repository — checkExistenceOnly() must report it as found, not
        // dropped entirely, matching AvailabilityStatus's documented intent
        // of collapsing every "not currently available" reason into one
        // signal rather than making it indistinguishable from not-found.
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->with('SKU-1', false, self::STORE_ID, true)->willReturn($this->product(enabled: false));

        $service = $this->service($repository);

        $results = $service->checkAvailability(self::STORE_ID, null, ['SKU-1']);

        self::assertCount(1, $results);
        self::assertSame('SKU-1', $results[0]->sku);
        self::assertTrue($results[0]->found);
        self::assertFalse($results[0]->inStock);
        self::assertSame('Blue Shoe', $results[0]->name);
    }

    public function testCheckAvailabilityFailsClosedOnInactiveStore(): void
    {
        $storeScope = $this->createMock(StoreScopeProviderInterface::class);
        $storeScope->method('requireActive')->willThrowException(new StoreScopeException(new Phrase('inactive')));

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::never())->method('get');

        $service = $this->service($repository, storeScope: $storeScope);

        $this->expectException(StoreScopeException::class);
        $service->checkAvailability(self::STORE_ID, null, ['SKU-1']);
    }

    public function testInactiveStoreFailsClosedBeforeAnyRepositoryCall(): void
    {
        $storeScope = $this->createMock(StoreScopeProviderInterface::class);
        $storeScope->method('requireActive')->willThrowException(new StoreScopeException(new Phrase('inactive')));

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::never())->method('get');

        $service = $this->service($repository, storeScope: $storeScope);

        $this->expectException(StoreScopeException::class);
        $service->revalidate(self::STORE_ID, null, ['SKU-1']);
    }
}
