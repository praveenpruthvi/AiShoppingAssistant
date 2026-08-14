<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmbeddingInput::class)]
final class EmbeddingInputTest extends TestCase
{
    public function testValidInput(): void
    {
        $input = new EmbeddingInput('blue running shoe', '0');

        self::assertSame('blue running shoe', $input->text());
        self::assertSame('0', $input->identifier());
    }

    public function testWhitespaceOnlyTextIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingInput('   ', '0');
    }

    public function testEmptyTextIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingInput('', '0');
    }

    public function testEmptyIdentifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmbeddingInput('blue shoe', '');
    }
}
