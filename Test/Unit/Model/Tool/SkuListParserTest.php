<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Tool\SkuListParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkuListParser::class)]
final class SkuListParserTest extends TestCase
{
    public function testParsesANonEmptyListOfStrings(): void
    {
        $parsed = (new SkuListParser())->parse(['SKU-1', 'SKU-2']);

        self::assertSame(['SKU-1', 'SKU-2'], $parsed);
    }

    public function testRejectsNull(): void
    {
        self::assertNull((new SkuListParser())->parse(null));
    }

    public function testRejectsANonArrayValue(): void
    {
        self::assertNull((new SkuListParser())->parse('SKU-1'));
    }

    public function testRejectsAnEmptyArray(): void
    {
        self::assertNull((new SkuListParser())->parse([]));
    }

    public function testRejectsAnArrayContainingANonStringEntry(): void
    {
        self::assertNull((new SkuListParser())->parse(['SKU-1', 42]));
    }

    public function testRejectsAnArrayContainingABlankString(): void
    {
        self::assertNull((new SkuListParser())->parse(['SKU-1', '   ']));
    }
}
