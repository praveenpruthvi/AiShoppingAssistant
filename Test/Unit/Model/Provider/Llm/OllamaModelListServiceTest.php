<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\OllamaModelListService;
use Laminas\Http\Response;
use Magento\Framework\HTTP\LaminasClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OllamaModelListService::class)]
final class OllamaModelListServiceTest extends TestCase
{
    private ?LaminasClient $client = null;

    public function testEmptyBaseUrlFailsClosedBeforeRequest(): void
    {
        $service = $this->service('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');
        $this->client->expects(self::never())->method('send');

        $result = $service->fetchModels('');

        self::assertFalse($result->successful);
        self::assertSame([], $result->models);
        self::assertNotNull($result->message);
    }

    public function testStripsTrailingV1BeforeAppendingApiTags(): void
    {
        $service = $this->service(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"models":[{"name":"tinyllama:latest"}]}'
        );

        $service->fetchModels('http://127.0.0.1:11434/v1');

        self::assertSame('http://127.0.0.1:11434/api/tags', $this->client->getRequest()->getUriString());
    }

    public function testBaseUrlWithoutV1SuffixIsUsedDirectly(): void
    {
        $service = $this->service(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"models":[]}'
        );

        $service->fetchModels('http://127.0.0.1:11434');

        self::assertSame('http://127.0.0.1:11434/api/tags', $this->client->getRequest()->getUriString());
    }

    public function testReturnsModelNamesFromARealShapedResponse(): void
    {
        $service = $this->service(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"models":[{"name":"qwen3.5:latest","model":"qwen3.5:latest"},{"name":"tinyllama:latest"}]}'
        );

        $result = $service->fetchModels('http://127.0.0.1:11434');

        self::assertTrue($result->successful);
        self::assertSame(['qwen3.5:latest', 'tinyllama:latest'], $result->models);
        self::assertNull($result->message);
    }

    public function testZeroModelsIsAHonestSuccessNotAFailure(): void
    {
        $service = $this->service('HTTP/1.1 200 OK' . "\r\n\r\n" . '{"models":[]}');

        $result = $service->fetchModels('http://127.0.0.1:11434');

        self::assertTrue($result->successful);
        self::assertSame([], $result->models);
    }

    public function testUnreachableServerIsReportedAsFailureNotAnException(): void
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willThrowException(new \RuntimeException('connection refused'));
        $service = new OllamaModelListService($this->client, new HttpUrlPolicy());

        $result = $service->fetchModels('http://127.0.0.1:11434');

        self::assertFalse($result->successful);
        self::assertSame([], $result->models);
        self::assertStringNotContainsString('connection refused', (string) $result->message);
    }

    public function testNonSuccessStatusIsReportedAsFailure(): void
    {
        $service = $this->service('HTTP/1.1 500 Internal Server Error' . "\r\n\r\n" . '{}');

        $result = $service->fetchModels('http://127.0.0.1:11434');

        self::assertFalse($result->successful);
    }

    public function testMalformedJsonIsReportedAsFailure(): void
    {
        $service = $this->service('HTTP/1.1 200 OK' . "\r\n\r\n" . 'not json');

        $result = $service->fetchModels('http://127.0.0.1:11434');

        self::assertFalse($result->successful);
    }

    public function testMissingModelsKeyIsReportedAsFailure(): void
    {
        $service = $this->service('HTTP/1.1 200 OK' . "\r\n\r\n" . '{"unexpected":true}');

        $result = $service->fetchModels('http://127.0.0.1:11434');

        self::assertFalse($result->successful);
    }

    private function service(string $rawResponse): OllamaModelListService
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willReturn(Response::fromString($rawResponse));

        return new OllamaModelListService($this->client, new HttpUrlPolicy());
    }

    private function makeClient(): LaminasClient
    {
        $client = $this->getMockBuilder(LaminasClient::class)
            ->onlyMethods(['send'])
            ->setConstructorArgs([])
            ->getMock();

        $this->client = $client;

        return $client;
    }
}
