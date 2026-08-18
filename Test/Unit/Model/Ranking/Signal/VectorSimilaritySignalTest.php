<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal\VectorSimilaritySignal;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VectorSimilaritySignal::class)]
final class VectorSimilaritySignalTest extends TestCase
{
    private function candidate(float $vectorScore): SearchCandidate
    {
        return new SearchCandidate(1, 'SKU-1', 1, 'Name', '', [], [], true, 4, 0.0, $vectorScore);
    }

    public function testAddsVectorScoreDirectlyToRunningScore(): void
    {
        $signal = new VectorSimilaritySignal();
        $context = new SearchContext(1, 'query', false);

        [$result] = $signal->apply($context, [$this->candidate(0.8)->withScore(1.0)]);

        self::assertSame(1.8, $result->score);
    }

    public function testClampsAboveOneDefensively(): void
    {
        $signal = new VectorSimilaritySignal();
        $context = new SearchContext(1, 'query', false);

        [$result] = $signal->apply($context, [$this->candidate(1.5)]);

        self::assertSame(1.0, $result->score);
    }

    public function testZeroVectorScoreContributesNothing(): void
    {
        $signal = new VectorSimilaritySignal();
        $context = new SearchContext(1, 'query', false);

        [$result] = $signal->apply($context, [$this->candidate(0.0)]);

        self::assertSame(0.0, $result->score);
    }
}
