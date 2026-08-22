<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\ActiveBoostReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Merchandising\ActiveCategoryBoostReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Merchandising\ProductCategoryMembershipReaderInterface;
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

    public function testProductBoostAloneIsAddedToTheRunningScore(): void
    {
        $signal = $this->signal(productBoosts: [1 => 0.4], memberships: [], categoryBoosts: []);

        $result = $signal->apply($this->context(), [$this->candidate(1, score: 0.5)]);

        self::assertSame(0.9, $result[0]->score);
    }

    public function testCategoryBoostAloneIsAddedToTheRunningScore(): void
    {
        // Candidate 1's product has no boost of its own, but belongs to
        // category 100, which has an active boost of 0.3.
        $signal = $this->signal(productBoosts: [], memberships: [1 => [100]], categoryBoosts: [100 => 0.3]);

        $result = $signal->apply($this->context(), [$this->candidate(1, score: 0.5)]);

        self::assertSame(0.8, $result[0]->score);
    }

    public function testProductAndCategoryBoostsCombineAdditivelyAndAreCappedTogetherNotSeparately(): void
    {
        // 0.7 (product) + 0.6 (category) = 1.3 uncapped — must be capped
        // to MAX_BOOST_WEIGHT ONCE on the combined total, not once per
        // source (which would have let each individually-under-the-cap
        // contribution through unclamped, adding 1.3 to the score).
        $signal = $this->signal(
            productBoosts: [1 => 0.7],
            memberships: [1 => [100]],
            categoryBoosts: [100 => 0.6]
        );

        $result = $signal->apply($this->context(), [$this->candidate(1, score: 0.0)]);

        self::assertSame(MerchandisingBoostRow::MAX_BOOST_WEIGHT, $result[0]->score);
    }

    public function testProductAndCategoryBoostsBelowTheCapSumExactly(): void
    {
        $signal = $this->signal(
            productBoosts: [1 => 0.3],
            memberships: [1 => [100]],
            categoryBoosts: [100 => 0.2]
        );

        $result = $signal->apply($this->context(), [$this->candidate(1, score: 0.1)]);

        self::assertEqualsWithDelta(0.6, $result[0]->score, 0.0001);
    }

    public function testMaxNotSumIsUsedAcrossAProductsMultipleBoostedCategories(): void
    {
        // Candidate 1's product belongs to THREE boosted categories
        // (0.2, 0.5, 0.3) — only the STRONGEST (0.5) must apply, never
        // their sum (1.0).
        $signal = $this->signal(
            productBoosts: [],
            memberships: [1 => [100, 200, 300]],
            categoryBoosts: [100 => 0.2, 200 => 0.5, 300 => 0.3]
        );

        $result = $signal->apply($this->context(), [$this->candidate(1, score: 0.0)]);

        self::assertSame(0.5, $result[0]->score);
    }

    public function testACandidateWithNoBoostOfEitherKindIsLeftUntouched(): void
    {
        $signal = $this->signal(productBoosts: [1 => 0.4], memberships: [], categoryBoosts: []);

        $result = $signal->apply($this->context(), [$this->candidate(2, score: 0.5)]);

        self::assertSame(0.5, $result[0]->score);
    }

    /**
     * A category boost the reader never returns (already expired, not
     * yet started, or is_active=0 — ActiveCategoryBoostReader's own job,
     * proven directly in ActiveCategoryBoostReaderTest/the integration
     * test) simply never appears in what forCategoryIds() returns, so
     * the signal correctly contributes nothing for it — this proves the
     * signal itself correctly treats "reader didn't return this
     * category id" as "no boost," not a crash or a default value.
     */
    public function testACategoryThatIsAMemberButHasNoActiveBoostContributesNothing(): void
    {
        $signal = $this->signal(
            productBoosts: [],
            memberships: [1 => [100]],
            categoryBoosts: [] // 100 has no active boost right now
        );

        $result = $signal->apply($this->context(), [$this->candidate(1, score: 0.5)]);

        self::assertSame(0.5, $result[0]->score);
    }

    public function testQueriesProductBoostsOnlyForTheCurrentCandidateSetsEntityIds(): void
    {
        $productReader = $this->createMock(ActiveBoostReaderInterface::class);
        $productReader->expects(self::once())->method('forProductIds')->with([1, 2])->willReturn([]);

        $membershipReader = $this->createMock(ProductCategoryMembershipReaderInterface::class);
        $membershipReader->method('forProductIds')->willReturn([]);

        $categoryReader = $this->createMock(ActiveCategoryBoostReaderInterface::class);
        $categoryReader->expects(self::never())->method('forCategoryIds');

        $signal = new MerchandisingBoostSignal($productReader, $categoryReader, $membershipReader);

        $signal->apply($this->context(), [$this->candidate(1), $this->candidate(2)]);
    }

    public function testQueriesCategoryBoostsOnlyForCategoriesTheCurrentCandidatesActuallyBelongTo(): void
    {
        $productReader = $this->createMock(ActiveBoostReaderInterface::class);
        $productReader->method('forProductIds')->willReturn([]);

        $membershipReader = $this->createMock(ProductCategoryMembershipReaderInterface::class);
        $membershipReader->method('forProductIds')->with([1, 2])->willReturn([1 => [100, 200], 2 => [200]]);

        $categoryReader = $this->createMock(ActiveCategoryBoostReaderInterface::class);
        $categoryReader->expects(self::once())->method('forCategoryIds')->with([100, 200])->willReturn([]);

        $signal = new MerchandisingBoostSignal($productReader, $categoryReader, $membershipReader);

        $signal->apply($this->context(), [$this->candidate(1), $this->candidate(2)]);
    }

    public function testEmptyCandidateListNeverQueriesAnyReader(): void
    {
        $productReader = $this->createMock(ActiveBoostReaderInterface::class);
        $productReader->expects(self::never())->method('forProductIds');

        $membershipReader = $this->createMock(ProductCategoryMembershipReaderInterface::class);
        $membershipReader->expects(self::never())->method('forProductIds');

        $categoryReader = $this->createMock(ActiveCategoryBoostReaderInterface::class);
        $categoryReader->expects(self::never())->method('forCategoryIds');

        $signal = new MerchandisingBoostSignal($productReader, $categoryReader, $membershipReader);

        self::assertSame([], $signal->apply($this->context(), []));
    }

    public function testCombinedBoostContributionIsCappedEvenIfBothReadersMisbehave(): void
    {
        // Defensive clamp on the COMBINED total — mirrors Task 32's own
        // "the signal itself must not blindly trust whatever the reader
        // returns" defensive test, now for the combined case.
        $signal = $this->signal(
            productBoosts: [1 => MerchandisingBoostRow::MAX_BOOST_WEIGHT + 5.0],
            memberships: [1 => [100]],
            categoryBoosts: [100 => MerchandisingBoostRow::MAX_BOOST_WEIGHT + 5.0]
        );

        $result = $signal->apply($this->context(), [$this->candidate(1, score: 0.0)]);

        self::assertSame(MerchandisingBoostRow::MAX_BOOST_WEIGHT, $result[0]->score);
    }

    public function testGuardrailAProductBoostedButIrrelevantCandidateDoesNotOutrankAGenuinelyRelevantOne(): void
    {
        $signal = $this->signal(
            productBoosts: [1 => MerchandisingBoostRow::MAX_BOOST_WEIGHT],
            memberships: [],
            categoryBoosts: []
        );

        $irrelevantButBoosted = $this->candidate(1, score: 0.0);
        $relevantUnboosted = $this->candidate(2, score: 1.7);

        $result = $signal->apply($this->context(), [$irrelevantButBoosted, $relevantUnboosted]);

        $scoreById = $this->scoreById($result);
        self::assertLessThan($scoreById[2], $scoreById[1]);
    }

    /**
     * The same guardrail as above, now proven for a CATEGORY boost
     * specifically (Task 33's own explicit requirement) — a product
     * that's irrelevant to the query but happens to sit in a maximally
     * boosted category must still not outrank a genuinely relevant,
     * unboosted product.
     */
    public function testGuardrailACategoryBoostedButIrrelevantCandidateDoesNotOutrankAGenuinelyRelevantOne(): void
    {
        $signal = $this->signal(
            productBoosts: [],
            memberships: [1 => [100]],
            categoryBoosts: [100 => MerchandisingBoostRow::MAX_BOOST_WEIGHT]
        );

        $irrelevantButCategoryBoosted = $this->candidate(1, score: 0.0);
        $relevantUnboosted = $this->candidate(2, score: 1.7);

        $result = $signal->apply($this->context(), [$irrelevantButCategoryBoosted, $relevantUnboosted]);

        $scoreById = $this->scoreById($result);
        self::assertLessThan($scoreById[2], $scoreById[1]);
    }

    /**
     * @param array<int, float> $productBoosts
     * @param array<int, list<int>> $memberships
     * @param array<int, float> $categoryBoosts
     */
    private function signal(array $productBoosts, array $memberships, array $categoryBoosts): MerchandisingBoostSignal
    {
        $productReader = $this->createMock(ActiveBoostReaderInterface::class);
        $productReader->method('forProductIds')->willReturn($productBoosts);

        $membershipReader = $this->createMock(ProductCategoryMembershipReaderInterface::class);
        $membershipReader->method('forProductIds')->willReturn($memberships);

        $categoryReader = $this->createMock(ActiveCategoryBoostReaderInterface::class);
        $categoryReader->method('forCategoryIds')->willReturn($categoryBoosts);

        return new MerchandisingBoostSignal($productReader, $categoryReader, $membershipReader);
    }

    /**
     * @param list<SearchCandidate> $candidates
     *
     * @return array<int, float>
     */
    private function scoreById(array $candidates): array
    {
        $scores = [];
        foreach ($candidates as $candidate) {
            $scores[$candidate->entityId] = $candidate->score;
        }

        return $scores;
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
