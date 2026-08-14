<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityResultInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductEligibilityResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductEligibilityResult::class)]
final class ProductEligibilityResultTest extends TestCase
{
    public function testEligibleReasonMarksEligible(): void
    {
        $result = new ProductEligibilityResult(ProductEligibilityResultInterface::REASON_ELIGIBLE);

        self::assertTrue($result->eligible());
    }

    public function testNonEligibleReasonIsNotEligible(): void
    {
        $result = new ProductEligibilityResult(ProductEligibilityResultInterface::REASON_DISABLED);

        self::assertFalse($result->eligible());
        self::assertSame(ProductEligibilityResultInterface::REASON_DISABLED, $result->reasonCode());
    }

    public function testRejectsUnknownReasonCode(): void
    {
        $this->expectException(CatalogException::class);

        new ProductEligibilityResult('not_a_real_reason');
    }

    public function testValidReasonCodesContainAllConstants(): void
    {
        $codes = ProductEligibilityResult::validReasonCodes();

        foreach ([
            ProductEligibilityResultInterface::REASON_ELIGIBLE,
            ProductEligibilityResultInterface::REASON_INVALID_IDENTITY,
            ProductEligibilityResultInterface::REASON_STORE_MISMATCH,
            ProductEligibilityResultInterface::REASON_WEBSITE_NOT_ASSIGNED,
            ProductEligibilityResultInterface::REASON_DISABLED,
            ProductEligibilityResultInterface::REASON_NOT_SEARCH_VISIBLE,
        ] as $expected) {
            self::assertContains($expected, $codes);
        }
    }
}
