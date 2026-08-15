<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalIndexTargetInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalFailureDispositionPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IncrementalFailureDispositionPolicy::class)]
final class IncrementalFailureDispositionPolicyTest extends TestCase
{
    public function testOnlyAllowlistedTransientFailureRetries(): void
    {
        $disposition = (new IncrementalFailureDispositionPolicy())->classify(
            new OpenSearchBackendUnavailableException(),
            0
        );

        self::assertTrue($disposition->retryable());
        self::assertSame('opensearch_backend_unavailable', $disposition->errorCode());
        self::assertSame(60, $disposition->delaySeconds());
    }

    public function testSafetyAndConfigurationFailuresAreTerminal(): void
    {
        $disposition = (new IncrementalFailureDispositionPolicy())->classify(
            new IncrementalIndexTargetInvalidException(),
            0
        );

        self::assertFalse($disposition->retryable());
        self::assertSame('incremental_target_invalid', $disposition->errorCode());
        self::assertSame(0, $disposition->delaySeconds());
    }

    public function testUnknownFailureIsTerminalAndSanitized(): void
    {
        $disposition = (new IncrementalFailureDispositionPolicy())->classify(
            new \RuntimeException('secret backend text'),
            0
        );

        self::assertFalse($disposition->retryable());
        self::assertSame('unknown', $disposition->errorCode());
    }

    public function testMaximumAttemptsStopRetrying(): void
    {
        $disposition = (new IncrementalFailureDispositionPolicy())->classify(
            new OpenSearchBackendUnavailableException(),
            IncrementalFailureDispositionPolicy::MAX_ATTEMPTS - 1
        );

        self::assertFalse($disposition->retryable());
    }

    public function testBackoffIsBounded(): void
    {
        $disposition = (new IncrementalFailureDispositionPolicy())->classify(
            new OpenSearchBackendUnavailableException(),
            3
        );

        self::assertTrue($disposition->retryable());
        self::assertLessThanOrEqual(
            IncrementalFailureDispositionPolicy::MAX_DELAY_SECONDS,
            $disposition->delaySeconds()
        );
    }
}
