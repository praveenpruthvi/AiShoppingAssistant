<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Config;

use Aavirbhava\AiShoppingAssistant\Model\Config\CapabilitiesConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CapabilitiesConfig::class)]
final class CapabilitiesConfigTest extends TestCase
{
    public function testExposesEachToggleIndependently(): void
    {
        $capabilities = new CapabilitiesConfig(true, false, true, false, true, false, true);

        self::assertTrue($capabilities->isProductDiscoveryEnabled());
        self::assertFalse($capabilities->isProductDetailsEnabled());
        self::assertTrue($capabilities->isComparisonEnabled());
        self::assertFalse($capabilities->isPriceCheckingEnabled());
        self::assertTrue($capabilities->isStockCheckingEnabled());
        self::assertFalse($capabilities->isPolicySearchEnabled());
        self::assertTrue($capabilities->isPromotionAwarenessEnabled());
    }
}
