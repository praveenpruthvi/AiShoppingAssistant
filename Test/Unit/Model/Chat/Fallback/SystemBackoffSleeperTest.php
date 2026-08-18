<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat\Fallback;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Fallback\SystemBackoffSleeper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemBackoffSleeper::class)]
final class SystemBackoffSleeperTest extends TestCase
{
    public function testSleepsForAtLeastTheRequestedDuration(): void
    {
        $sleeper = new SystemBackoffSleeper();

        $started = microtime(true);
        $sleeper->sleepMilliseconds(20);
        $elapsedMs = (microtime(true) - $started) * 1000;

        self::assertGreaterThanOrEqual(15, $elapsedMs);
    }

    public function testNegativeMillisecondsDoesNotErrorOrSleep(): void
    {
        $sleeper = new SystemBackoffSleeper();

        $started = microtime(true);
        $sleeper->sleepMilliseconds(-100);
        $elapsedMs = (microtime(true) - $started) * 1000;

        self::assertLessThan(50, $elapsedMs);
    }
}
