<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;
use PHPUnit\Framework\TestCase;

class ProviderIdentifiersTest extends TestCase
{
    public function testLlmProviderIdsContainOnlyAllowlistedIdentifiers(): void
    {
        $ids = ProviderIdentifiers::llmProviderIds();

        self::assertSame([
            'openai',
            'anthropic',
            'xai',
            'openai_compatible',
        ], $ids);

        foreach ($ids as $id) {
            self::assertTrue(ProviderIdentifiers::isKnownLlm($id));
        }
    }

    public function testEmbeddingProviderIdsContainOnlyAllowlistedIdentifiers(): void
    {
        $ids = ProviderIdentifiers::embeddingProviderIds();

        self::assertSame([
            'openai',
            'voyage',
            'openai_compatible',
        ], $ids);

        foreach ($ids as $id) {
            self::assertTrue(ProviderIdentifiers::isKnownEmbedding($id));
        }
    }

    public function testUnknownIdentifiersAreNotKnown(): void
    {
        self::assertFalse(ProviderIdentifiers::isKnownLlm('google'));
        self::assertFalse(ProviderIdentifiers::isKnownLlm('UnknownClass'));
        self::assertFalse(ProviderIdentifiers::isKnownEmbedding('google'));
        self::assertFalse(ProviderIdentifiers::isKnownEmbedding(''));
    }

    public function testEmbeddingIdentifiersAreRejectedForLlmChecks(): void
    {
        self::assertFalse(ProviderIdentifiers::isKnownLlm('voyage'));
    }
}