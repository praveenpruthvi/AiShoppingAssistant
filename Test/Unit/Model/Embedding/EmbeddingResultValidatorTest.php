<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingResultInterface;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingResult;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingResultValidator;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingUsage;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingVector;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingDimensionException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingResponseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmbeddingResultValidator::class)]
final class EmbeddingResultValidatorTest extends TestCase
{
    public function testValidResultPasses(): void
    {
        $result = new EmbeddingResult(
            [new EmbeddingVector([0.1, 0.2], 2)],
            ['0'],
            'test-model',
            new EmbeddingUsage(1, 1)
        );

        (new EmbeddingResultValidator())->validate($result, ['0'], 2);

        $this->addToAssertionCount(1);
    }

    public function testReorderedIdentifiersAreRejected(): void
    {
        $result = new EmbeddingResult(
            [new EmbeddingVector([0.1], 1), new EmbeddingVector([0.2], 1)],
            ['1', '0'],
            'test-model',
            new EmbeddingUsage(1, 1)
        );

        $this->expectException(EmbeddingResponseException::class);
        (new EmbeddingResultValidator())->validate($result, ['0', '1'], 1);
    }

    public function testUnknownIdentifierIsRejected(): void
    {
        $result = new EmbeddingResult(
            [new EmbeddingVector([0.1], 1), new EmbeddingVector([0.2], 1)],
            ['0', 'x'],
            'test-model',
            new EmbeddingUsage(1, 1)
        );

        $this->expectException(EmbeddingResponseException::class);
        (new EmbeddingResultValidator())->validate($result, ['0', '1'], 1);
    }

    public function testVectorCountMismatchIsRejected(): void
    {
        $result = $this->createMock(EmbeddingResultInterface::class);
        $result->method('inputIdentifiers')->willReturn(['0', '1']);
        $result->method('vectors')->willReturn(
            [new EmbeddingVector([0.1], 1)]
        );

        $this->expectException(EmbeddingResponseException::class);
        (new EmbeddingResultValidator())->validate($result, ['0', '1'], 1);
    }

    public function testDimensionMismatchIsRejected(): void
    {
        $result = new EmbeddingResult(
            [new EmbeddingVector([0.1, 0.2, 0.3], 3)],
            ['0'],
            'test-model',
            new EmbeddingUsage(1, 1)
        );

        $this->expectException(EmbeddingDimensionException::class);
        (new EmbeddingResultValidator())->validate($result, ['0'], 2);
    }

    public function testDimensionMismatchUsesStableErrorCode(): void
    {
        $result = new EmbeddingResult(
            [new EmbeddingVector([0.1, 0.2, 0.3], 3)],
            ['0'],
            'test-model',
            new EmbeddingUsage(1, 1)
        );

        try {
            (new EmbeddingResultValidator())->validate($result, ['0'], 2);
            self::fail('Expected EmbeddingDimensionException.');
        } catch (EmbeddingDimensionException $exception) {
            self::assertSame('embedding_dimension_mismatch', $exception->errorCode());
        }
    }
}
