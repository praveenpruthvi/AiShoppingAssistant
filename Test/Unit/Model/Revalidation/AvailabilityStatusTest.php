<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Revalidation;

use Aavirbhava\AiShoppingAssistant\Model\Revalidation\AvailabilityStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AvailabilityStatus::class)]
final class AvailabilityStatusTest extends TestCase
{
    public function testAcceptsAFoundInStockStatus(): void
    {
        $status = new AvailabilityStatus('SKU-1', true, true, 'Blue Shoe');

        self::assertSame('SKU-1', $status->sku);
        self::assertTrue($status->found);
        self::assertTrue($status->inStock);
        self::assertSame('Blue Shoe', $status->name);
    }

    public function testAcceptsAFoundButOutOfStockStatus(): void
    {
        $status = new AvailabilityStatus('SKU-1', true, false, 'Blue Shoe');

        self::assertTrue($status->found);
        self::assertFalse($status->inStock);
    }

    public function testAcceptsANotFoundStatus(): void
    {
        $status = new AvailabilityStatus('SKU-GONE', false, false, null);

        self::assertFalse($status->found);
        self::assertFalse($status->inStock);
        self::assertNull($status->name);
    }

    public function testRejectsEmptySku(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AvailabilityStatus('', false, false, null);
    }

    public function testRejectsInStockWithoutFound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AvailabilityStatus('SKU-1', false, true, null);
    }
}
