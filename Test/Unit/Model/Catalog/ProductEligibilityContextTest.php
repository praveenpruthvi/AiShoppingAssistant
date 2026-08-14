<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductEligibilityContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductEligibilityContext::class)]
final class ProductEligibilityContextTest extends TestCase
{
    public function testAcceptsValidContext(): void
    {
        $context = new ProductEligibilityContext(1, 2);

        self::assertSame(1, $context->storeId());
        self::assertSame(2, $context->websiteId());
    }

    public function testRejectsNonPositiveStoreId(): void
    {
        $this->expectException(CatalogException::class);

        new ProductEligibilityContext(0, 2);
    }

    public function testRejectsNonPositiveWebsiteId(): void
    {
        $this->expectException(CatalogException::class);

        new ProductEligibilityContext(1, 0);
    }
}
