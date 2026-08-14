<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingInputType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmbeddingInputType::class)]
final class EmbeddingInputTypeTest extends TestCase
{
    public function testDocumentValue(): void
    {
        $type = EmbeddingInputType::document();

        self::assertSame('document', $type->value());
        self::assertTrue($type->isDocument());
        self::assertFalse($type->isQuery());
    }

    public function testQueryValue(): void
    {
        $type = EmbeddingInputType::query();

        self::assertSame('query', $type->value());
        self::assertTrue($type->isQuery());
        self::assertFalse($type->isDocument());
    }

    public function testFromValueAcceptsOnlyAllowedValues(): void
    {
        self::assertTrue(EmbeddingInputType::fromValue('document')->isDocument());
        self::assertTrue(EmbeddingInputType::fromValue('query')->isQuery());
    }

    public function testFromValueRejectsUnknownValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmbeddingInputType::fromValue('sentence');
    }

    public function testFromValueIsCaseSensitive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmbeddingInputType::fromValue('Query');
    }
}
