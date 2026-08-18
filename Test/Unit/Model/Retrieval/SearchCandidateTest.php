<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Retrieval;

use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchCandidate::class)]
final class SearchCandidateTest extends TestCase
{
    private function candidate(float $score = 0.0): SearchCandidate
    {
        return new SearchCandidate(42, 'SKU-42', 1, 'Blue Shoe', 'desc', ['Shoes'], [], true, 4, 1.0, 0.5, $score);
    }

    public function testWithScoreReturnsANewInstanceWithEveryOtherFieldPreserved(): void
    {
        $original = $this->candidate(0.0);

        $updated = $original->withScore(2.5);

        self::assertSame(0.0, $original->score);
        self::assertSame(2.5, $updated->score);
        self::assertSame($original->entityId, $updated->entityId);
        self::assertSame($original->sku, $updated->sku);
        self::assertSame($original->bm25Score, $updated->bm25Score);
        self::assertSame($original->vectorScore, $updated->vectorScore);
        self::assertNotSame($original, $updated);
    }

    public function testRejectsNonPositiveEntityId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchCandidate(0, 'SKU-1', 1, 'Name', '', [], [], true, 4, 0.0, 0.0);
    }

    public function testRejectsEmptySku(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchCandidate(1, '', 1, 'Name', '', [], [], true, 4, 0.0, 0.0);
    }

    public function testRejectsNegativeRetrievalScores(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchCandidate(1, 'SKU-1', 1, 'Name', '', [], [], true, 4, -0.1, 0.0);
    }
}
