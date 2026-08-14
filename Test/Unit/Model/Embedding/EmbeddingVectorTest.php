<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingVector;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmbeddingVector::class)]
final class EmbeddingVectorTest extends TestCase
{
    public function testValidVector(): void
    {
        $vector = new EmbeddingVector([0.1, 0.2, 0.3], 3);

        self::assertSame([0.1, 0.2, 0.3], $vector->values());
        self::assertSame(3, $vector->dimension());
    }

    public function testIntegerValuesAreAccepted(): void
    {
        $vector = new EmbeddingVector([1, 2, 3], 3);

        self::assertSame([1, 2, 3], $vector->values());
    }

    public function testEmptyVectorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingVector([], 3);
    }

    public function testCountDimensionMismatchIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingVector([0.1, 0.2], 3);
    }

    public function testDimensionBelowOneIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingVector([], 0);
    }

    public function testNonNumericValueIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingVector(['a', 0.2], 2);
    }

    public function testNullValueIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingVector([null, 0.2], 2);
    }

    public function testNanValueIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingVector([NAN, 0.2], 2);
    }

    public function testInfiniteValueIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingVector([INF, 0.2], 2);
    }
}
