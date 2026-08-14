<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingResult;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingUsage;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingVector;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmbeddingResult::class)]
final class EmbeddingResultTest extends TestCase
{
    public function testValidResult(): void
    {
        $vector = new EmbeddingVector([0.1, 0.2], 2);
        $usage = new EmbeddingUsage(5, 6);

        $result = new EmbeddingResult([$vector], ['0'], 'test-model', $usage);

        self::assertSame([$vector], $result->vectors());
        self::assertSame(['0'], $result->inputIdentifiers());
        self::assertSame('test-model', $result->model());
        self::assertSame($usage, $result->usage());
    }

    public function testEmptyVectorsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingResult([], [], 'test-model', new EmbeddingUsage(0, 0));
    }

    public function testIdentifierCountMismatchIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingResult(
            [new EmbeddingVector([0.1], 1)],
            ['0', '1'],
            'test-model',
            new EmbeddingUsage(0, 0)
        );
    }

    public function testEmptyIdentifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingResult(
            [new EmbeddingVector([0.1], 1)],
            [''],
            'test-model',
            new EmbeddingUsage(0, 0)
        );
    }

    public function testDuplicateIdentifiersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingResult(
            [new EmbeddingVector([0.1], 1), new EmbeddingVector([0.2], 1)],
            ['0', '0'],
            'test-model',
            new EmbeddingUsage(0, 0)
        );
    }

    public function testEmptyModelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingResult(
            [new EmbeddingVector([0.1], 1)],
            ['0'],
            '',
            new EmbeddingUsage(0, 0)
        );
    }
}
