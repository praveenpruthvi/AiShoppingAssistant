<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Embedding\VoyageEmbeddingProvider;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(VoyageEmbeddingProvider::class)]
final class VoyageEmbeddingProviderTest extends AbstractEmbeddingProviderTestCase
{
    private VoyageEmbeddingProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new VoyageEmbeddingProvider(
            $this->transport(
                'HTTP/1.1 200 OK' . "\r\nContent-Type: application/json\r\n\r\n" .
                '{"data":[{"index":0,"embedding":[0.1,0.2,0.3]}],"model":"voyage-test",' .
                '"usage":{"prompt_tokens":5,"total_tokens":5}}'
            ),
            $this->endpointPolicy()
        );
    }

    public function testSendsVoyageEndpointAndInputType(): void
    {
        $this->provider->embed($this->request(['blue shoe'], inputType: 'document'));

        $request = $this->client->getRequest();

        self::assertSame('https://api.voyageai.com/v1/embeddings', $request->getUriString());
        $body = json_decode($request->getContent(), true);
        self::assertSame('document', $body['input_type']);
        self::assertSame('blue shoe', $body['input'][0]);
    }

    public function testQueryInputTypeIsSent(): void
    {
        $this->provider->embed($this->request(['blue shoe'], inputType: 'query'));

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame('query', $body['input_type']);
    }

    public function testMissingApiKeyFailsClosed(): void
    {
        $this->client->expects(self::never())->method('send');

        $this->expectException(EmbeddingConfigurationException::class);
        $this->provider->embed($this->request(withApiKey: false));
    }

    public function testCustomBaseUrlIsRejectedFailClosed(): void
    {
        $this->client->expects(self::never())->method('send');

        $this->expectException(EmbeddingConfigurationException::class);
        $this->provider->embed($this->request(baseUrl: 'https://evil.example.test/v1'));
    }
}
