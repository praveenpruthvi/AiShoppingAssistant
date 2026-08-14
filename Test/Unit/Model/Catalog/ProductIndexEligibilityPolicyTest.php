<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductEligibilityContext;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductIndexEligibilityPolicy;
use Magento\Catalog\Model\Product\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductIndexEligibilityPolicy::class)]
final class ProductIndexEligibilityPolicyTest extends TestCase
{
    private ProductIndexEligibilityPolicy $policy;
    private CatalogSnapshotFactory $factory;

    protected function setUp(): void
    {
        $this->policy = new ProductIndexEligibilityPolicy();
        $this->factory = new CatalogSnapshotFactory();
    }

    public function testEligibleProductIsAccepted(): void
    {
        $result = $this->policy->evaluate(
            $this->factory->create(),
            new ProductEligibilityContext(1, 1)
        );

        self::assertTrue($result->eligible());
        self::assertSame(ProductEligibilityResultInterface::REASON_ELIGIBLE, $result->reasonCode());
    }

    public function testSearchVisibleOnlyProductIsAccepted(): void
    {
        $result = $this->policy->evaluate(
            $this->factory->create(['visibility' => Visibility::VISIBILITY_IN_SEARCH]),
            new ProductEligibilityContext(1, 1)
        );

        self::assertTrue($result->eligible());
    }

    public function testCatalogOnlyVisibilityIsRejected(): void
    {
        $result = $this->policy->evaluate(
            $this->factory->create(['visibility' => Visibility::VISIBILITY_IN_CATALOG]),
            new ProductEligibilityContext(1, 1)
        );

        self::assertFalse($result->eligible());
        self::assertSame(ProductEligibilityResultInterface::REASON_NOT_SEARCH_VISIBLE, $result->reasonCode());
    }

    public function testNotVisibleProductIsRejected(): void
    {
        $result = $this->policy->evaluate(
            $this->factory->create(['visibility' => Visibility::VISIBILITY_NOT_VISIBLE]),
            new ProductEligibilityContext(1, 1)
        );

        self::assertFalse($result->eligible());
        self::assertSame(ProductEligibilityResultInterface::REASON_NOT_SEARCH_VISIBLE, $result->reasonCode());
    }

    public function testDisabledProductIsRejected(): void
    {
        $result = $this->policy->evaluate(
            $this->factory->create(['isEnabled' => false]),
            new ProductEligibilityContext(1, 1)
        );

        self::assertFalse($result->eligible());
        self::assertSame(ProductEligibilityResultInterface::REASON_DISABLED, $result->reasonCode());
    }

    public function testStoreMismatchIsRejected(): void
    {
        $result = $this->policy->evaluate(
            $this->factory->create(['storeId' => 5]),
            new ProductEligibilityContext(1, 1)
        );

        self::assertFalse($result->eligible());
        self::assertSame(ProductEligibilityResultInterface::REASON_STORE_MISMATCH, $result->reasonCode());
    }

    public function testWebsiteNotAssignedIsRejected(): void
    {
        $result = $this->policy->evaluate(
            $this->factory->create(['websiteIds' => [9]]),
            new ProductEligibilityContext(1, 1)
        );

        self::assertFalse($result->eligible());
        self::assertSame(ProductEligibilityResultInterface::REASON_WEBSITE_NOT_ASSIGNED, $result->reasonCode());
    }

    public function testProductAssignedToAnyWebsiteIsEligible(): void
    {
        $result = $this->policy->evaluate(
            $this->factory->create(['websiteIds' => [1, 9]]),
            new ProductEligibilityContext(1, 9)
        );

        self::assertTrue($result->eligible());
    }

    public function testInvalidIdentityIsRejected(): void
    {
        $snapshot = $this->createStub(ProductSnapshotInterface::class);
        $snapshot->method('entityId')->willReturn(0);
        $snapshot->method('storeId')->willReturn(1);
        $snapshot->method('sku')->willReturn('SKU-42');
        $snapshot->method('productType')->willReturn('simple');
        $snapshot->method('websiteIds')->willReturn([1]);
        $snapshot->method('isEnabled')->willReturn(true);
        $snapshot->method('visibility')->willReturn(Visibility::VISIBILITY_BOTH);

        $result = $this->policy->evaluate($snapshot, new ProductEligibilityContext(1, 1));

        self::assertFalse($result->eligible());
        self::assertSame(ProductEligibilityResultInterface::REASON_INVALID_IDENTITY, $result->reasonCode());
    }
}
