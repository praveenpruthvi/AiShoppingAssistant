<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Embedding\ProviderEndpointPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderEndpointPolicy::class)]
final class ProviderEndpointPolicyTest extends TestCase
{
    public function testCloudProviderUsesOfficialDefaultWhenBaseUrlIsEmpty(): void
    {
        $policy = $this->policy();

        self::assertSame(
            'https://api.openai.com/v1/embeddings',
            $policy->embeddingsEndpoint(ProviderIdentifiers::EMBEDDING_OPENAI, '', 'https://api.openai.com/v1')
        );
        self::assertSame(
            'https://api.voyageai.com/v1/embeddings',
            $policy->embeddingsEndpoint(ProviderIdentifiers::EMBEDDING_VOYAGE, '', 'https://api.voyageai.com/v1')
        );
    }

    public function testCloudProviderAllowsOfficialDefaultAsOverride(): void
    {
        $policy = $this->policy();

        self::assertSame(
            'https://api.openai.com/v1/embeddings',
            $policy->embeddingsEndpoint(
                ProviderIdentifiers::EMBEDDING_OPENAI,
                'HTTPS://API.OPENAI.COM/V1/',
                'https://api.openai.com/v1'
            )
        );
    }

    public function testCloudProviderRejectsDifferentOverrideFailClosed(): void
    {
        $policy = $this->policy();

        $this->expectException(EmbeddingConfigurationException::class);
        $policy->embeddingsEndpoint(
            ProviderIdentifiers::EMBEDDING_OPENAI,
            'https://evil.example.test/v1',
            'https://api.openai.com/v1'
        );
    }

    public function testLocalProviderRequiresExplicitBaseUrl(): void
    {
        $policy = $this->policy();

        $this->expectException(EmbeddingConfigurationException::class);
        $policy->embeddingsEndpoint(ProviderIdentifiers::EMBEDDING_LOCAL_OPENAI_COMPATIBLE, '', '');
    }

    public function testLocalProviderAllowsHttpAndHttps(): void
    {
        $policy = $this->policy();

        self::assertSame(
            'http://127.0.0.1:11434/v1/embeddings',
            $policy->embeddingsEndpoint(
                ProviderIdentifiers::EMBEDDING_LOCAL_OPENAI_COMPATIBLE,
                'http://127.0.0.1:11434/v1',
                ''
            )
        );
        self::assertSame(
            'https://local.example.test/v1/embeddings',
            $policy->embeddingsEndpoint(
                ProviderIdentifiers::EMBEDDING_LOCAL_OPENAI_COMPATIBLE,
                'https://local.example.test/v1',
                ''
            )
        );
    }

    public function testLocalProviderRejectsCredentialsAndFragments(): void
    {
        $policy = $this->policy();

        $this->expectException(EmbeddingConfigurationException::class);
        $policy->embeddingsEndpoint(
            ProviderIdentifiers::EMBEDDING_LOCAL_OPENAI_COMPATIBLE,
            'https://user:pass@local.example.test/v1',
            ''
        );
    }

    private function policy(): ProviderEndpointPolicy
    {
        return new ProviderEndpointPolicy(new HttpUrlPolicy());
    }
}
