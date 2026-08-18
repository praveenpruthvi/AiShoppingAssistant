<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Dto;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatRequest::class)]
final class ChatRequestTest extends TestCase
{
    public function testValidRequest(): void
    {
        $request = new ChatRequest(
            storeId: 1,
            messages: [new ChatMessage('user', 'Show waterproof phones.')],
            model: 'test-model',
            baseUrl: '',
            apiKey: new SecretValue('secret-value'),
            timeoutSeconds: 20
        );

        self::assertSame(1, $request->storeId);
        self::assertSame('test-model', $request->model);
        self::assertFalse($request->apiKey->isEmpty());
        self::assertSame(20, $request->timeoutSeconds);
        self::assertSame(1200, $request->maxOutputTokens);
    }

    public function testStoreIdBelowOneIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ChatRequest(
            storeId: 0,
            messages: [new ChatMessage('user', 'hi')],
            model: 'test-model',
            baseUrl: '',
            apiKey: SecretValue::empty(),
            timeoutSeconds: 20
        );
    }

    public function testEmptyMessagesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ChatRequest(
            storeId: 1,
            messages: [],
            model: 'test-model',
            baseUrl: '',
            apiKey: SecretValue::empty(),
            timeoutSeconds: 20
        );
    }

    public function testEmptyModelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ChatRequest(
            storeId: 1,
            messages: [new ChatMessage('user', 'hi')],
            model: '',
            baseUrl: '',
            apiKey: SecretValue::empty(),
            timeoutSeconds: 20
        );
    }

    public function testTimeoutOutsideRangeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ChatRequest(
            storeId: 1,
            messages: [new ChatMessage('user', 'hi')],
            model: 'test-model',
            baseUrl: '',
            apiKey: SecretValue::empty(),
            timeoutSeconds: 0
        );
    }

    public function testMaxOutputTokensOutsideRangeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ChatRequest(
            storeId: 1,
            messages: [new ChatMessage('user', 'hi')],
            model: 'test-model',
            baseUrl: '',
            apiKey: SecretValue::empty(),
            timeoutSeconds: 20,
            maxOutputTokens: 8193
        );
    }

    public function testJsonSerializationNeverExposesSecret(): void
    {
        $request = new ChatRequest(
            storeId: 1,
            messages: [new ChatMessage('user', 'hi')],
            model: 'test-model',
            baseUrl: 'https://example.test/v1',
            apiKey: new SecretValue('top-secret-key'),
            timeoutSeconds: 20
        );

        $serialized = json_encode($request);

        self::assertNotFalse($serialized);
        self::assertStringNotContainsString('top-secret-key', $serialized);
    }
}
