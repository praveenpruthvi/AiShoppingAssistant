<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;
use PHPUnit\Framework\TestCase;

class ProviderIdentifiersTest extends TestCase
{
    public function testBuiltInLlmIdentifiersAreExposedAsConstants(): void
    {
        self::assertSame([
            'openai',
            'anthropic',
            'xai',
            'openai_compatible',
        ], ProviderIdentifiers::llmProviderIds());

        foreach (ProviderIdentifiers::llmProviderIds() as $id) {
            self::assertTrue(ProviderIdentifiers::isValid($id));
        }
    }

    public function testBuiltInEmbeddingIdentifiersAreExposedAsConstants(): void
    {
        self::assertSame([
            'openai',
            'voyage',
            'openai_compatible',
        ], ProviderIdentifiers::embeddingProviderIds());

        foreach (ProviderIdentifiers::embeddingProviderIds() as $id) {
            self::assertTrue(ProviderIdentifiers::isValid($id));
        }
    }

    public function testBuiltInConstantsMatchPublicValues(): void
    {
        self::assertSame('openai', ProviderIdentifiers::LLM_OPENAI);
        self::assertSame('anthropic', ProviderIdentifiers::LLM_ANTHROPIC);
        self::assertSame('xai', ProviderIdentifiers::LLM_XAI);
        self::assertSame('openai_compatible', ProviderIdentifiers::LLM_OPENAI_COMPATIBLE);
        self::assertSame('openai', ProviderIdentifiers::EMBEDDING_OPENAI);
        self::assertSame('voyage', ProviderIdentifiers::EMBEDDING_VOYAGE);
        self::assertSame('openai_compatible', ProviderIdentifiers::EMBEDDING_OPENAI_COMPATIBLE);
    }

    public function testThirdPartyIdentifiersAreSyntacticallyValid(): void
    {
        self::assertTrue(ProviderIdentifiers::isValid('acme_local_llm'));
        self::assertTrue(ProviderIdentifiers::isValid('acme_embeddings'));
        self::assertTrue(ProviderIdentifiers::isValid('a'));
    }

    public function testMaximumLengthIdentifierIsValid(): void
    {
        self::assertTrue(ProviderIdentifiers::isValid(str_repeat('a', 64)));
    }

    public function testInvalidIdentifiersAreRejected(): void
    {
        $invalid = [
            '',
            'OpenAI',
            'openai.com',
            'openai-compatible',
            '1openai',
            '_openai',
            'open ai',
            'openai/Evil',
            'openai\\Provider',
            str_repeat('a', 65),
        ];

        foreach ($invalid as $identifier) {
            self::assertFalse(ProviderIdentifiers::isValid($identifier), $identifier);
        }
    }

    public function testAssertValidThrowsSanitizedExceptionForInvalidIdentifier(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        ProviderIdentifiers::assertValid('openai/Evil');
    }

    public function testAssertValidThrowsWithoutEchoingTheIdentifier(): void
    {
        try {
            ProviderIdentifiers::assertValid('openai/Evil');
            self::fail('Expected ProviderConfigurationException to be thrown.');
        } catch (ProviderConfigurationException $exception) {
            self::assertStringNotContainsString('openai', $exception->getMessage());
        }
    }
}