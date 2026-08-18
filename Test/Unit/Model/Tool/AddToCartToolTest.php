<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Cart\CartResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Cart\Exception\CartNotAvailableException;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Tool\AddToCartTool;
use Aavirbhava\AiShoppingAssistant\Model\Tool\CartMutationConfirmationService;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ProductFormatter;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Api\Data\ConfigurableItemOptionValueInterface;
use Magento\ConfigurableProduct\Api\Data\ConfigurableItemOptionValueInterfaceFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Api\Data\CartItemInterfaceFactory;
use Magento\Quote\Api\Data\ProductOptionExtensionFactory;
use Magento\Quote\Api\Data\ProductOptionExtensionInterface;
use Magento\Quote\Api\Data\ProductOptionInterface;
use Magento\Quote\Api\Data\ProductOptionInterfaceFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(AddToCartTool::class)]
final class AddToCartToolTest extends TestCase
{
    private const STORE_ID = 5;
    private const SIZE_ATTRIBUTE_ID = 141;
    private const COLOR_ATTRIBUTE_ID = 142;

    /**
     * @var array<string, string>
     */
    private array $cacheStore = [];

    public function testNameAndSchema(): void
    {
        $tool = $this->tool();

        self::assertSame('add_to_cart', $tool->name());
        self::assertSame(['sku', 'qty'], $tool->inputSchema()['required']);
        self::assertArrayHasKey('confirmation_token', $tool->inputSchema()['properties']);
        self::assertArrayHasKey('option_selection', $tool->inputSchema()['properties']);
        self::assertNotContains('confirmation_token', $tool->inputSchema()['required']);
        self::assertNotContains('option_selection', $tool->inputSchema()['required']);
    }

    public function testAuthorizeThrowsWhenCartMutationsAreDisabled(): void
    {
        $tool = $this->tool(cartMutationsEnabled: false);

        $this->expectException(ToolAuthorizationException::class);
        $tool->authorize(new ToolContext(self::STORE_ID, null));
    }

    public function testExecuteRejectsAMissingSku(): void
    {
        $tool = $this->tool();

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['qty' => 1]);

        self::assertSame(['status' => 'rejected', 'reason' => 'invalid_arguments'], $result->data);
    }

    public function testExecuteRejectsANonPositiveQty(): void
    {
        $tool = $this->tool();

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'SKU-1', 'qty' => 0]);

        self::assertSame(['status' => 'rejected', 'reason' => 'invalid_arguments'], $result->data);
    }

    public function testExecuteReportsCartNotAvailable(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willThrowException(new CartNotAvailableException(new Phrase('none')));

        $tool = $this->tool(cartResolver: $cartResolver);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'SKU-1', 'qty' => 1]);

        self::assertSame(['status' => 'cart_not_available'], $result->data);
    }

    public function testExecuteRejectsASkuThatFailsLiveRevalidation(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($this->cart());

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([]);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::never())->method('save');

        $tool = $this->tool(cartResolver: $cartResolver, revalidationService: $revalidationService, cartItemRepository: $cartItemRepository);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'SKU-1', 'qty' => 1]);

        self::assertSame(['status' => 'rejected', 'reason' => 'not_purchasable', 'sku' => 'SKU-1'], $result->data);
    }

    public function testFirstCallWithConfirmationRequiredNeverMutatesTheCart(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($this->cart());

        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::never())->method('save');

        $tool = $this->tool(cartResolver: $cartResolver, revalidationService: $revalidationService, cartItemRepository: $cartItemRepository, requireConfirmation: true);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'SKU-1', 'qty' => 1]);

        self::assertSame('confirmation_required', $result->data['status']);
        self::assertArrayHasKey('confirmation_token', $result->data);
        self::assertSame([], $result->verifiedProducts);
    }

    public function testAValidConfirmationTokenFromALaterTurnExecutesTheMutation(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($this->cart());

        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);

        $item = $this->createMock(CartItemInterface::class);
        $item->expects(self::once())->method('setQuoteId')->with(77);
        $item->expects(self::once())->method('setSku')->with('SKU-1');
        $item->expects(self::once())->method('setQty')->with(2);

        $factory = $this->createMock(CartItemInterfaceFactory::class);
        $factory->method('create')->willReturn($item);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::once())->method('save')->with($item);

        $tool = $this->tool(
            cartResolver: $cartResolver,
            revalidationService: $revalidationService,
            cartItemFactory: $factory,
            cartItemRepository: $cartItemRepository,
            requireConfirmation: true
        );

        $firstContext = new ToolContext(self::STORE_ID, null, null, 'turn-1');
        $proposeResult = $tool->execute($firstContext, ['sku' => 'SKU-1', 'qty' => 2]);
        $token = $proposeResult->data['confirmation_token'];

        $secondContext = new ToolContext(self::STORE_ID, null, null, 'turn-2');
        $confirmResult = $tool->execute($secondContext, ['sku' => 'SKU-1', 'qty' => 2, 'confirmation_token' => $token]);

        self::assertSame('added', $confirmResult->data['status']);
        self::assertSame([$verified], $confirmResult->verifiedProducts);
    }

    public function testRedeemingTheTokenInTheSameTurnDoesNotExecuteAndReturnsAFreshConfirmationRequest(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($this->cart());

        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::never())->method('save');

        $tool = $this->tool(cartResolver: $cartResolver, revalidationService: $revalidationService, cartItemRepository: $cartItemRepository, requireConfirmation: true);

        $context = new ToolContext(self::STORE_ID, null, null, 'same-turn');
        $proposeResult = $tool->execute($context, ['sku' => 'SKU-1', 'qty' => 2]);
        $token = $proposeResult->data['confirmation_token'];

        $secondAttempt = $tool->execute($context, ['sku' => 'SKU-1', 'qty' => 2, 'confirmation_token' => $token]);

        self::assertSame('confirmation_required', $secondAttempt->data['status']);
    }

    public function testAMismatchedTokenDoesNotExecuteTheMutation(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($this->cart());

        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::never())->method('save');

        $tool = $this->tool(cartResolver: $cartResolver, revalidationService: $revalidationService, cartItemRepository: $cartItemRepository, requireConfirmation: true);

        $firstContext = new ToolContext(self::STORE_ID, null, null, 'turn-1');
        $proposeResult = $tool->execute($firstContext, ['sku' => 'SKU-1', 'qty' => 2]);
        $token = $proposeResult->data['confirmation_token'];

        // A different qty than what the token was minted for.
        $secondContext = new ToolContext(self::STORE_ID, null, null, 'turn-2');
        $result = $tool->execute($secondContext, ['sku' => 'SKU-1', 'qty' => 5, 'confirmation_token' => $token]);

        self::assertSame('confirmation_required', $result->data['status']);
    }

    public function testExecutesImmediatelyWhenConfirmationIsNotRequired(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($this->cart());

        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);

        $item = $this->createMock(CartItemInterface::class);
        $factory = $this->createMock(CartItemInterfaceFactory::class);
        $factory->method('create')->willReturn($item);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::once())->method('save')->with($item);

        $tool = $this->tool(
            cartResolver: $cartResolver,
            revalidationService: $revalidationService,
            cartItemFactory: $factory,
            cartItemRepository: $cartItemRepository,
            requireConfirmation: false
        );

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'SKU-1', 'qty' => 1]);

        self::assertSame('added', $result->data['status']);
    }

    public function testCartUpdateFailureIsReportedCleanlyNotThrown(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($this->cart());

        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);

        $factory = $this->createMock(CartItemInterfaceFactory::class);
        $factory->method('create')->willReturn($this->createMock(CartItemInterface::class));

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->method('save')->willThrowException(new \RuntimeException('db exploded'));

        $tool = $this->tool(
            cartResolver: $cartResolver,
            revalidationService: $revalidationService,
            cartItemFactory: $factory,
            cartItemRepository: $cartItemRepository,
            requireConfirmation: false
        );

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'SKU-1', 'qty' => 1]);

        self::assertSame(['status' => 'failed', 'reason' => 'cart_update_failed', 'sku' => 'SKU-1'], $result->data);
    }

    public function testConfigurableProductWithoutOptionSelectionReturnsNeedsOptions(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($this->cart());

        $verified = new RevalidatedProduct(1, 'CONF-1', 'Tank Top', 22.0, null, 'https://store.test/tank-top', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::never())->method('save');

        $tool = $this->tool(
            cartResolver: $cartResolver,
            revalidationService: $revalidationService,
            cartItemRepository: $cartItemRepository,
            productRepository: $this->configurableProductRepository(),
            configurableType: $this->configurableType()
        );

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'CONF-1', 'qty' => 1]);

        self::assertSame('needs_options', $result->data['status']);
        self::assertSame('CONF-1', $result->data['sku']);
        self::assertCount(2, $result->data['option_types']);
        self::assertSame('Size', $result->data['option_types'][0]['attribute']);
        self::assertSame(['Small', 'Large'], $result->data['option_types'][0]['values']);
    }

    public function testConfigurableProductWithUnrecognizedOptionPhraseIsRejected(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($this->cart());

        $verified = new RevalidatedProduct(1, 'CONF-1', 'Tank Top', 22.0, null, 'https://store.test/tank-top', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::never())->method('save');

        $tool = $this->tool(
            cartResolver: $cartResolver,
            revalidationService: $revalidationService,
            cartItemRepository: $cartItemRepository,
            productRepository: $this->configurableProductRepository(),
            configurableType: $this->configurableType()
        );

        $result = $tool->execute(
            new ToolContext(self::STORE_ID, null),
            ['sku' => 'CONF-1', 'qty' => 1, 'option_selection' => 'XXL, teal']
        );

        self::assertSame('rejected', $result->data['status']);
        self::assertSame('invalid_option', $result->data['reason']);
        self::assertSame('XXL', $result->data['invalid_value']);
    }

    public function testConfigurableProductWithAPartialOptionSelectionStillNeedsOptions(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($this->cart());

        $verified = new RevalidatedProduct(1, 'CONF-1', 'Tank Top', 22.0, null, 'https://store.test/tank-top', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);

        $tool = $this->tool(
            cartResolver: $cartResolver,
            revalidationService: $revalidationService,
            productRepository: $this->configurableProductRepository(),
            configurableType: $this->configurableType()
        );

        $result = $tool->execute(
            new ToolContext(self::STORE_ID, null),
            ['sku' => 'CONF-1', 'qty' => 1, 'option_selection' => 'Large']
        );

        self::assertSame('needs_options', $result->data['status']);
    }

    public function testConfigurableProductResolvesTolerantPhraseToTheMatchingChildAndAdds(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($this->cart());

        $verified = new RevalidatedProduct(1, 'CONF-1', 'Tank Top', 22.0, null, 'https://store.test/tank-top', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);

        $item = $this->createMock(CartItemInterface::class);
        $item->expects(self::once())->method('setSku')->with('CONF-1');
        $capturedProductOption = null;
        $item->expects(self::once())->method('setProductOption')->willReturnCallback(
            function (ProductOptionInterface $option) use (&$capturedProductOption): void {
                $capturedProductOption = $option;
            }
        );

        $factory = $this->createMock(CartItemInterfaceFactory::class);
        $factory->method('create')->willReturn($item);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::once())->method('save')->with($item);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItem')->willReturn($stockItem);

        $capturedOptions = [];
        $optionValueFactory = $this->createMock(ConfigurableItemOptionValueInterfaceFactory::class);
        $optionValueFactory->method('create')->willReturnCallback(function () use (&$capturedOptions) {
            $option = $this->createMock(ConfigurableItemOptionValueInterface::class);
            $option->method('setOptionId')->willReturnCallback(function (int $id) use ($option, &$capturedOptions) {
                $capturedOptions[spl_object_id($option)]['optionId'] = $id;
                return $option;
            });
            $option->method('setOptionValue')->willReturnCallback(function ($value) use ($option, &$capturedOptions) {
                $capturedOptions[spl_object_id($option)]['optionValue'] = $value;
                return $option;
            });

            return $option;
        });

        $tool = $this->tool(
            cartResolver: $cartResolver,
            revalidationService: $revalidationService,
            cartItemFactory: $factory,
            cartItemRepository: $cartItemRepository,
            productRepository: $this->configurableProductRepository(),
            configurableType: $this->configurableType(),
            stockRegistry: $stockRegistry,
            configurableItemOptionValueFactory: $optionValueFactory
        );

        $result = $tool->execute(
            new ToolContext(self::STORE_ID, null),
            ['sku' => 'CONF-1', 'qty' => 1, 'option_selection' => 'Large, pink one']
        );

        self::assertSame('added', $result->data['status']);
        self::assertNotNull($capturedProductOption);
        self::assertCount(2, $capturedOptions);
        $resolved = array_map(static fn (array $option): array => $option, array_values($capturedOptions));
        $byAttribute = [];
        foreach ($resolved as $option) {
            $byAttribute[$option['optionId']] = $option['optionValue'];
        }
        self::assertSame('11', (string) $byAttribute[self::SIZE_ATTRIBUTE_ID]);
        self::assertSame('21', (string) $byAttribute[self::COLOR_ATTRIBUTE_ID]);
    }

    public function testConfigurableProductWithAnOutOfStockChildIsRejectedAsNotPurchasable(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($this->cart());

        $verified = new RevalidatedProduct(1, 'CONF-1', 'Tank Top', 22.0, null, 'https://store.test/tank-top', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::never())->method('save');

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(false);
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItem')->willReturn($stockItem);

        $tool = $this->tool(
            cartResolver: $cartResolver,
            revalidationService: $revalidationService,
            cartItemRepository: $cartItemRepository,
            productRepository: $this->configurableProductRepository(),
            configurableType: $this->configurableType(),
            stockRegistry: $stockRegistry
        );

        $result = $tool->execute(
            new ToolContext(self::STORE_ID, null),
            ['sku' => 'CONF-1', 'qty' => 1, 'option_selection' => 'Large, Pink']
        );

        self::assertSame('rejected', $result->data['status']);
        self::assertSame('not_purchasable', $result->data['reason']);
    }

    private function cart(): CartInterface
    {
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getId')->willReturn(77);

        return $cart;
    }

    private function simpleProductRepository(): ProductRepositoryInterface
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(function (string $sku) {
            $product = $this->getMockBuilder(Product::class)
                ->onlyMethods(['getTypeId', 'getSku'])
                ->disableOriginalConstructor()
                ->getMock();
            $product->method('getTypeId')->willReturn('simple');
            $product->method('getSku')->willReturn($sku);

            return $product;
        });

        return $repository;
    }

    /**
     * A configurable parent ("CONF-1") with two attributes — Size (Small=10,
     * Large=11) and Color (Blue=20, Pink=21) — and two children exposing
     * those attribute values via getData('size')/getData('color'), matching
     * how Configurable::getUsedProducts() results are actually consumed.
     */
    private function configurableProductRepository(): ProductRepositoryInterface
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(function (string $sku) {
            $product = $this->getMockBuilder(Product::class)
                ->onlyMethods(['getTypeId', 'getSku'])
                ->disableOriginalConstructor()
                ->getMock();
            $product->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
            $product->method('getSku')->willReturn($sku);

            return $product;
        });

        return $repository;
    }

    private function configurableType(): Configurable
    {
        $configurableType = $this->createMock(Configurable::class);
        $configurableType->method('getConfigurableAttributesAsArray')->willReturn([
            self::SIZE_ATTRIBUTE_ID => [
                'attribute_id' => self::SIZE_ATTRIBUTE_ID,
                'attribute_code' => 'size',
                'label' => 'Size',
                'frontend_label' => 'Size',
                'values' => [
                    ['value_index' => '10', 'label' => 'Small'],
                    ['value_index' => '11', 'label' => 'Large'],
                ],
            ],
            self::COLOR_ATTRIBUTE_ID => [
                'attribute_id' => self::COLOR_ATTRIBUTE_ID,
                'attribute_code' => 'color',
                'label' => 'Color',
                'frontend_label' => 'Color',
                'values' => [
                    ['value_index' => '20', 'label' => 'Blue'],
                    ['value_index' => '21', 'label' => 'Pink'],
                ],
            ],
        ]);

        $largePink = $this->childProduct('CONF-1-LARGE-PINK', '11', '21');
        $smallBlue = $this->childProduct('CONF-1-SMALL-BLUE', '10', '20');
        $configurableType->method('getUsedProducts')->willReturn([$smallBlue, $largePink]);

        return $configurableType;
    }

    private function childProduct(string $sku, string $sizeValueIndex, string $colorValueIndex): Product
    {
        $child = $this->getMockBuilder(Product::class)
            ->onlyMethods(['getId', 'getSku', 'getData', 'isSalable'])
            ->disableOriginalConstructor()
            ->getMock();
        $child->method('getId')->willReturn(crc32($sku));
        $child->method('getSku')->willReturn($sku);
        $child->method('isSalable')->willReturn(true);
        $child->method('getData')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'size' => $sizeValueIndex,
                'color' => $colorValueIndex,
                default => null,
            }
        );

        return $child;
    }

    private function activeStoreScopeProvider(): StoreScopeProviderInterface
    {
        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('websiteId')->willReturn(1);

        $provider = $this->createMock(StoreScopeProviderInterface::class);
        $provider->method('requireActive')->with(self::STORE_ID)->willReturn($scope);

        return $provider;
    }

    private function tool(
        bool $cartMutationsEnabled = true,
        bool $requireConfirmation = false,
        ?CartResolverInterface $cartResolver = null,
        ?LiveRevalidationServiceInterface $revalidationService = null,
        ?CartItemInterfaceFactory $cartItemFactory = null,
        ?CartItemRepositoryInterface $cartItemRepository = null,
        ?ProductRepositoryInterface $productRepository = null,
        ?Configurable $configurableType = null,
        ?StockRegistryInterface $stockRegistry = null,
        ?ConfigurableItemOptionValueInterfaceFactory $configurableItemOptionValueFactory = null
    ): AddToCartTool {
        $guardrails = $this->createMock(GuardrailConfigInterface::class);
        $guardrails->method('areCartMutationsEnabled')->willReturn($cartMutationsEnabled);
        $guardrails->method('requiresCartConfirmation')->willReturn($requireConfirmation);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readGuardrails')->with(self::STORE_ID)->willReturn($guardrails);

        $productOptionExtension = $this->createMock(ProductOptionExtensionInterface::class);
        $productOptionExtension->method('setConfigurableItemOptions')->willReturnSelf();
        $productOptionExtensionFactory = $this->createMock(ProductOptionExtensionFactory::class);
        $productOptionExtensionFactory->method('create')->willReturn($productOptionExtension);

        $productOption = $this->createMock(ProductOptionInterface::class);
        $productOption->method('setExtensionAttributes')->willReturnSelf();
        $productOptionFactory = $this->createMock(ProductOptionInterfaceFactory::class);
        $productOptionFactory->method('create')->willReturn($productOption);

        return new AddToCartTool(
            $configurationReader,
            $cartResolver ?? $this->createMock(CartResolverInterface::class),
            $revalidationService ?? $this->createMock(LiveRevalidationServiceInterface::class),
            $this->confirmationService(),
            $cartItemFactory ?? $this->createMock(CartItemInterfaceFactory::class),
            $cartItemRepository ?? $this->createMock(CartItemRepositoryInterface::class),
            new ProductFormatter(),
            $productRepository ?? $this->simpleProductRepository(),
            $configurableType ?? $this->createMock(Configurable::class),
            $this->activeStoreScopeProvider(),
            $stockRegistry ?? $this->createMock(StockRegistryInterface::class),
            $productOptionFactory,
            $productOptionExtensionFactory,
            $configurableItemOptionValueFactory ?? $this->createMock(ConfigurableItemOptionValueInterfaceFactory::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function confirmationService(): CartMutationConfirmationService
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            fn (string $id) => $this->cacheStore[$id] ?? false
        );
        $cache->method('save')->willReturnCallback(
            function (string $data, string $id) {
                $this->cacheStore[$id] = $data;

                return true;
            }
        );
        $cache->method('remove')->willReturnCallback(
            function (string $id) {
                unset($this->cacheStore[$id]);

                return true;
            }
        );

        return new CartMutationConfirmationService($cache);
    }
}
