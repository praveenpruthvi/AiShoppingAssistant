<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Http;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingTransportException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\ProviderHttpTransport;
use Laminas\Http\Response;
use Magento\Framework\HTTP\LaminasClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderHttpTransport::class)]
final class ProviderHttpTransportTest extends TestCase
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $setOptionsCalls = [];

    /**
     * The shared Laminas client mock used by the current test.
     *
     * @var LaminasClient|null
     */
    private ?LaminasClient $client = null;

    public function testPostReturnsStatusAndBody(): void
    {
        $transport = $this->transport('HTTP/1.1 200 OK' . "\r\nContent-Type: application/json\r\n\r\n" . '{"ok":true}');

        $response = $transport->post(
            'https://api.openai.com/v1/embeddings',
            ['Authorization' => 'Bearer key-123'],
            '{"model":"m"}',
            20.0
        );

        self::assertSame(200, $response->statusCode());
        self::assertSame('{"ok":true}', $response->body());
    }

    public function testRequestIsPostWithJsonHeadersAndRawBody(): void
    {
        $transport = $this->transport('HTTP/1.1 200 OK' . "\r\nContent-Type: application/json\r\n\r\n" . '{}');

        $transport->post(
            'https://api.openai.com/v1/embeddings',
            ['Authorization' => 'Bearer key-123'],
            '{"model":"m"}',
            20.0
        );

        $request = $this->client->getRequest();

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.openai.com/v1/embeddings', $request->getUriString());
        self::assertSame('{"model":"m"}', $request->getContent());
        self::assertSame('application/json', $request->getHeaders()->get('Content-Type')->getFieldValue());
        self::assertSame('application/json', $request->getHeaders()->get('Accept')->getFieldValue());
        self::assertSame('Bearer key-123', $request->getHeaders()->get('Authorization')->getFieldValue());
    }

    public function testOptionsDisableRedirectsAndEnforceTlsAndTimeout(): void
    {
        $this->transport('HTTP/1.1 200 OK' . "\r\nContent-Type: application/json\r\n\r\n" . '{}')
            ->post('https://api.openai.com/v1/embeddings', [], '{}', 25.0);

        $transportOptions = $this->lastSetOptions();

        self::assertSame(0, $transportOptions['maxredirects']);
        self::assertSame(25, $transportOptions['timeout']);
        self::assertTrue($transportOptions['verifypeer']);
        self::assertSame(2, $transportOptions['verifyhost']);
    }

    public function testTimeoutIsRoundedUpToAtLeastOneSecond(): void
    {
        $this->transport('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}')
            ->post('https://api.openai.com/v1/embeddings', [], '{}', 0.4);

        self::assertSame(1, $this->lastSetOptions()['timeout']);
    }

    public function testMalformedUrlIsRejectedWithoutSending(): void
    {
        $transport = $this->transport('HTTP/1.1 200 OK' . "\r\nContent-Type: application/json\r\n\r\n" . '{}');
        $this->client->expects(self::never())->method('send');

        $this->expectException(EmbeddingTransportException::class);
        $transport->post('https://user:pass@api.openai.com/v1/embeddings', [], '{}', 20.0);
    }

    public function testTimeoutIsMapped(): void
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willThrowException(
            new \RuntimeException('Operation timed out after 20000 milliseconds')
        );

        $transport = $this->makeTransport();

        try {
            $transport->post('https://api.openai.com/v1/embeddings', [], '{}', 20.0);
            self::fail('Expected EmbeddingTimeoutException.');
        } catch (EmbeddingTimeoutException $exception) {
            self::assertSame('embedding_timeout', $exception->errorCode());
        }
    }

    public function testGenericTransportFailureIsSanitized(): void
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willThrowException(new \RuntimeException('secret connection detail'));

        $transport = $this->makeTransport();

        try {
            $transport->post('https://api.openai.com/v1/embeddings', [], '{}', 20.0);
            self::fail('Expected EmbeddingTransportException.');
        } catch (EmbeddingTransportException $exception) {
            self::assertSame('embedding_transport_failed', $exception->errorCode());
            self::assertStringNotContainsString('secret connection detail', $exception->getMessage());
        }
    }

    public function testOversizedBodyIsRejected(): void
    {
        $this->client = $this->makeClient();

        $response = $this->createMock(Response::class);
        $response->method('getBody')->willReturn(
            str_repeat('x', ProviderHttpTransport::MAX_RESPONSE_BYTES + 1)
        );
        $response->method('getStatusCode')->willReturn(200);
        $this->client->method('send')->willReturn($response);

        $transport = $this->makeTransport();

        $this->expectException(EmbeddingResponseException::class);
        $transport->post('https://api.openai.com/v1/embeddings', [], '{}', 20.0);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastSetOptions(): array
    {
        self::assertNotEmpty($this->setOptionsCalls);

        return $this->setOptionsCalls[count($this->setOptionsCalls) - 1];
    }

    private function transport(string $rawResponse): ProviderHttpTransport
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willReturn(Response::fromString($rawResponse));

        return $this->makeTransport();
    }

    private function makeTransport(): ProviderHttpTransport
    {
        self::assertNotNull($this->client);

        return new ProviderHttpTransport($this->client, new HttpUrlPolicy());
    }

    private function makeClient(): LaminasClient
    {
        $client = $this->getMockBuilder(LaminasClient::class)
            ->onlyMethods(['send', 'setOptions'])
            ->setConstructorArgs([])
            ->getMock();

        $client->method('setOptions')->willReturnCallback(
            function (array $options): LaminasClient {
                $this->setOptionsCalls[] = $options;

                return $this->client;
            }
        );

        $this->client = $client;

        return $client;
    }
}
