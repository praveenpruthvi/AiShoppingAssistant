<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\UntrustedContentSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UntrustedContentSanitizer::class)]
final class UntrustedContentSanitizerTest extends TestCase
{
    private UntrustedContentSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new UntrustedContentSanitizer();
    }

    public function testReturnsEmptyForNullAndEmptyInput(): void
    {
        self::assertSame('', $this->sanitizer->sanitize(null));
        self::assertSame('', $this->sanitizer->sanitize(''));
        self::assertSame('', $this->sanitizer->sanitize('   '));
    }

    public function testPlainTextPassesThrough(): void
    {
        self::assertSame(
            'Waterproof running shoes',
            $this->sanitizer->sanitize('Waterproof running shoes')
        );
    }

    public function testDecodesEntitiesInPlainText(): void
    {
        self::assertSame(
            'Tom & Jerry <3',
            $this->sanitizer->sanitize('Tom &amp; Jerry &lt;3')
        );
    }

    public function testRemovesScriptsInHtml(): void
    {
        self::assertSame(
            'Buy now',
            $this->sanitizer->sanitize("<div><script>alert('x')</script><p>Buy now</p></div>")
        );
    }

    public function testRemovesEntityEncodedScriptsInPlainText(): void
    {
        self::assertSame(
            'Buy now',
            $this->sanitizer->sanitize('&lt;script&gt;alert(1)&lt;/script&gt;Buy now')
        );
    }

    public function testRemovesInlineEventHandlersWithTheirTags(): void
    {
        self::assertSame(
            'Stay safe',
            $this->sanitizer->sanitize('<p>Stay safe</p><img src="x" onerror="alert(1)">')
        );
    }

    public function testRemovesHiddenContent(): void
    {
        self::assertSame(
            'Visible',
            $this->sanitizer->sanitize(
                '<p>Visible</p><div style="display:none">Hidden text</div><span hidden>Also hidden</span>'
            )
        );
    }

    public function testRemovesComments(): void
    {
        self::assertSame(
            'real',
            $this->sanitizer->sanitize('<!-- secret comment --><p>real</p>')
        );
    }

    public function testDoesNotResolveExternalEntities(): void
    {
        $input = '<!DOCTYPE foo [<!ENTITY ext SYSTEM "file:///etc/passwd">]><p>&ext;secret</p>';
        $result = $this->sanitizer->sanitize($input);

        self::assertStringContainsString('secret', $result);
        self::assertStringNotContainsString('root:', $result);
    }

    public function testRemovesControlCharacters(): void
    {
        self::assertSame(
            'abcd',
            $this->sanitizer->sanitize("a\x00b\x07c\x1Fd")
        );
    }

    public function testCollapsesWhitespace(): void
    {
        self::assertSame(
            'Line1 Line2 Line3',
            $this->sanitizer->sanitize("Line1\n\n  Line2\t\tLine3")
        );
    }

    public function testPreservesUnicodeAndEmoji(): void
    {
        $text = 'ಕನ್ನಡ ಸ್ವರಗಳು ಸುಂದರ 🚀';

        self::assertSame($text, $this->sanitizer->sanitize($text));
    }

    public function testTruncatesOversizedRawInput(): void
    {
        $result = $this->sanitizer->sanitize(str_repeat('a', 150000));

        self::assertSame(UntrustedContentSanitizer::MAX_RAW_INPUT_CHARACTERS, mb_strlen($result));
    }

    public function testStripsAngleBracketComparisonsWithoutKnownTags(): void
    {
        self::assertSame(
            '10 < y and z > 2',
            $this->sanitizer->sanitize('10 < y and z > 2')
        );
    }
}