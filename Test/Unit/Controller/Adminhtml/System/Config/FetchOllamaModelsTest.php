<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Controller\Adminhtml\System\Config;

use Aavirbhava\AiShoppingAssistant\Api\Provider\OllamaModelListServiceInterface;
use Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\System\Config\FetchOllamaModels;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\OllamaModelListResult;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FetchOllamaModels::class)]
final class FetchOllamaModelsTest extends TestCase
{
    public function testSuccessfulFetchReturnsTheModelListAsJson(): void
    {
        $service = $this->createMock(OllamaModelListServiceInterface::class);
        $service->expects(self::once())
            ->method('fetchModels')
            ->with('http://127.0.0.1:11434/v1')
            ->willReturn(OllamaModelListResult::success(['qwen3.5:latest', 'tinyllama:latest']));

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects(self::once())->method('setData')->with([
            'successful' => true,
            'models' => ['qwen3.5:latest', 'tinyllama:latest'],
            'message' => null,
        ]);

        $controller = $this->controller(
            baseUrl: 'http://127.0.0.1:11434/v1',
            service: $service,
            jsonResult: $jsonResult
        );

        $controller->execute();
    }

    public function testFailedFetchStillReturnsACleanJsonPayload(): void
    {
        $service = $this->createMock(OllamaModelListServiceInterface::class);
        $service->method('fetchModels')->willReturn(OllamaModelListResult::failure('Unable to reach the Ollama server.'));

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects(self::once())->method('setData')->with([
            'successful' => false,
            'models' => [],
            'message' => 'Unable to reach the Ollama server.',
        ]);

        $controller = $this->controller(baseUrl: 'http://unreachable.test', service: $service, jsonResult: $jsonResult);

        $controller->execute();
    }

    public function testEmptyBaseUrlFallsBackToOllamasStandardLocalPort(): void
    {
        $service = $this->createMock(OllamaModelListServiceInterface::class);
        $service->expects(self::once())
            ->method('fetchModels')
            ->with('http://localhost:11434')
            ->willReturn(OllamaModelListResult::success([]));

        $controller = $this->controller(baseUrl: '', service: $service);

        $controller->execute();
    }

    private function controller(
        string $baseUrl,
        ?OllamaModelListServiceInterface $service = null,
        ?Json $jsonResult = null
    ): FetchOllamaModels {
        $context = $this->createMock(Context::class);

        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $key === 'base_url' ? $baseUrl : $default
        );
        $context->method('getRequest')->willReturn($request);

        $jsonResult ??= $this->createMock(Json::class);
        $jsonResultFactory = $this->createMock(JsonFactory::class);
        $jsonResultFactory->method('create')->willReturn($jsonResult);

        $service ??= $this->createMock(OllamaModelListServiceInterface::class);

        return new FetchOllamaModels($context, $jsonResultFactory, $service);
    }
}
