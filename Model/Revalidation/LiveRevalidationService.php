<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Revalidation;

use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Block\Product\ImageFactory as ProductImageFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Customer\Model\Group;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Live, store-scoped, customer-group-aware revalidation against Magento
 * itself.
 *
 * Reuses the existing Model\Indexing\Clock\ClockInterface abstraction
 * (rather than calling gmdate()/new DateTimeImmutable() directly) so
 * verifiedAt stays deterministically testable, matching the pattern already
 * established for durable-ledger/rebuild-fence timestamps.
 *
 * Per-SKU failures (not found, disabled, not visible, not on this website,
 * not salable) are expected, ordinary outcomes and are silently dropped —
 * not exceptions. Unexpected infrastructure failures (repository/stock
 * lookups throwing something other than "not found") are not caught here
 * and propagate, since silently dropping those would hide a real system
 * problem rather than a stale candidate.
 */
final class LiveRevalidationService implements LiveRevalidationServiceInterface
{
    /**
     * A compact-but-legible size for a chat product card — bigger than
     * Luma's 75x75 product_thumbnail_image (too small to be useful next to
     * a name/price), smaller than the 240x300 category_page_grid image
     * (would dominate a narrow chat bubble). Matches Luma's own
     * product_small_image conversion, so it reuses an already-generated
     * cache bucket rather than forcing a new resize job.
     */
    private const IMAGE_ID = 'product_small_image';

    public function __construct(
        private readonly StoreScopeProviderInterface $storeScopeProvider,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ClockInterface $clock,
        private readonly ProductImageFactory $productImageFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function revalidate(int $storeId, ?int $customerGroupId, array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        $scope = $this->storeScopeProvider->requireActive($storeId);
        $resolvedGroupId = $customerGroupId ?? Group::NOT_LOGGED_IN_ID;
        $verifiedAt = $this->clock->now()->format(DATE_ATOM);

        $results = [];
        foreach (array_unique($skus) as $sku) {
            $revalidated = $this->revalidateOne($sku, $scope, $resolvedGroupId, $verifiedAt);
            if ($revalidated !== null) {
                $results[] = $revalidated;
            }
        }

        return $results;
    }

    public function checkAvailability(int $storeId, ?int $customerGroupId, array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        $scope = $this->storeScopeProvider->requireActive($storeId);

        // Reuses the existing, already-tested revalidate() as-is (never
        // duplicated/modified) to determine what's fully available; only
        // the remainder needs a distinct existence check.
        $available = $this->revalidate($storeId, $customerGroupId, $skus);
        $availableBySku = [];
        foreach ($available as $product) {
            $availableBySku[$product->sku] = $product;
        }

        $results = [];
        foreach (array_unique($skus) as $sku) {
            if (isset($availableBySku[$sku])) {
                $results[] = new AvailabilityStatus($sku, true, true, $availableBySku[$sku]->name);
                continue;
            }

            $results[] = $this->checkExistenceOnly($sku, $scope);
        }

        return $results;
    }

    private function checkExistenceOnly(string $sku, StoreScopeInterface $scope): AvailabilityStatus
    {
        try {
            $product = $this->productRepository->get($sku, false, $scope->storeId(), true);
        } catch (NoSuchEntityException) {
            return new AvailabilityStatus($sku, false, false, null);
        }

        return new AvailabilityStatus($sku, true, false, (string)$product->getName());
    }

    private function revalidateOne(
        string $sku,
        StoreScopeInterface $scope,
        int $customerGroupId,
        string $verifiedAt
    ): ?RevalidatedProduct {
        try {
            $product = $this->productRepository->get($sku, false, $scope->storeId(), true);
        } catch (NoSuchEntityException) {
            return null;
        }

        if (!$this->isAvailable($product, $scope)) {
            return null;
        }

        $product->setCustomerGroupId($customerGroupId);

        // Product::getPrice()/getFinalPrice() return the product's own raw
        // `price` attribute for a configurable product, which is 0/unset —
        // the sellable price only exists on its child simple products.
        // getPriceInfo() dispatches through the type-specific pricing model
        // registered for "configurable" (ConfigurablePriceResolver), which
        // resolves to the minimum salable child's price — the same "as low
        // as" value Magento's own PDP/catalog listing show. For a simple
        // product this resolves to the identical value getPrice()/
        // getFinalPrice() already returned, so this is a strict
        // generalization, not a configurable-specific branch.
        $priceInfo = $product->getPriceInfo();
        $regularPrice = $priceInfo->getPrice(RegularPrice::PRICE_CODE)->getAmount()->getValue();
        $finalPrice = $priceInfo->getPrice(FinalPrice::PRICE_CODE)->getAmount()->getValue();

        if (!is_numeric($finalPrice) || !is_numeric($regularPrice) || (float)$finalPrice < 0.0) {
            return null;
        }

        $regularPrice = (float)$regularPrice;
        $finalPrice = (float)$finalPrice;
        $specialPrice = $finalPrice < $regularPrice ? $finalPrice : null;

        return new RevalidatedProduct(
            (int)$product->getId(),
            $sku,
            (string)$product->getName(),
            $regularPrice,
            $specialPrice,
            (string)$product->getProductUrl(),
            $verifiedAt,
            $this->resolveImageUrl($product)
        );
    }

    /**
     * Image resolution is a display enhancement, never a revalidation
     * concern — a product with no base image, or a resize/URL-building
     * failure in Magento's own image pipeline, must never drop an
     * otherwise-available, correctly-priced product from the result set.
     * Any failure here is logged and degrades to null, exactly like a
     * product genuinely having no image.
     *
     * Deliberately uses Block\Product\ImageFactory (the same
     * non-deprecated, lazy URL-building path Luma's own PDP/category
     * templates use via `$block->getImage()`) rather than the older
     * Helper\Image::init()->getUrl() — that legacy path performs a
     * synchronous, eager file-existence check and resize at URL-build
     * time, and returned an obviously-broken placeholder URL for real,
     * on-disk product images when exercised outside of a full block/
     * layout render, confirmed via a live storefront check before this
     * was chosen instead. ImageFactory never touches the filesystem: it
     * always returns a valid URL (the real resized image, or Magento's
     * own placeholder image for a product with no base image at all —
     * the same honest, live fact the rest of the site already shows),
     * with any actual resizing happening lazily on first real HTTP
     * request to that URL, exactly like every other product image on
     * this store.
     */
    private function resolveImageUrl(Product $product): ?string
    {
        try {
            $url = $this->productImageFactory->create($product, self::IMAGE_ID)->getImageUrl();
        } catch (Throwable $exception) {
            $this->logger->warning('AI shopping assistant: product image URL resolution failed.', [
                'sku' => $product->getSku(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function isAvailable(Product $product, StoreScopeInterface $scope): bool
    {
        if ((int)$product->getStatus() !== Status::STATUS_ENABLED) {
            return false;
        }

        if (!in_array((int)$product->getVisibility(), [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH], true)) {
            return false;
        }

        $websiteIds = array_map('intval', $product->getWebsiteIds() ?? []);
        if (!in_array($scope->websiteId(), $websiteIds, true)) {
            return false;
        }

        $stockItem = $this->stockRegistry->getStockItem((int)$product->getId(), $scope->websiteId());
        if (!$stockItem->getIsInStock()) {
            return false;
        }

        return $product->isSalable();
    }
}
