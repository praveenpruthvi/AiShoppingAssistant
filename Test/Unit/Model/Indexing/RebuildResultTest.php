<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildMetricsInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildMetrics;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RebuildResult::class)]
final class RebuildResultTest extends TestCase
{
    private function metrics(): RebuildMetricsInterface
    {
        return new RebuildMetrics(1, 0, 1, 1, 1, 0, 1, [], 1, true, 1.0);
    }

    public function testActivatedOutcome(): void
    {
        $metrics = $this->metrics();
        $result = new RebuildResult($metrics, RebuildResultInterface::OUTCOME_ACTIVATED);

        self::assertSame('activated', $result->outcome());
        self::assertTrue($result->activated());
        self::assertFalse($result->noOp());
        self::assertFalse($result->aborted());
        self::assertSame($metrics, $result->metrics());
    }

    public function testNoOpOutcome(): void
    {
        $result = new RebuildResult($this->metrics(), RebuildResultInterface::OUTCOME_NO_OP);

        self::assertTrue($result->noOp());
        self::assertFalse($result->activated());
    }

    public function testAbortedOutcome(): void
    {
        $result = new RebuildResult($this->metrics(), RebuildResultInterface::OUTCOME_ABORTED);

        self::assertTrue($result->aborted());
        self::assertFalse($result->activated());
        self::assertFalse($result->noOp());
    }

    public function testRejectsUnknownOutcome(): void
    {
        $this->expectException(ProductIndexingException::class);
        new RebuildResult($this->metrics(), 'exploded');
    }
}
