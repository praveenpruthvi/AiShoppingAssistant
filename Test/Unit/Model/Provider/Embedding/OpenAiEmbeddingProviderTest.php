<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingDimensionException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingRateLimitException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingTransportException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Embedding\OpenAiEmbeddingProvider;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OpenAiEmbeddingProvider::class)]
final class OpenAiEmbeddingProviderTest extends AbstractEmbeddingProviderTestCase
{
    private OpenAiEmbeddingProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new OpenAiEmbeddingProvider(
            $this->transport(
                'HTTP/1.1 200 OK' . "\r\nContent-Type: application/json\r\n\r\n" .
                '{"data":[{"index":0,"embedding":[0.1,0.2,0.3]}],"model":"text-embedding-test",' .
                '"usage":{"prompt_tokens":5,"total_tokens":5}}'
            ),
            $this->endpointPolicy()
        );
    }

    public function testReturnsVectorsCorrelatedToInputs(): void
    {
        $provider = new OpenAiEmbeddingProvider(
            $this->transport(
                'HTTP/1.1 200 OK' . "\r\nContent-Type: application/json\r\n\r\n" .
                '{"data":[{"index":0,"embedding":[0.1,0.2,0.3]},{"index":1,"embedding":[0.4,0.5,0.6]}],' .
                '"model":"text-embedding-test","usage":{"prompt_tokens":5,"total_tokens":5}}'
            ),
            $this->endpointPolicy()
        );

        $result = $provider->embed($this->request(['blue shoe', 'red hat'], dimensions: 3));

        self::assertSame(['0', '1'], $result->inputIdentifiers());
        self::assertCount(2, $result->vectors());
        self::assertSame([0.1, 0.2, 0.3], $result->vectors()[0]->values());
        self::assertSame([0.4, 0.5, 0.6], $result->vectors()[1]->values());
        self::assertSame('text-embedding-test', $result->model());
        self::assertSame(5, $result->usage()->inputTokens());
    }

    public function testSendsOpenAiEndpointAndRequestBody(): void
    {
        $this->provider->embed($this->request(['blue shoe']));

        $request = $this->client->getRequest();

        self::assertSame('https://api.openai.com/v1/embeddings', $request->getUriString());
        $body = json_decode($request->getContent(), true);
        self::assertSame('text-embedding-test', $body['model']);
        self::assertSame(['blue shoe'], $body['input']);
        self::assertSame('float', $body['encoding_format']);
        self::assertStringNotContainsString('key-123', $request->getContent());
    }

    public function testMissingApiKeyFailsClosedBeforeRequest(): void
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

    public function testAuthenticationStatusMapsToAuthenticationException(): void
    {
        $provider = new OpenAiEmbeddingProvider(
            $this->transport('HTTP/1.1 401 Unauthorized' . "\r\n\r\n" . '{"error":"bad"}'),
            $this->endpointPolicy()
        );

        $this->expectException(EmbeddingAuthenticationException::class);
        $provider->embed($this->request());
    }

    public function testRateLimitStatusMapsToRateLimitException(): void
    {
        $provider = new OpenAiEmbeddingProvider(
            $this->transport('HTTP/1.1 429 Too Many Requests' . "\r\n\r\n" . '{}'),
            $this->endpointPolicy()
        );

        $this->expectException(EmbeddingRateLimitException::class);
        $provider->embed($this->request());
    }

    public function testServerErrorMapsToUnavailableException(): void
    {
        $provider = new OpenAiEmbeddingProvider(
            $this->transport('HTTP/1.1 500 Internal Server Error' . "\r\n\r\n" . '{}'),
            $this->endpointPolicy()
        );

        $this->expectException(EmbeddingUnavailableException::class);
        $provider->embed($this->request());
    }

    public function testTimeoutStatusMapsToTimeoutException(): void
    {
        $provider = new OpenAiEmbeddingProvider(
            $this->transport('HTTP/1.1 504 Gateway Timeout' . "\r\n\r\n" . '{}'),
            $this->endpointPolicy()
        );

        $this->expectException(EmbeddingTimeoutException::class);
        $provider->embed($this->request());
    }

    public function testInvalidJsonIsRejected(): void
    {
        $provider = new OpenAiEmbeddingProvider(
            $this->transport('HTTP/1.1 200 OK' . "\r\n\r\n" . 'not json'),
            $this->endpointPolicy()
        );

        $this->expectException(EmbeddingResponseException::class);
        $provider->embed($this->request());
    }

    public function testMissingVectorsAreRejected(): void
    {
        $provider = new OpenAiEmbeddingProvider(
            $this->transport('HTTP/1.1 200 OK' . "\r\n\r\n" . '{"data":[]}'),
            $this->endpointPolicy()
        );

        $this->expectException(EmbeddingResponseException::class);
        $provider->embed($this->request());
    }

    public function testDuplicateIndexIsRejected(): void
    {
        $provider = new OpenAiEmbeddingProvider(
            $this->transport(
                'HTTP/1.1 200 OK' . "\r\n\r\n" .
                '{"data":[{"index":0,"embedding":[0.1,0.2,0.3]},{"index":0,"embedding":[0.4,0.5,0.6]}]}'
            ),
            $this->endpointPolicy()
        );

        $this->expectException(EmbeddingResponseException::class);
        $provider->embed($this->request(['blue shoe', 'red hat']));
    }

    public function testDimensionMismatchIsRejected(): void
    {
        $provider = new OpenAiEmbeddingProvider(
            $this->transport(
                'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"data":[{"index":0,"embedding":[0.1,0.2,0.3,0.4]}]}'
            ),
            $this->endpointPolicy()
        );

        $this->expectException(EmbeddingDimensionException::class);
        $provider->embed($this->request());
    }

    public function testTransportFailureIsSanitized(): void
    {
        $this->client->method('send')->willThrowException(new \RuntimeException('secret detail'));

        try {
            $this->provider->embed($this->request());
            self::fail('Expected EmbeddingTransportException.');
        } catch (EmbeddingTransportException $exception) {
            self::assertSame('embedding_transport_failed', $exception->errorCode());
            self::assertStringNotContainsString('secret detail', $exception->getMessage());
        }
    }

    public function testReorderedIndexIsRestoredSafely(): void
    {
        $provider = new OpenAiEmbeddingProvider(
            $this->transport(
                'HTTP/1.1 200 OK' . "\r\n\r\n" .
                '{"data":[{"index":1,"embedding":[0.4,0.5,0.6]},{"index":0,"embedding":[0.1,0.2,0.3]}]}'
            ),
            $this->endpointPolicy()
        );

        $result = $provider->embed($this->request(['blue shoe', 'red hat']));

        self::assertSame([0.1, 0.2, 0.3], $result->vectors()[0]->values());
        self::assertSame([0.4, 0.5, 0.6], $result->vectors()[1]->values());
    }

    public function testUnknownIndexIsRejected(): void
    {
        $provider = new OpenAiEmbeddingProvider(
            $this->transport(
                'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"data":[{"index":9,"embedding":[0.1,0.2,0.3]}]}'
            ),
            $this->endpointPolicy()
        );

        $this->expectException(EmbeddingResponseException::class);
        $provider->embed($this->request());
    }
}
