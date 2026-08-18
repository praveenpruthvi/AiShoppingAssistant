<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal\AvailabilitySignal;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use Magento\Catalog\Model\Product\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AvailabilitySignal::class)]
final class AvailabilitySignalTest extends TestCase
{
    private function candidate(bool $isEnabled, int $visibility, float $score): SearchCandidate
    {
        return (new SearchCandidate(1, 'SKU-1', 1, 'Name', '', [], [], $isEnabled, $visibility, 0.0, 0.0))
            ->withScore($score);
    }

    public function testAvailableCandidateScoreIsUnchanged(): void
    {
        $signal = new AvailabilitySignal();
        $context = new SearchContext(1, 'query', false);

        [$result] = $signal->apply($context, [
            $this->candidate(true, Visibility::VISIBILITY_BOTH, 3.5),
        ]);

        self::assertSame(3.5, $result->score);
    }

    public function testInSearchVisibilityIsAlsoAvailable(): void
    {
        $signal = new AvailabilitySignal();
        $context = new SearchContext(1, 'query', false);

        [$result] = $signal->apply($context, [
            $this->candidate(true, Visibility::VISIBILITY_IN_SEARCH, 2.0),
        ]);

        self::assertSame(2.0, $result->score);
    }

    public function testDisabledCandidateScoreIsZeroedRegardlessOfUpstreamScore(): void
    {
        $signal = new AvailabilitySignal();
        $context = new SearchContext(1, 'query', false);

        [$result] = $signal->apply($context, [
            $this->candidate(false, Visibility::VISIBILITY_BOTH, 9.9),
        ]);

        self::assertSame(0.0, $result->score);
    }

    public function testNotSearchVisibleCandidateScoreIsZeroed(): void
    {
        $signal = new AvailabilitySignal();
        $context = new SearchContext(1, 'query', false);

        [$result] = $signal->apply($context, [
            $this->candidate(true, Visibility::VISIBILITY_NOT_VISIBLE, 5.0),
        ]);

        self::assertSame(0.0, $result->score);
    }

    public function testCatalogOnlyVisibilityIsZeroed(): void
    {
        $signal = new AvailabilitySignal();
        $context = new SearchContext(1, 'query', false);

        [$result] = $signal->apply($context, [
            $this->candidate(true, Visibility::VISIBILITY_IN_CATALOG, 5.0),
        ]);

        self::assertSame(0.0, $result->score);
    }
}
