<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;
use PHPUnit\Framework\TestCase;

class ProviderCapabilitiesTest extends TestCase
{
    public function testDefaultCapabilitiesDisableEverything(): void
    {
        $capabilities = new ProviderCapabilities();

        self::assertFalse($capabilities->supportsChatGeneration());
        self::assertFalse($capabilities->supportsEmbeddings());
        self::assertFalse($capabilities->supportsToolCalling());
        self::assertFalse($capabilities->supportsStructuredOutput());
        self::assertFalse($capabilities->supportsStreaming());
        self::assertFalse($capabilities->isApiKeyOptional());
        self::assertFalse($capabilities->supportsConfigurableBaseUrl());
    }

    public function testDeclaredCapabilitiesAreReported(): void
    {
        $capabilities = new ProviderCapabilities(
            chatGeneration: true,
            embeddings: true,
            toolCalling: true,
            structuredOutput: true,
            streaming: false,
            apiKeyOptional: true,
            configurableBaseUrl: true
        );

        self::assertTrue($capabilities->supportsChatGeneration());
        self::assertTrue($capabilities->supportsEmbeddings());
        self::assertTrue($capabilities->supportsToolCalling());
        self::assertTrue($capabilities->supportsStructuredOutput());
        self::assertFalse($capabilities->supportsStreaming());
        self::assertTrue($capabilities->isApiKeyOptional());
        self::assertTrue($capabilities->supportsConfigurableBaseUrl());
    }

    public function testMixedCapabilitiesAreIndependent(): void
    {
        $chatOnly = new ProviderCapabilities(chatGeneration: true);
        $embeddingOnly = new ProviderCapabilities(embeddings: true);

        self::assertTrue($chatOnly->supportsChatGeneration());
        self::assertFalse($chatOnly->supportsEmbeddings());
        self::assertFalse($embeddingOnly->supportsChatGeneration());
        self::assertTrue($embeddingOnly->supportsEmbeddings());
    }
}