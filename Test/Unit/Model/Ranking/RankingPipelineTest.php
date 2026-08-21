<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Ranking;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\RetrievalConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Merchandising\ActiveBoostReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalCollectorInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalInterface;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\MerchandisingBoostRow;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\RankingPipeline;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal\AttributeMatchSignal;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal\AvailabilitySignal;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal\MerchandisingBoostSignal;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal\RatingSignal;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal\TextRelevanceSignal;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal\VectorSimilaritySignal;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RankingPipeline::class)]
final class RankingPipelineTest extends TestCase
{
    private const STORE_ID = 1;

    private function candidate(int $entityId, float $score = 0.0): SearchCandidate
    {
        return (new SearchCandidate($entityId, "SKU-$entityId", self::STORE_ID, 'Name', '', [], [], true, 4, 0.0, 0.0))
            ->withScore($score);
    }

    public function testRunsCandidatesThroughEverySignalInOrder(): void
    {
        $calls = [];
        $signalA = $this->fakeSignal(function (array $candidates) use (&$calls) {
            $calls[] = 'a';
            return array_map(static fn (SearchCandidate $c) => $c->withScore($c->score + 1.0), $candidates);
        });
        $signalB = $this->fakeSignal(function (array $candidates) use (&$calls) {
            $calls[] = 'b';
            return array_map(static fn (SearchCandidate $c) => $c->withScore($c->score + 10.0), $candidates);
        });

        $pipeline = $this->pipeline(['first' => $signalA, 'second' => $signalB]);

        $result = $pipeline->rank(new SearchContext(self::STORE_ID, 'query', false), [$this->candidate(1)]);

        self::assertSame(['a', 'b'], $calls);
        self::assertSame(11.0, $result[0]->score);
    }

    public function testSortsDescendingByFinalScore(): void
    {
        $pipeline = $this->pipeline([]);

        $result = $pipeline->rank(
            new SearchContext(self::STORE_ID, 'query', false),
            [$this->candidate(1, 1.0), $this->candidate(2, 3.0), $this->candidate(3, 2.0)]
        );

        self::assertSame([2, 3, 1], array_map(static fn (SearchCandidate $c) => $c->entityId, $result));
    }

    public function testTiesAreBrokenDeterministicallyByEntityId(): void
    {
        $pipeline = $this->pipeline([]);

        $result = $pipeline->rank(
            new SearchContext(self::STORE_ID, 'query', false),
            [$this->candidate(5, 1.0), $this->candidate(2, 1.0), $this->candidate(9, 1.0)]
        );

        self::assertSame([2, 5, 9], array_map(static fn (SearchCandidate $c) => $c->entityId, $result));
    }

    public function testCapsResultAtFinalProductsConfig(): void
    {
        $pipeline = $this->pipeline([], finalProducts: 2);

        $result = $pipeline->rank(
            new SearchContext(self::STORE_ID, 'query', false),
            [$this->candidate(1, 1.0), $this->candidate(2, 2.0), $this->candidate(3, 3.0)]
        );

        self::assertCount(2, $result);
        self::assertSame([3, 2], array_map(static fn (SearchCandidate $c) => $c->entityId, $result));
    }

    public function testExtraSignalRunsWithoutAnyChangeToExistingSignalsOrThisClass(): void
    {
        // Proves the extensibility contract: a brand-new signal (standing in
        // for a future Phase 2 PromotionSignal) plugs in purely by being
        // added to the constructor array, exactly like a real di.xml
        // registration would add it — no change to RankingPipeline itself.
        $phase2Signal = $this->fakeSignal(
            static fn (array $candidates) => array_map(static fn (SearchCandidate $c) => $c->withScore($c->score + 100.0), $candidates)
        );

        $pipeline = $this->pipeline(['phase2_stand_in' => $phase2Signal]);

        $result = $pipeline->rank(new SearchContext(self::STORE_ID, 'query', false), [$this->candidate(1)]);

        self::assertSame(100.0, $result[0]->score);
    }

    public function testCollectorRecordsEachSignalsStageInOrderWithTheRealDiKeyAsIdentifier(): void
    {
        $signalA = $this->fakeSignal(
            static fn (array $candidates) => array_map(static fn (SearchCandidate $c) => $c->withScore($c->score + 1.0), $candidates)
        );
        $signalB = $this->fakeSignal(
            static fn (array $candidates) => array_map(static fn (SearchCandidate $c) => $c->withScore($c->score + 10.0), $candidates)
        );

        $pipeline = $this->pipeline(['text_relevance' => $signalA, 'availability' => $signalB]);

        $collector = $this->createMock(RankingSignalCollectorInterface::class);
        $calls = [];
        $collector->expects(self::exactly(2))
            ->method('recordStage')
            ->willReturnCallback(function (string $identifier, array $candidates) use (&$calls): void {
                $calls[] = [$identifier, $candidates[0]->score];
            });

        $pipeline->rank(new SearchContext(self::STORE_ID, 'query', false), [$this->candidate(1)], $collector);

        self::assertSame([['text_relevance', 1.0], ['availability', 11.0]], $calls);
    }

    public function testNullCollectorIsFullyOptionalAndChangesNoBehavior(): void
    {
        $pipeline = $this->pipeline(['first' => $this->fakeSignal(
            static fn (array $candidates) => array_map(static fn (SearchCandidate $c) => $c->withScore($c->score + 1.0), $candidates)
        )]);

        $result = $pipeline->rank(new SearchContext(self::STORE_ID, 'query', false), [$this->candidate(1)], null);

        self::assertSame(1.0, $result[0]->score);
    }

    public function testRatingSignalRunsAlongsideTheFourExistingSignalsWithoutBreakingThem(): void
    {
        // Wires the five real, production signal classes (not fakes) through
        // the real pipeline exactly as etc/di.xml registers them, proving
        // RatingSignal's addition doesn't disturb text relevance, vector
        // similarity, attribute match, or availability's own behavior.
        $retrievalConfig = $this->createMock(RetrievalConfigInterface::class);
        $retrievalConfig->method('finalProducts')->willReturn(8);
        $retrievalConfig->method('ratingSignalWeight')->willReturn(0.1);

        $configReader = $this->createMock(ConfigurationReaderInterface::class);
        $configReader->method('readRetrieval')->with(self::STORE_ID)->willReturn($retrievalConfig);

        $pipeline = new RankingPipeline($configReader, [
            'text_relevance' => new TextRelevanceSignal(),
            'vector_similarity' => new VectorSimilaritySignal(),
            'attribute_match' => new AttributeMatchSignal(),
            'rating' => new RatingSignal($configReader),
            'availability' => new AvailabilitySignal(),
        ]);

        // Strong text/vector match, unrated (falls back to catalogue mean).
        $relevantUnrated = new SearchCandidate(
            1,
            'SKU-1',
            self::STORE_ID,
            'Name',
            '',
            ['shoes'],
            [],
            true,
            4,
            9.0,
            1.0,
            0.0,
            0.0,
            0,
            3.5
        );

        // Weak text/vector match, exceptionally well-reviewed.
        $irrelevantHighlyRated = new SearchCandidate(
            2,
            'SKU-2',
            self::STORE_ID,
            'Name',
            '',
            [],
            [],
            true,
            4,
            0.0,
            0.0,
            0.0,
            5.0,
            1000,
            3.5
        );

        // Disabled candidate must still be zeroed out by AvailabilitySignal
        // regardless of a strong rating.
        $disabledButHighlyRated = new SearchCandidate(
            3,
            'SKU-3',
            self::STORE_ID,
            'Name',
            '',
            [],
            [],
            false,
            4,
            9.0,
            1.0,
            0.0,
            5.0,
            1000,
            3.5
        );

        $result = $pipeline->rank(
            new SearchContext(self::STORE_ID, 'query', false),
            [$relevantUnrated, $irrelevantHighlyRated, $disabledButHighlyRated]
        );

        $order = array_map(static fn (SearchCandidate $c) => $c->entityId, $result);

        // A well-matching product still generally outranks a well-rated but
        // irrelevant one at the default conservative weight.
        self::assertSame(1, $order[0]);
        // The disabled candidate is demoted to the bottom by AvailabilitySignal
        // no matter how well-rated it is — the rating signal never overrides it.
        self::assertSame(3, $order[array_key_last($order)]);
    }

    public function testMerchandisingBoostSignalRunsAlongsideTheFiveExistingSignalsWithoutBreakingThem(): void
    {
        // Wires all 6 real, production signal classes (not fakes) through
        // the real pipeline exactly as etc/di.xml registers them, proving
        // MerchandisingBoostSignal's addition doesn't disturb text
        // relevance, vector similarity, attribute match, rating, or
        // availability's own behavior. This is the guardrail test this
        // task's own requirement 5 asks for.
        $retrievalConfig = $this->createMock(RetrievalConfigInterface::class);
        $retrievalConfig->method('finalProducts')->willReturn(8);
        $retrievalConfig->method('ratingSignalWeight')->willReturn(0.1);

        $configReader = $this->createMock(ConfigurationReaderInterface::class);
        $configReader->method('readRetrieval')->with(self::STORE_ID)->willReturn($retrievalConfig);

        // Strong text/vector match, no boost.
        $relevantUnboosted = new SearchCandidate(1, 'SKU-1', self::STORE_ID, 'Name', '', ['shoes'], [], true, 4, 9.0, 1.0);

        // Zero text/vector/attribute relevance at all, but maximally
        // boosted — the exact "boosted-but-irrelevant" guardrail case.
        $irrelevantButMaximallyBoosted = new SearchCandidate(2, 'SKU-2', self::STORE_ID, 'Name', '', [], [], true, 4, 0.0, 0.0);

        // Disabled candidate, maximally boosted — AvailabilitySignal must
        // still demote it regardless of the boost.
        $disabledButBoosted = new SearchCandidate(3, 'SKU-3', self::STORE_ID, 'Name', '', [], [], false, 4, 0.0, 0.0);

        $boostReader = $this->createMock(ActiveBoostReaderInterface::class);
        $boostReader->method('forProductIds')->willReturn([
            2 => MerchandisingBoostRow::MAX_BOOST_WEIGHT,
            3 => MerchandisingBoostRow::MAX_BOOST_WEIGHT,
        ]);
        $pipeline = new RankingPipeline($configReader, [
            'text_relevance' => new TextRelevanceSignal(),
            'vector_similarity' => new VectorSimilaritySignal(),
            'attribute_match' => new AttributeMatchSignal(),
            'rating' => new RatingSignal($configReader),
            'merchandising_boost' => new MerchandisingBoostSignal($boostReader),
            'availability' => new AvailabilitySignal(),
        ]);

        $result = $pipeline->rank(
            new SearchContext(self::STORE_ID, 'query', false),
            [$relevantUnboosted, $irrelevantButMaximallyBoosted, $disabledButBoosted]
        );

        $order = array_map(static fn (SearchCandidate $c) => $c->entityId, $result);

        // The genuinely relevant, unboosted product still outranks the
        // maximally-boosted-but-irrelevant one — a boost nudges, it does
        // not override real relevance.
        self::assertSame(1, $order[0]);
        // The disabled-but-boosted candidate is still demoted to the
        // bottom by AvailabilitySignal, which remains the pipeline's last,
        // authoritative gate regardless of any boost.
        self::assertSame(3, $order[array_key_last($order)]);
    }

    public function testRejectsANonStringSignalKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pipeline([0 => $this->fakeSignal(static fn (array $c) => $c)]);
    }

    public function testRejectsAnItemThatDoesNotImplementRankingSignalInterface(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RankingPipeline($this->createMock(ConfigurationReaderInterface::class), ['bad' => new \stdClass()]);
    }

    private function fakeSignal(\Closure $apply): RankingSignalInterface
    {
        return new class ($apply) implements RankingSignalInterface {
            public function __construct(private readonly \Closure $apply)
            {
            }

            public function apply(SearchContext $context, array $candidates): array
            {
                return ($this->apply)($candidates);
            }
        };
    }

    /**
     * @param array<string, RankingSignalInterface> $signals
     */
    private function pipeline(array $signals, int $finalProducts = 8): RankingPipeline
    {
        $retrievalConfig = $this->createMock(RetrievalConfigInterface::class);
        $retrievalConfig->method('finalProducts')->willReturn($finalProducts);

        $configReader = $this->createMock(ConfigurationReaderInterface::class);
        $configReader->method('readRetrieval')->with(self::STORE_ID)->willReturn($retrievalConfig);

        return new RankingPipeline($configReader, $signals);
    }
}
