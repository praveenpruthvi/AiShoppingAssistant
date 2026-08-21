<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Api\Config\ProviderCostConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostCalculator;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CostCalculator::class)]
final class CostCalculatorTest extends TestCase
{
    public function testComputesRealCostFromRealTokenCountsAndConfiguredPricing(): void
    {
        $usage = new TokenUsage(2000, 1000);
        $providerCost = $this->providerCost(0.005, 0.015);

        $cost = (new CostCalculator())->cost($usage, 'openai', $providerCost);

        // (2000/1000 * 0.005) + (1000/1000 * 0.015) = 0.01 + 0.015 = 0.025
        self::assertEqualsWithDelta(0.025, $cost, 0.0000001);
    }

    public function testALocalProviderPricedAtZeroCostsNothing(): void
    {
        $usage = new TokenUsage(5000, 3000);
        $providerCost = $this->providerCost(0.0, 0.0);

        $cost = (new CostCalculator())->cost($usage, 'openai_compatible', $providerCost);

        self::assertSame(0.0, $cost);
    }

    public function testZeroTokensCostsNothingRegardlessOfPricing(): void
    {
        $usage = new TokenUsage(0, 0);
        $providerCost = $this->providerCost(0.005, 0.015);

        $cost = (new CostCalculator())->cost($usage, 'openai', $providerCost);

        self::assertSame(0.0, $cost);
    }

    private function providerCost(float $input, float $output): ProviderCostConfigInterface
    {
        $providerCost = $this->createMock(ProviderCostConfigInterface::class);
        $providerCost->method('pricePerThousandInputTokens')->willReturn($input);
        $providerCost->method('pricePerThousandOutputTokens')->willReturn($output);

        return $providerCost;
    }
}
