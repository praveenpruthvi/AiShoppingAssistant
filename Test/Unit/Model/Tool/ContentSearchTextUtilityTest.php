<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Tool\ContentSearchTextUtility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentSearchTextUtility::class)]
final class ContentSearchTextUtilityTest extends TestCase
{
    public function testEscapeLikeEscapesWildcardCharacters(): void
    {
        $utility = new ContentSearchTextUtility();

        self::assertSame('50\\% off', $utility->escapeLike('50% off'));
        self::assertSame('a\\_b', $utility->escapeLike('a_b'));
        self::assertSame('back\\\\slash', $utility->escapeLike('back\\slash'));
    }

    public function testEscapeLikeLeavesOrdinaryTextUnchanged(): void
    {
        $utility = new ContentSearchTextUtility();

        self::assertSame('returns policy', $utility->escapeLike('returns policy'));
    }

    public function testSnippetStripsHtmlAndCollapsesWhitespace(): void
    {
        $utility = new ContentSearchTextUtility();

        $snippet = $utility->snippet('<p>Hello   <b>world</b></p>', 'hello');

        self::assertSame('Hello world', $snippet);
    }

    public function testSnippetIsCenteredOnTheMatch(): void
    {
        $utility = new ContentSearchTextUtility();
        $long = str_repeat('padding ', 40) . 'RETURNS POLICY' . str_repeat(' filler', 40);

        $snippet = $utility->snippet($long, 'returns policy', 40);

        self::assertStringContainsString('RETURNS POLICY', $snippet);
        self::assertStringStartsWith('…', $snippet);
    }

    public function testSnippetFallsBackToTheStartWhenTermIsNotFound(): void
    {
        $utility = new ContentSearchTextUtility();

        $snippet = $utility->snippet('<p>Some unrelated content here.</p>', 'nonexistent');

        self::assertStringStartsWith('Some unrelated', $snippet);
    }

    public function testSnippetOfEmptyContentIsEmptyString(): void
    {
        $utility = new ContentSearchTextUtility();

        self::assertSame('', $utility->snippet('<p></p>', 'anything'));
    }
}
