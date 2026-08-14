<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingUsage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmbeddingUsage::class)]
final class EmbeddingUsageTest extends TestCase
{
    public function testValidUsage(): void
    {
        $usage = new EmbeddingUsage(10, 12);

        self::assertSame(10, $usage->inputTokens());
        self::assertSame(12, $usage->totalTokens());
    }

    public function testZeroUsageIsAllowed(): void
    {
        $usage = new EmbeddingUsage(0, 0);

        self::assertSame(0, $usage->inputTokens());
        self::assertSame(0, $usage->totalTokens());
    }

    public function testNegativeInputTokensAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingUsage(-1, 0);
    }

    public function testNegativeTotalTokensAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingUsage(0, -5);
    }
}
