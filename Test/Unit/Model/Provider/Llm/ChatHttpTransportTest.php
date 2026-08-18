<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTransportException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\ChatHttpTransport;
use Laminas\Http\Response;
use Magento\Framework\HTTP\LaminasClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatHttpTransport::class)]
final class ChatHttpTransportTest extends TestCase
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $setOptionsCalls = [];

    private ?LaminasClient $client = null;

    public function testPostReturnsStatusAndBody(): void
    {
        $transport = $this->transport('HTTP/1.1 200 OK' . "\r\nContent-Type: application/json\r\n\r\n" . '{"ok":true}');

        $response = $transport->post(
            'https://api.openai.com/v1/chat/completions',
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
            'https://api.openai.com/v1/chat/completions',
            ['Authorization' => 'Bearer key-123'],
            '{"model":"m"}',
            20.0
        );

        $request = $this->client->getRequest();

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.openai.com/v1/chat/completions', $request->getUriString());
        self::assertSame('{"model":"m"}', $request->getContent());
        self::assertSame('application/json', $request->getHeaders()->get('Content-Type')->getFieldValue());
        self::assertSame('application/json', $request->getHeaders()->get('Accept')->getFieldValue());
        self::assertSame('Bearer key-123', $request->getHeaders()->get('Authorization')->getFieldValue());
    }

    public function testOptionsDisableRedirectsAndEnforceTlsAndTimeout(): void
    {
        $this->transport('HTTP/1.1 200 OK' . "\r\nContent-Type: application/json\r\n\r\n" . '{}')
            ->post('https://api.openai.com/v1/chat/completions', [], '{}', 25.0);

        $transportOptions = $this->lastSetOptions();

        self::assertSame(0, $transportOptions['maxredirects']);
        self::assertSame(25, $transportOptions['timeout']);
        self::assertTrue($transportOptions['verifypeer']);
        self::assertSame(2, $transportOptions['verifyhost']);
    }

    public function testTimeoutIsRoundedUpToAtLeastOneSecond(): void
    {
        $this->transport('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}')
            ->post('https://api.openai.com/v1/chat/completions', [], '{}', 0.4);

        self::assertSame(1, $this->lastSetOptions()['timeout']);
    }

    public function testMalformedUrlIsRejectedWithoutSending(): void
    {
        $transport = $this->transport('HTTP/1.1 200 OK' . "\r\nContent-Type: application/json\r\n\r\n" . '{}');
        $this->client->expects(self::never())->method('send');

        $this->expectException(ProviderTransportException::class);
        $transport->post('https://user:pass@api.openai.com/v1/chat/completions', [], '{}', 20.0);
    }

    public function testTimeoutIsMapped(): void
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willThrowException(
            new \RuntimeException('Operation timed out after 20000 milliseconds')
        );

        $transport = $this->makeTransport();

        try {
            $transport->post('https://api.openai.com/v1/chat/completions', [], '{}', 20.0);
            self::fail('Expected ProviderTimeoutException.');
        } catch (ProviderTimeoutException $exception) {
            self::assertSame('PROVIDER_TIMEOUT', $exception->errorCode());
        }
    }

    public function testGenericTransportFailureIsSanitized(): void
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willThrowException(new \RuntimeException('secret connection detail'));

        $transport = $this->makeTransport();

        try {
            $transport->post('https://api.openai.com/v1/chat/completions', [], '{}', 20.0);
            self::fail('Expected ProviderTransportException.');
        } catch (ProviderTransportException $exception) {
            self::assertSame('PROVIDER_TRANSPORT_ERROR', $exception->errorCode());
            self::assertStringNotContainsString('secret connection detail', $exception->getMessage());
        }
    }

    public function testOversizedBodyIsRejected(): void
    {
        $this->client = $this->makeClient();

        $response = $this->createMock(Response::class);
        $response->method('getBody')->willReturn(
            str_repeat('x', ChatHttpTransport::MAX_RESPONSE_BYTES + 1)
        );
        $response->method('getStatusCode')->willReturn(200);
        $this->client->method('send')->willReturn($response);

        $transport = $this->makeTransport();

        $this->expectException(ProviderInvalidResponseException::class);
        $transport->post('https://api.openai.com/v1/chat/completions', [], '{}', 20.0);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastSetOptions(): array
    {
        self::assertNotEmpty($this->setOptionsCalls);

        return $this->setOptionsCalls[count($this->setOptionsCalls) - 1];
    }

    private function transport(string $rawResponse): ChatHttpTransport
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willReturn(Response::fromString($rawResponse));

        return $this->makeTransport();
    }

    private function makeTransport(): ChatHttpTransport
    {
        self::assertNotNull($this->client);

        return new ChatHttpTransport($this->client, new HttpUrlPolicy());
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
