<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolResult::class)]
final class ToolResultTest extends TestCase
{
    public function testDefaultsToNoVerifiedProducts(): void
    {
        $result = new ToolResult(['found' => false]);

        self::assertSame(['found' => false], $result->data);
        self::assertSame([], $result->verifiedProducts);
    }

    public function testCarriesVerifiedProductsAlongsideData(): void
    {
        $product = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');

        $result = new ToolResult(['found' => true], [$product]);

        self::assertSame([$product], $result->verifiedProducts);
    }
}
