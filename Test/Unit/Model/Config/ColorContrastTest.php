<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Config;

use Aavirbhava\AiShoppingAssistant\Model\Config\ColorContrast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ColorContrast::class)]
final class ColorContrastTest extends TestCase
{
    private function contrast(): ColorContrast
    {
        return new ColorContrast();
    }

    public function testDarkBackgroundGetsLightText(): void
    {
        self::assertSame('#ffffff', $this->contrast()->readableTextFor('#1a1a2e'));
    }

    public function testLightBackgroundGetsDarkText(): void
    {
        self::assertSame('#1d1d1d', $this->contrast()->readableTextFor('#f5f5f5'));
    }

    public function testMidRangeBlueDefaultGetsLightText(): void
    {
        // This module's own default primary color — confirms the
        // contrast computation reproduces the existing hardcoded white
        // header text for the unchanged default, not a regression.
        self::assertSame('#ffffff', $this->contrast()->readableTextFor('#1979c3'));
    }

    public function testShorthandHexIsSupported(): void
    {
        self::assertSame('#ffffff', $this->contrast()->readableTextFor('#111'));
        self::assertSame('#1d1d1d', $this->contrast()->readableTextFor('#eee'));
    }

    public function testLightTextGetsDarkBackground(): void
    {
        self::assertSame('#2b2b2f', $this->contrast()->readableBackgroundFor('#ffffff'));
    }

    public function testDarkTextGetsLightBackground(): void
    {
        self::assertSame('#f2f2f2', $this->contrast()->readableBackgroundFor('#111111'));
    }
}
