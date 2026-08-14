<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexIncrementalSchedulerUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\UnavailableIncrementalProductIndexScheduler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnavailableIncrementalProductIndexScheduler::class)]
final class UnavailableIncrementalProductIndexSchedulerTest extends TestCase
{
    private UnavailableIncrementalProductIndexScheduler $scheduler;

    protected function setUp(): void
    {
        $this->scheduler = new UnavailableIncrementalProductIndexScheduler();
    }

    public function testScheduleValidIdRefusesExplicitly(): void
    {
        $this->expectException(ProductIndexIncrementalSchedulerUnavailableException::class);
        $this->scheduler->schedule(42);
    }

    public function testScheduleRejectsZero(): void
    {
        $this->expectException(InvalidProductIndexEntityIdsException::class);
        $this->scheduler->schedule(0);
    }

    public function testScheduleRejectsNegativeId(): void
    {
        $this->expectException(InvalidProductIndexEntityIdsException::class);
        $this->scheduler->schedule(-1);
    }

    public function testScheduleManyValidIdsRefusesExplicitly(): void
    {
        $this->expectException(ProductIndexIncrementalSchedulerUnavailableException::class);
        $this->scheduler->scheduleMany([3, 1, 2]);
    }

    public function testScheduleManyAcceptsDuplicatesBeforeRefusing(): void
    {
        $this->expectException(ProductIndexIncrementalSchedulerUnavailableException::class);
        $this->scheduler->scheduleMany([5, 5, 5]);
    }

    public function testScheduleManyRejectsInvalidElement(): void
    {
        $this->expectException(InvalidProductIndexEntityIdsException::class);
        $this->scheduler->scheduleMany([1, 'two', 3]);
    }

    public function testScheduleManyRejectsZeroElement(): void
    {
        $this->expectException(InvalidProductIndexEntityIdsException::class);
        $this->scheduler->scheduleMany([1, 0]);
    }

    public function testScheduleManyRejectsEmptyList(): void
    {
        $this->expectException(ProductIndexIncrementalSchedulerUnavailableException::class);
        $this->scheduler->scheduleMany([]);
    }
}
