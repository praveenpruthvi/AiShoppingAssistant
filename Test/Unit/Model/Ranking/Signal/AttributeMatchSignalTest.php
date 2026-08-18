<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal\AttributeMatchSignal;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttributeMatchSignal::class)]
final class AttributeMatchSignalTest extends TestCase
{
    private function candidate(array $categoryNames, array $attributes): SearchCandidate
    {
        return new SearchCandidate(1, 'SKU-1', 1, 'Name', '', $categoryNames, $attributes, true, 4, 0.0, 0.0);
    }

    public function testBoostsWhenCategoryNameOverlapsQuery(): void
    {
        $signal = new AttributeMatchSignal();
        $context = new SearchContext(1, 'waterproof hiking boots', false);

        [$result] = $signal->apply($context, [$this->candidate(['Hiking Boots'], [])]);

        self::assertGreaterThan(0.0, $result->score);
    }

    public function testBoostsWhenAttributeValueOverlapsQuery(): void
    {
        $signal = new AttributeMatchSignal();
        $context = new SearchContext(1, 'blue running shoes', false);

        $attributes = [['code' => 'color', 'label' => 'Color', 'values' => ['Blue']]];
        [$result] = $signal->apply($context, [$this->candidate([], $attributes)]);

        self::assertGreaterThan(0.0, $result->score);
    }

    public function testNoOverlapContributesNothing(): void
    {
        $signal = new AttributeMatchSignal();
        $context = new SearchContext(1, 'blue running shoes', false);

        [$result] = $signal->apply($context, [$this->candidate(['Kitchenware'], [])]);

        self::assertSame(0.0, $result->score);
    }

    public function testMatchIsCaseInsensitive(): void
    {
        $signal = new AttributeMatchSignal();
        $context = new SearchContext(1, 'BLUE shoes', false);

        [$result] = $signal->apply($context, [$this->candidate([], [
            ['code' => 'color', 'label' => 'Color', 'values' => ['blue']],
        ])]);

        self::assertGreaterThan(0.0, $result->score);
    }

    public function testBoostIsCappedRegardlessOfMatchCount(): void
    {
        $signal = new AttributeMatchSignal();
        $context = new SearchContext(1, 'red blue green yellow purple orange shoes', false);

        $attributes = [['code' => 'color', 'label' => 'Color', 'values' => ['Red', 'Blue', 'Green', 'Yellow', 'Purple', 'Orange']]];
        [$result] = $signal->apply($context, [$this->candidate([], $attributes)]);

        self::assertLessThanOrEqual(0.5, $result->score);
    }

    public function testShortTokensAreIgnored(): void
    {
        $signal = new AttributeMatchSignal();
        $context = new SearchContext(1, 'a of it', false);

        [$result] = $signal->apply($context, [$this->candidate(['a', 'of', 'it'], [])]);

        self::assertSame(0.0, $result->score);
    }
}
