<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\RetrievalConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal\RatingSignal;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RatingSignal::class)]
final class RatingSignalTest extends TestCase
{
    private const STORE_ID = 1;

    public function testAHighReviewCountProductOutranksAOneReviewProductDespiteALowerRawAverage(): void
    {
        // Required correctness case: a single 5-star review must not outrank
        // a 500-review 4.7-star product once blended toward the catalogue mean.
        $oneReview = $this->candidate(1, ratingAverage: 5.0, reviewCount: 1, catalogRatingAverage: 3.5);
        $wellReviewed = $this->candidate(2, ratingAverage: 4.7, reviewCount: 500, catalogRatingAverage: 3.5);

        $signal = new RatingSignal($this->configurationReader(weight: 1.0));

        $result = $signal->apply($this->context(), [$oneReview, $wellReviewed]);

        $scores = $this->scoresByEntityId($result);

        self::assertGreaterThan($scores[1], $scores[2]);
    }

    public function testAZeroReviewProductScoresExactlyTheCatalogueAverageWithNoSpecialCaseBranch(): void
    {
        $unrated = $this->candidate(1, ratingAverage: 0.0, reviewCount: 0, catalogRatingAverage: 3.5);

        $signal = new RatingSignal($this->configurationReader(weight: 1.0));

        $result = $signal->apply($this->context(), [$unrated]);

        // With v=0, WR = (0/(0+m))*R + (m/(0+m))*C = C exactly, normalized by
        // the same /5.0 scale as every other case — the formula falls
        // through to this on its own, no branch checks reviewCount === 0.
        self::assertSame(3.5 / 5.0, $result[0]->score);
    }

    public function testWeightZeroLeavesCandidatesUntouched(): void
    {
        $candidate = $this->candidate(1, ratingAverage: 5.0, reviewCount: 500, catalogRatingAverage: 1.0, score: 0.42);

        $signal = new RatingSignal($this->configurationReader(weight: 0.0));

        $result = $signal->apply($this->context(), [$candidate]);

        self::assertSame(0.42, $result[0]->score);
    }

    public function testWeightScalesTheSignalsContributionLinearly(): void
    {
        $candidate = $this->candidate(1, ratingAverage: 5.0, reviewCount: 1_000_000, catalogRatingAverage: 5.0);

        $fullWeight = (new RatingSignal($this->configurationReader(weight: 1.0)))
            ->apply($this->context(), [$candidate])[0]->score;
        $halfWeight = (new RatingSignal($this->configurationReader(weight: 0.5)))
            ->apply($this->context(), [$candidate])[0]->score;

        self::assertEqualsWithDelta($fullWeight / 2.0, $halfWeight, 0.0001);
    }

    public function testAddsToTheRunningScoreRatherThanReplacingIt(): void
    {
        $candidate = $this->candidate(1, ratingAverage: 5.0, reviewCount: 500, catalogRatingAverage: 5.0, score: 2.0);

        $signal = new RatingSignal($this->configurationReader(weight: 0.1));

        $result = $signal->apply($this->context(), [$candidate]);

        self::assertGreaterThan(2.0, $result[0]->score);
    }

    private function candidate(
        int $entityId,
        float $ratingAverage,
        int $reviewCount,
        float $catalogRatingAverage,
        float $score = 0.0
    ): SearchCandidate {
        return new SearchCandidate(
            $entityId,
            "SKU-$entityId",
            self::STORE_ID,
            'Name',
            '',
            [],
            [],
            true,
            4,
            0.0,
            0.0,
            $score,
            $ratingAverage,
            $reviewCount,
            $catalogRatingAverage
        );
    }

    /**
     * @param list<SearchCandidate> $candidates
     *
     * @return array<int, float>
     */
    private function scoresByEntityId(array $candidates): array
    {
        $scores = [];
        foreach ($candidates as $candidate) {
            $scores[$candidate->entityId] = $candidate->score;
        }

        return $scores;
    }

    private function context(): SearchContext
    {
        return new SearchContext(self::STORE_ID, 'query', false);
    }

    private function configurationReader(float $weight): ConfigurationReaderInterface
    {
        $retrievalConfig = $this->createMock(RetrievalConfigInterface::class);
        $retrievalConfig->method('ratingSignalWeight')->willReturn($weight);

        $configReader = $this->createMock(ConfigurationReaderInterface::class);
        $configReader->method('readRetrieval')->with(self::STORE_ID)->willReturn($retrievalConfig);

        return $configReader;
    }
}
