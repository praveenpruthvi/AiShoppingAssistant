<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingInput;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingInputType;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingRequest;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmbeddingRequest::class)]
final class EmbeddingRequestTest extends TestCase
{
    public function testValidRequest(): void
    {
        $request = new EmbeddingRequest(
            3,
            EmbeddingInputType::query(),
            [new EmbeddingInput('blue shoe', '0')],
            'test-model',
            '',
            new SecretValue('secret-value'),
            20,
            1024
        );

        self::assertSame(3, $request->storeId());
        self::assertTrue($request->inputType()->isQuery());
        self::assertCount(1, $request->inputs());
        self::assertSame('test-model', $request->model());
        self::assertSame('', $request->baseUrl());
        self::assertFalse($request->apiKey()->isEmpty());
        self::assertSame(20, $request->timeoutSeconds());
        self::assertSame(1024, $request->dimensions());
    }

    public function testStoreIdBelowOneIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingRequest(
            0,
            EmbeddingInputType::document(),
            [new EmbeddingInput('blue shoe', '0')],
            'test-model',
            '',
            SecretValue::empty(),
            20,
            1024
        );
    }

    public function testEmptyInputsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingRequest(
            1,
            EmbeddingInputType::document(),
            [],
            'test-model',
            '',
            SecretValue::empty(),
            20,
            1024
        );
    }

    public function testEmptyModelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingRequest(
            1,
            EmbeddingInputType::document(),
            [new EmbeddingInput('blue shoe', '0')],
            '',
            '',
            SecretValue::empty(),
            20,
            1024
        );
    }

    public function testTimeoutOutsideRangeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingRequest(
            1,
            EmbeddingInputType::document(),
            [new EmbeddingInput('blue shoe', '0')],
            'test-model',
            '',
            SecretValue::empty(),
            0,
            1024
        );
    }

    public function testDimensionsOutsideRangeAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingRequest(
            1,
            EmbeddingInputType::document(),
            [new EmbeddingInput('blue shoe', '0')],
            'test-model',
            '',
            SecretValue::empty(),
            20,
            0
        );
    }

    public function testJsonSerializationNeverExposesSecret(): void
    {
        $request = new EmbeddingRequest(
            1,
            EmbeddingInputType::document(),
            [new EmbeddingInput('blue shoe', '0')],
            'test-model',
            'https://example.test/v1',
            new SecretValue('top-secret-key'),
            20,
            1024
        );

        $serialized = json_encode($request);

        self::assertNotFalse($serialized);
        self::assertStringNotContainsString('top-secret-key', $serialized);
    }
}
