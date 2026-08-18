<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal\TextRelevanceSignal;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextRelevanceSignal::class)]
final class TextRelevanceSignalTest extends TestCase
{
    private function candidate(float $bm25Score): SearchCandidate
    {
        return new SearchCandidate(1, 'SKU-1', 1, 'Name', '', [], [], true, 4, $bm25Score, 0.0);
    }

    public function testHigherBm25ScoreYieldsHigherContribution(): void
    {
        $signal = new TextRelevanceSignal();
        $context = new SearchContext(1, 'query', false);

        [$low, $high] = $signal->apply($context, [$this->candidate(1.0), $this->candidate(10.0)]);

        self::assertGreaterThan($low->score, $high->score);
    }

    public function testZeroBm25ScoreContributesNothing(): void
    {
        $signal = new TextRelevanceSignal();
        $context = new SearchContext(1, 'query', false);

        [$result] = $signal->apply($context, [$this->candidate(0.0)]);

        self::assertSame(0.0, $result->score);
    }

    public function testContributionIsBoundedBelowOne(): void
    {
        $signal = new TextRelevanceSignal();
        $context = new SearchContext(1, 'query', false);

        [$result] = $signal->apply($context, [$this->candidate(1000000.0)]);

        self::assertLessThan(1.0, $result->score);
    }

    public function testAddsToExistingScoreRatherThanReplacingIt(): void
    {
        $signal = new TextRelevanceSignal();
        $context = new SearchContext(1, 'query', false);
        $candidate = $this->candidate(1.0)->withScore(5.0);

        [$result] = $signal->apply($context, [$candidate]);

        self::assertGreaterThan(5.0, $result->score);
    }
}
