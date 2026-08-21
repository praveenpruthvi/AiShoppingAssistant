<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Config;

use Aavirbhava\AiShoppingAssistant\Model\Config\ProviderCostConfig;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderCostConfig::class)]
final class ProviderCostConfigTest extends TestCase
{
    public function testReturnsTheConfiguredPriceForAKnownProvider(): void
    {
        $config = new ProviderCostConfig([
            ProviderIdentifiers::LLM_OPENAI => ['input' => 0.005, 'output' => 0.015],
        ]);

        self::assertSame(0.005, $config->pricePerThousandInputTokens(ProviderIdentifiers::LLM_OPENAI));
        self::assertSame(0.015, $config->pricePerThousandOutputTokens(ProviderIdentifiers::LLM_OPENAI));
    }

    public function testReturnsZeroForAnUnconfiguredProvider(): void
    {
        $config = new ProviderCostConfig([
            ProviderIdentifiers::LLM_OPENAI => ['input' => 0.005, 'output' => 0.015],
        ]);

        self::assertSame(0.0, $config->pricePerThousandInputTokens(ProviderIdentifiers::LLM_OPENAI_COMPATIBLE));
        self::assertSame(0.0, $config->pricePerThousandOutputTokens(ProviderIdentifiers::LLM_OPENAI_COMPATIBLE));
    }

    public function testLocalProviderDefaultingToZeroIsTheExpectedShape(): void
    {
        $config = new ProviderCostConfig([
            ProviderIdentifiers::LLM_OPENAI_COMPATIBLE => ['input' => 0.0, 'output' => 0.0],
        ]);

        self::assertSame(0.0, $config->pricePerThousandInputTokens(ProviderIdentifiers::LLM_OPENAI_COMPATIBLE));
        self::assertSame(0.0, $config->pricePerThousandOutputTokens(ProviderIdentifiers::LLM_OPENAI_COMPATIBLE));
    }

    public function testRejectsANegativePrice(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProviderCostConfig([ProviderIdentifiers::LLM_OPENAI => ['input' => -1.0, 'output' => 0.0]]);
    }
}
