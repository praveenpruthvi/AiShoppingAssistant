<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat\Fallback;

use Aavirbhava\AiShoppingAssistant\Api\Chat\CircuitBreakerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Fallback\CacheCircuitBreaker;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheCircuitBreaker::class)]
final class CacheCircuitBreakerTest extends TestCase
{
    private const STORE_ID = 3;
    private const NOW = 1_000_000;

    /**
     * @var array<string, string>
     */
    private array $store = [];

    private function breaker(): CacheCircuitBreaker
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            fn (string $id) => $this->store[$id] ?? false
        );
        $cache->method('save')->willReturnCallback(
            function (string $data, string $id) {
                $this->store[$id] = $data;
                return true;
            }
        );
        $cache->method('remove')->willReturnCallback(
            function (string $id) {
                unset($this->store[$id]);
                return true;
            }
        );

        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn((new \DateTimeImmutable())->setTimestamp(self::NOW));

        return new CacheCircuitBreaker($cache, $clock);
    }

    public function testIsOpenIsFalseWithNoRecordedFailures(): void
    {
        $breaker = $this->breaker();

        self::assertFalse($breaker->isOpen(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY));
    }

    public function testStaysClosedBelowTheFailureThreshold(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 3, 60);
        $breaker->recordFailure(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 3, 60);

        self::assertFalse($breaker->isOpen(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY));
    }

    public function testOpensAtTheFailureThreshold(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 2, 60);
        $breaker->recordFailure(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 2, 60);

        self::assertTrue($breaker->isOpen(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY));
    }

    public function testRecordSuccessClosesTheBreakerAndResetsTheCount(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 2, 60);
        $breaker->recordFailure(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 2, 60);
        self::assertTrue($breaker->isOpen(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY));

        $breaker->recordSuccess(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY);

        self::assertFalse($breaker->isOpen(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY));

        // Confirms the count was reset, not just the open flag: two more
        // failures are needed to reopen it.
        $breaker->recordFailure(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 2, 60);
        self::assertFalse($breaker->isOpen(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY));
    }

    public function testPrimaryAndFallbackRolesAreTrackedIndependently(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 1, 60);

        self::assertTrue($breaker->isOpen(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY));
        self::assertFalse($breaker->isOpen(self::STORE_ID, CircuitBreakerInterface::ROLE_FALLBACK));
    }

    public function testDifferentStoresAreTrackedIndependently(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 1, 60);

        self::assertTrue($breaker->isOpen(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY));
        self::assertFalse($breaker->isOpen(self::STORE_ID + 1, CircuitBreakerInterface::ROLE_PRIMARY));
    }
}
