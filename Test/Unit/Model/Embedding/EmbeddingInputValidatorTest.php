<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingInputValidator;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingInputException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmbeddingInputValidator::class)]
final class EmbeddingInputValidatorTest extends TestCase
{
    public function testValidBatchGetsTrimmedAndPositionalIdentifiers(): void
    {
        $inputs = (new EmbeddingInputValidator())->validate([' blue shoe ', 'red hat']);

        self::assertCount(2, $inputs);
        self::assertSame('blue shoe', $inputs[0]->text());
        self::assertSame('0', $inputs[0]->identifier());
        self::assertSame('red hat', $inputs[1]->text());
        self::assertSame('1', $inputs[1]->identifier());
    }

    public function testEmptyBatchIsRejected(): void
    {
        $this->expectException(EmbeddingInputException::class);
        (new EmbeddingInputValidator())->validate([]);
    }

    public function testEmptyBatchErrorCodeIsStable(): void
    {
        try {
            (new EmbeddingInputValidator())->validate([]);
            self::fail('Expected EmbeddingInputException.');
        } catch (EmbeddingInputException $exception) {
            self::assertSame('embedding_input_invalid', $exception->errorCode());
        }
    }

    public function testWhitespaceOnlyTextIsRejected(): void
    {
        $this->expectException(EmbeddingInputException::class);
        (new EmbeddingInputValidator())->validate(['   ']);
    }

    public function testNonStringTextIsRejected(): void
    {
        $this->expectException(EmbeddingInputException::class);
        (new EmbeddingInputValidator())->validate([42]);
    }

    public function testTooManyTextsAreRejected(): void
    {
        $texts = array_fill(0, EmbeddingInputValidator::MAX_TEXTS_PER_REQUEST + 1, 'x');

        $this->expectException(EmbeddingInputException::class);
        (new EmbeddingInputValidator())->validate($texts);
    }

    public function testTextOverMaxLengthIsRejectedNeverTruncated(): void
    {
        $this->expectException(EmbeddingInputException::class);
        (new EmbeddingInputValidator())->validate([str_repeat('x', EmbeddingInputValidator::MAX_TEXT_BYTES + 1)]);
    }

    public function testCombinedTextsOverMaxTotalAreRejected(): void
    {
        $single = str_repeat('x', 8000);
        $texts = array_fill(0, 26, $single);

        $this->expectException(EmbeddingInputException::class);
        (new EmbeddingInputValidator())->validate($texts);
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $this->expectException(EmbeddingInputException::class);
        (new EmbeddingInputValidator())->validate(["\xC3\x28 invalid"]);
    }
}
