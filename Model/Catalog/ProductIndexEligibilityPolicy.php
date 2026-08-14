<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityContextInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductIndexEligibilityPolicyInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotInterface;
use Magento\Catalog\Model\Product\Visibility;

/**
 * Deterministic store/website scope and visibility gate for product indexing.
 *
 * Uses Magento's real constants (Visibility::VISIBILITY_IN_SEARCH and
 * Visibility::VISIBILITY_BOTH) instead of magic numbers. The policy never
 * inspects content; content sanitization happens downstream.
 */
final class ProductIndexEligibilityPolicy implements ProductIndexEligibilityPolicyInterface
{
    public function evaluate(
        ProductSnapshotInterface $snapshot,
        ProductEligibilityContextInterface $context
    ): ProductEligibilityResultInterface {
        if (
            $snapshot->entityId() < 1
            || $snapshot->storeId() < 1
            || $snapshot->sku() === ''
            || $snapshot->productType() === ''
            || $context->storeId() < 1
            || $context->websiteId() < 1
        ) {
            return new ProductEligibilityResult(ProductEligibilityResultInterface::REASON_INVALID_IDENTITY);
        }

        if ($snapshot->storeId() !== $context->storeId()) {
            return new ProductEligibilityResult(ProductEligibilityResultInterface::REASON_STORE_MISMATCH);
        }

        if (!in_array($context->websiteId(), $snapshot->websiteIds(), true)) {
            return new ProductEligibilityResult(ProductEligibilityResultInterface::REASON_WEBSITE_NOT_ASSIGNED);
        }

        if (!$snapshot->isEnabled()) {
            return new ProductEligibilityResult(ProductEligibilityResultInterface::REASON_DISABLED);
        }

        if (
            $snapshot->visibility() !== Visibility::VISIBILITY_IN_SEARCH
            && $snapshot->visibility() !== Visibility::VISIBILITY_BOTH
        ) {
            return new ProductEligibilityResult(ProductEligibilityResultInterface::REASON_NOT_SEARCH_VISIBLE);
        }

        return new ProductEligibilityResult(ProductEligibilityResultInterface::REASON_ELIGIBLE);
    }
}
