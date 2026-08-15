<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Embedding\LocalOpenAiCompatibleEmbeddingProvider;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(LocalOpenAiCompatibleEmbeddingProvider::class)]
final class LocalOpenAiCompatibleEmbeddingProviderTest extends AbstractEmbeddingProviderTestCase
{
    public function testSendsConfiguredBaseUrlWithoutAuthorizationWhenKeyIsEmpty(): void
    {
        $provider = new LocalOpenAiCompatibleEmbeddingProvider(
            $this->transport(
                'HTTP/1.1 200 OK' . "\r\nContent-Type: application/json\r\n\r\n" .
                '{"data":[{"index":0,"embedding":[0.1,0.2,0.3]}]}'
            ),
            $this->endpointPolicy()
        );

        $result = $provider->embed(
            $this->request(['blue shoe'], baseUrl: 'http://127.0.0.1:11434/v1', withApiKey: false)
        );

        $request = $this->client->getRequest();

        self::assertSame('http://127.0.0.1:11434/v1/embeddings', $request->getUriString());
        self::assertFalse($request->getHeaders()->has('Authorization'));
        self::assertSame('text-embedding-test', $result->model());
    }

    public function testSendsAuthorizationHeaderWhenKeyIsConfigured(): void
    {
        $provider = new LocalOpenAiCompatibleEmbeddingProvider(
            $this->transport(
                'HTTP/1.1 200 OK' . "\r\nContent-Type: application/json\r\n\r\n" .
                '{"data":[{"index":0,"embedding":[0.1,0.2,0.3]}]}'
            ),
            $this->endpointPolicy()
        );

        $provider->embed(
            $this->request(['blue shoe'], baseUrl: 'http://127.0.0.1:11434/v1', withApiKey: true)
        );

        self::assertTrue($this->client->getRequest()->getHeaders()->has('Authorization'));
    }

    public function testHttpsBaseUrlIsAllowed(): void
    {
        $provider = new LocalOpenAiCompatibleEmbeddingProvider(
            $this->transport(
                'HTTP/1.1 200 OK' . "\r\nContent-Type: application/json\r\n\r\n" .
                '{"data":[{"index":0,"embedding":[0.1,0.2,0.3]}]}'
            ),
            $this->endpointPolicy()
        );

        $provider->embed($this->request(['blue shoe'], baseUrl: 'https://local.example.test/v1'));

        self::assertSame(
            'https://local.example.test/v1/embeddings',
            $this->client->getRequest()->getUriString()
        );
    }

    public function testMissingBaseUrlFailsClosed(): void
    {
        $provider = new LocalOpenAiCompatibleEmbeddingProvider(
            $this->transport('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}'),
            $this->endpointPolicy()
        );

        $this->expectException(EmbeddingConfigurationException::class);
        $provider->embed($this->request());
    }

    public function testEndpointWithEmbeddedCredentialsIsRejected(): void
    {
        $provider = new LocalOpenAiCompatibleEmbeddingProvider(
            $this->transport('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}'),
            $this->endpointPolicy()
        );

        $this->expectException(EmbeddingConfigurationException::class);
        $provider->embed($this->request(baseUrl: 'https://user:pass@local.example.test/v1'));
    }
}
