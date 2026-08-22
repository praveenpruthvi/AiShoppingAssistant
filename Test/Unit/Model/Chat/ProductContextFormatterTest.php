<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ProductContextFormatter;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductContextFormatter::class)]
final class ProductContextFormatterTest extends TestCase
{
    private const STORE_ID = 1;

    public function testEmptyCandidateListReturnsNull(): void
    {
        $formatter = $this->formatter();

        self::assertNull($formatter->format(self::STORE_ID, []));
    }

    public function testFormatsAsASystemMessage(): void
    {
        $formatter = $this->formatter();
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

        $message = $formatter->format(self::STORE_ID, [$candidate]);

        self::assertNotNull($message);
        self::assertSame('system', $message->role);
        self::assertStringContainsString('SKU-1', $message->content);
        self::assertStringContainsString('Blue Shoe', $message->content);
        self::assertStringContainsString('Shoes', $message->content);
        self::assertStringContainsString('Blue', $message->content);
    }

    public function testInstructsTheModelNotToInventDataOrStatePriceStock(): void
    {
        $formatter = $this->formatter();
        $candidate = new SearchCandidate(1, 'SKU-1', 1, 'Name', '', [], [], true, 4, 0.0, 0.0);

        $message = $formatter->format(self::STORE_ID, [$candidate]);

        self::assertStringContainsString('invent a SKU', $message->content);
        self::assertStringContainsString('price', $message->content);
        self::assertStringContainsString('stock', $message->content);
    }

    public function testPermitsReferencingAProductAlreadyNamedEarlierInTheConversation(): void
    {
        $formatter = $this->formatter();
        $candidate = new SearchCandidate(1, 'SKU-1', 1, 'Name', '', [], [], true, 4, 0.0, 0.0);

        $message = $formatter->format(self::STORE_ID, [$candidate]);

        self::assertStringContainsString('already named', $message->content);
        self::assertStringContainsString('earlier in this', $message->content);
    }

    public function testAttributesWithNoValuesAreOmitted(): void
    {
        $formatter = $this->formatter();
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

        $message = $formatter->format(self::STORE_ID, [$candidate]);

        self::assertStringNotContainsString('Color:', $message->content);
    }

    /**
     * Task 48, default (No) behavior: byte-identical to how this class
     * has always behaved — category names are included exactly as
     * before this task, a regression-proof default.
     */
    public function testTokenOptimizationDisabledIncludesCategoryNames(): void
    {
        $formatter = $this->formatter(tokenOptimizationEnabled: false);
        $candidate = $this->candidateWithCategoriesAndAttributes();

        $message = $formatter->format(self::STORE_ID, [$candidate]);

        self::assertStringContainsString('Categories: Shoes, Footwear', $message->content);
        self::assertStringContainsString('Color: Blue', $message->content);
    }

    /**
     * Task 48, opted-in (Yes) behavior: category names are dropped —
     * audited before trimming (see ProductContextFormatter's own
     * docblock): LlmResponseSchema::schema() never has a `category`
     * property anywhere, so nothing downstream ever reads it back out
     * of the model's response. SKU/Name/attributes — the fields the
     * response schema and OutputValidator's fabricated_sku check
     * genuinely depend on — are unaffected, and the resulting context
     * is measurably (not just superficially) smaller.
     */
    public function testTokenOptimizationEnabledDropsCategoryNamesButKeepsSkuNameAndAttributes(): void
    {
        $disabledMessage = $this->formatter(tokenOptimizationEnabled: false)
            ->format(self::STORE_ID, [$this->candidateWithCategoriesAndAttributes()]);
        $enabledMessage = $this->formatter(tokenOptimizationEnabled: true)
            ->format(self::STORE_ID, [$this->candidateWithCategoriesAndAttributes()]);

        self::assertStringNotContainsString('Categories:', $enabledMessage->content);
        self::assertStringNotContainsString('Shoes', $enabledMessage->content);
        self::assertStringContainsString('SKU-1', $enabledMessage->content);
        self::assertStringContainsString('Blue Shoe', $enabledMessage->content);
        self::assertStringContainsString('Color: Blue', $enabledMessage->content);

        self::assertLessThan(
            strlen($disabledMessage->content),
            strlen($enabledMessage->content),
            'The trimmed context must be measurably smaller, not just differently worded.'
        );
    }

    private function candidateWithCategoriesAndAttributes(): SearchCandidate
    {
        return new SearchCandidate(
            1,
            'SKU-1',
            1,
            'Blue Shoe',
            '',
            ['Shoes', 'Footwear'],
            [['code' => 'color', 'label' => 'Color', 'values' => ['Blue']]],
            true,
            4,
            0.0,
            0.0
        );
    }

    private function formatter(bool $tokenOptimizationEnabled = false): ProductContextFormatter
    {
        $general = $this->createMock(GeneralConfigInterface::class);
        $general->method('isTokenOptimizationEnabled')->willReturn($tokenOptimizationEnabled);

        $configReader = $this->createMock(ConfigurationReaderInterface::class);
        $configReader->method('readGeneral')->with(self::STORE_ID)->willReturn($general);

        return new ProductContextFormatter($configReader);
    }
}
