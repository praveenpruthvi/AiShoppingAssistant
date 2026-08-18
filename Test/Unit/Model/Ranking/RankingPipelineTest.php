<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Ranking;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\RetrievalConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalCollectorInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalInterface;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\RankingPipeline;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
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
