<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\ProductContextFormatter;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductContextFormatter::class)]
final class ProductContextFormatterTest extends TestCase
{
    public function testEmptyCandidateListReturnsNull(): void
    {
        $formatter = new ProductContextFormatter();

        self::assertNull($formatter->format([]));
    }

    public function testFormatsAsASystemMessage(): void
    {
        $formatter = new ProductContextFormatter();
        $candidate = new SearchCandidate(
            1,
            'SKU-1',
            1,
            'Blue Shoe',
            '',
            ['Shoes'],
            [['code' => 'color', 'label' => 'Color', 'values' => ['Blue']]],
            true,
            4,
            0.0,
            0.0
        );

        $message = $formatter->format([$candidate]);

        self::assertNotNull($message);
        self::assertSame('system', $message->role);
        self::assertStringContainsString('SKU-1', $message->content);
        self::assertStringContainsString('Blue Shoe', $message->content);
        self::assertStringContainsString('Shoes', $message->content);
        self::assertStringContainsString('Blue', $message->content);
    }

    public function testInstructsTheModelNotToInventDataOrStatePriceStock(): void
    {
        $formatter = new ProductContextFormatter();
        $candidate = new SearchCandidate(1, 'SKU-1', 1, 'Name', '', [], [], true, 4, 0.0, 0.0);

        $message = $formatter->format([$candidate]);

        self::assertStringContainsString('never invent', $message->content);
        self::assertStringContainsString('price', $message->content);
        self::assertStringContainsString('stock', $message->content);
    }

    public function testAttributesWithNoValuesAreOmitted(): void
    {
        $formatter = new ProductContextFormatter();
        $candidate = new SearchCandidate(
            1,
            'SKU-1',
            1,
            'Name',
            '',
            [],
            [['code' => 'color', 'label' => 'Color', 'values' => []]],
            true,
            4,
            0.0,
            0.0
        );

        $message = $formatter->format([$candidate]);

        self::assertStringNotContainsString('Color:', $message->content);
    }
}
