<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityResultInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductNormalizationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductNormalizationResult::class)]
final class ProductNormalizationResultTest extends TestCase
{
    public function testAcceptsEligibleResultWithDocument(): void
    {
        $document = $this->createStub(ProductDocumentInterface::class);
        $result = new ProductNormalizationResult(true, ProductEligibilityResultInterface::REASON_ELIGIBLE, $document);

        self::assertTrue($result->eligible());
        self::assertSame($document, $result->document());
    }

    public function testAcceptsIneligibleResultWithoutDocument(): void
    {
        $result = new ProductNormalizationResult(false, ProductEligibilityResultInterface::REASON_DISABLED, null);

        self::assertFalse($result->eligible());
        self::assertSame(ProductEligibilityResultInterface::REASON_DISABLED, $result->reasonCode());
        self::assertNull($result->document());
    }

    public function testRejectsReasonMismatchingEligibilityFlag(): void
    {
        $this->expectException(CatalogException::class);

        new ProductNormalizationResult(true, ProductEligibilityResultInterface::REASON_DISABLED, null);
    }

    public function testRejectsDocumentForIneligibleResult(): void
    {
        $this->expectException(CatalogException::class);

        new ProductNormalizationResult(false, ProductEligibilityResultInterface::REASON_DISABLED, $this->createStub(
            \Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface::class
        ));
    }

    public function testRejectsUnknownReasonCode(): void
    {
        $this->expectException(CatalogException::class);

        new ProductNormalizationResult(false, 'bogus', null);
    }
}