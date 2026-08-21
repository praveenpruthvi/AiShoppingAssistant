<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\ActiveBoostReaderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\MerchandisingBoostRow;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal\MerchandisingBoostSignal;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MerchandisingBoostSignal::class)]
final class MerchandisingBoostSignalTest extends TestCase
{
    private const STORE_ID = 1;

    public function testAddsTheBoostWeightToTheRunningScore(): void
    {
        $reader = $this->reader([1 => 0.4]);
        $signal = new MerchandisingBoostSignal($reader);

        $result = $signal->apply($this->context(), [$this->candidate(1, score: 0.5)]);

        self::assertSame(0.9, $result[0]->score);
    }

    public function testACandidateWithNoActiveBoostIsLeftUntouched(): void
    {
        $reader = $this->reader([1 => 0.4]);
        $signal = new MerchandisingBoostSignal($reader);

        $result = $signal->apply($this->context(), [$this->candidate(2, score: 0.5)]);

        self::assertSame(0.5, $result[0]->score);
    }

    public function testQueriesOnlyTheEntityIdsOfTheCurrentCandidateSet(): void
    {
        $reader = $this->createMock(ActiveBoostReaderInterface::class);
        $reader->expects(self::once())
            ->method('forProductIds')
            ->with([1, 2])
            ->willReturn([]);

        $signal = new MerchandisingBoostSignal($reader);

        $signal->apply($this->context(), [$this->candidate(1), $this->candidate(2)]);
    }

    public function testEmptyCandidateListNeverQueries(): void
    {
        $reader = $this->createMock(ActiveBoostReaderInterface::class);
        $reader->expects(self::never())->method('forProductIds');

        $signal = new MerchandisingBoostSignal($reader);

        self::assertSame([], $signal->apply($this->context(), []));
    }

    public function testBoostContributionIsCappedAtTheMaximumEvenIfTheReaderReturnsMore(): void
    {
        // Defensive clamp — MerchandisingBoostRow's own constructor already
        // rejects a weight above MAX_BOOST_WEIGHT at save time, but the
        // signal itself must not blindly trust whatever the reader returns.
        $reader = $this->reader([1 => MerchandisingBoostRow::MAX_BOOST_WEIGHT + 5.0]);
        $signal = new MerchandisingBoostSignal($reader);

        $result = $signal->apply($this->context(), [$this->candidate(1, score: 0.0)]);

        self::assertSame(MerchandisingBoostRow::MAX_BOOST_WEIGHT, $result[0]->score);
    }

    public function testGuardrailABoostedButIrrelevantCandidateDoesNotOutrankAGenuinelyRelevantOne(): void
    {
        $reader = $this->reader([1 => MerchandisingBoostRow::MAX_BOOST_WEIGHT]);
        $signal = new MerchandisingBoostSignal($reader);

        // Candidate 1: maximally boosted, but zero relevance score coming in
        // (simulating "irrelevant to this query"). Candidate 2: strong
        // relevance-signal score already, no boost at all.
        $irrelevantButBoosted = $this->candidate(1, score: 0.0);
        $relevantUnboosted = $this->candidate(2, score: 1.7);

        $result = $signal->apply($this->context(), [$irrelevantButBoosted, $relevantUnboosted]);

        $scoreById = [];
        foreach ($result as $candidate) {
            $scoreById[$candidate->entityId] = $candidate->score;
        }

        self::assertLessThan($scoreById[2], $scoreById[1]);
    }

    /**
     * @param array<int, float> $boosts
     */
    private function reader(array $boosts): ActiveBoostReaderInterface
    {
        $reader = $this->createMock(ActiveBoostReaderInterface::class);
        $reader->method('forProductIds')->willReturn($boosts);

        return $reader;
    }

    private function candidate(int $entityId, float $score = 0.0): SearchCandidate
    {
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
            $score
        );
    }

    private function context(): SearchContext
    {
        return new SearchContext(self::STORE_ID, 'query', false);
    }
}
