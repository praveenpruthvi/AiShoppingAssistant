<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Controller\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatEntryPipelineInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatIdentityResolverInterface;
use Aavirbhava\AiShoppingAssistant\Controller\Chat\Send;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatPipelineResult;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatRequestIdentity;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatResponseSerializer;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Exception\ChatInputException;
use Aavirbhava\AiShoppingAssistant\Model\Chat\SafeResponse;
use Aavirbhava\AiShoppingAssistant\Model\Store\Exception\StoreScopeException;
use Magento\Framework\App\RequestContentInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Phrase;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the controller stays thin: it resolves identity, calls the
 * pipeline with exactly what the resolver returned, and serializes
 * whatever comes back — general.enabled is never re-checked here with
 * separate logic, only ChatEntryPipelineInterface::handle()'s own
 * existing REASON_ASSISTANT_DISABLED short-circuit governs that, and this
 * controller serializes it like any other outcome.
 */
#[CoversClass(Send::class)]
final class SendTest extends TestCase
{
    private const STORE_ID = 5;

    public function testResolvesIdentityAndPassesItAndTheMessageToThePipeline(): void
    {
        $identity = new ChatRequestIdentity('conv-1', 2, 'masked-cart-abc');

        $identityResolver = $this->createMock(ChatIdentityResolverInterface::class);
        $identityResolver->method('resolve')->with(self::STORE_ID)->willReturn($identity);

        $pipeline = $this->createMock(ChatEntryPipelineInterface::class);
        $pipeline->expects(self::once())
            ->method('handle')
            ->with(self::STORE_ID, 'Show me waterproof phones.', 2, 'masked-cart-abc', 'conv-1')
            ->willReturn(ChatPipelineResult::shortCircuit(new SafeResponse('ok', 'assistant_unavailable')));

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects(self::once())->method('setHttpResponseCode')->with(200);
        $jsonResult->expects(self::once())->method('setData');

        $controller = $this->controller(
            request: $this->request(['message' => 'Show me waterproof phones.']),
            identityResolver: $identityResolver,
            pipeline: $pipeline,
            jsonResult: $jsonResult
        );

        $controller->execute();
    }

    public function testReadsTheMessageFromAJsonRequestBody(): void
    {
        $pipeline = $this->createMock(ChatEntryPipelineInterface::class);
        $pipeline->expects(self::once())
            ->method('handle')
            ->with(self::STORE_ID, 'Hello from JSON.')
            ->willReturn(ChatPipelineResult::shortCircuit(new SafeResponse('ok', 'assistant_unavailable')));

        $request = $this->createMock(RequestContentInterface::class);
        $request->method('getContent')->willReturn(json_encode(['message' => 'Hello from JSON.']));

        $controller = $this->controller(request: $request, pipeline: $pipeline);

        $controller->execute();
    }

    public function testReturns400OnInvalidInputWithoutLettingTheExceptionPropagate(): void
    {
        $pipeline = $this->createMock(ChatEntryPipelineInterface::class);
        $pipeline->method('handle')->willThrowException(new ChatInputException(new Phrase('Message must not be empty.')));

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects(self::once())->method('setHttpResponseCode')->with(400);
        $jsonResult->expects(self::once())->method('setData')->with(
            self::callback(static fn (array $data): bool => $data['error'] === 'invalid_input')
        );

        $controller = $this->controller(
            request: $this->request(['message' => '']),
            pipeline: $pipeline,
            jsonResult: $jsonResult
        );

        $controller->execute();
    }

    public function testReturns503OnAnInactiveStoreWithoutLettingTheExceptionPropagate(): void
    {
        $pipeline = $this->createMock(ChatEntryPipelineInterface::class);
        $pipeline->method('handle')->willThrowException(new StoreScopeException(new Phrase('Store is not active.')));

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects(self::once())->method('setHttpResponseCode')->with(503);

        $controller = $this->controller(
            request: $this->request(['message' => 'hi']),
            pipeline: $pipeline,
            jsonResult: $jsonResult
        );

        $controller->execute();
    }

    public function testCsrfValidationIsAlwaysSkippedForThisJsonEndpoint(): void
    {
        $controller = $this->controller();
        $request = $this->createMock(RequestContentInterface::class);

        self::assertNull($controller->createCsrfValidationException($request));
        self::assertTrue($controller->validateForCsrf($request));
    }

    private function request(array $params): RequestInterface
    {
        $request = $this->createMock(RequestContentInterface::class);
        $request->method('getContent')->willReturn('');
        $request->method('getParam')->willReturnCallback(
            static fn (string $key) => $params[$key] ?? null
        );

        return $request;
    }

    private function controller(
        ?RequestInterface $request = null,
        ?ChatIdentityResolverInterface $identityResolver = null,
        ?ChatEntryPipelineInterface $pipeline = null,
        ?Json $jsonResult = null
    ): Send {
        $request ??= $this->request(['message' => 'hi']);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        if ($identityResolver === null) {
            $identityResolver = $this->createMock(ChatIdentityResolverInterface::class);
            $identityResolver->method('resolve')->willReturn(new ChatRequestIdentity('conv-1', 0, null));
        }

        if ($pipeline === null) {
            $pipeline = $this->createMock(ChatEntryPipelineInterface::class);
            $pipeline->method('handle')->willReturn(
                ChatPipelineResult::shortCircuit(new SafeResponse('ok', 'assistant_unavailable'))
            );
        }

        $jsonResult ??= $this->createMock(Json::class);
        $jsonResultFactory = $this->createMock(JsonFactory::class);
        $jsonResultFactory->method('create')->willReturn($jsonResult);

        return new Send(
            $request,
            $jsonResultFactory,
            $storeManager,
            $identityResolver,
            $pipeline,
            new ChatResponseSerializer()
        );
    }
}
