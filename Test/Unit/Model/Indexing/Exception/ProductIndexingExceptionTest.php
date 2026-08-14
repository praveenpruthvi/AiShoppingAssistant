<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexAbortException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexActivationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBatchNormalizationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBatchWriteException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexIncrementalSchedulerUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexRunInitException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexStorePrepException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductIndexingException::class)]
final class ProductIndexingExceptionTest extends TestCase
{
    /**
     * @return array<string, array{class: class-string, code: string}>
     */
    public static function exceptionCodeProvider(): array
    {
        return [
            'backend unavailable' => [ProductIndexBackendUnavailableException::class, 'backend_unavailable'],
            'invalid entity ids' => [InvalidProductIndexEntityIdsException::class, 'invalid_entity_ids'],
            'run init' => [ProductIndexRunInitException::class, 'run_init_failed'],
            'store prep' => [ProductIndexStorePrepException::class, 'store_prep_failed'],
            'batch normalization' => [ProductIndexBatchNormalizationException::class, 'batch_normalization_failed'],
            'batch write' => [ProductIndexBatchWriteException::class, 'batch_write_failed'],
            'activation' => [ProductIndexActivationException::class, 'activation_failed'],
            'abort' => [ProductIndexAbortException::class, 'abort_failed'],
            'incremental unavailable' => [ProductIndexIncrementalSchedulerUnavailableException::class, 'incremental_scheduler_unavailable'],
        ];
    }

    /**
     * @param class-string $class
     *
     * @dataProvider exceptionCodeProvider
     */
    public function testErrorCodesAreStable(string $class, string $code): void
    {
        $exception = new $class();
        self::assertInstanceOf(ProductIndexingException::class, $exception);
        self::assertSame($code, $exception->errorCode());
    }

    public function testRootCanCarryCustomErrorCodeAndPhrase(): void
    {
        $exception = new ProductIndexingException(
            ProductIndexingException::ERROR_INVALID_METRICS,
            new Phrase('The AI shopping assistant reindex metrics are invalid.'),
            null
        );

        self::assertSame('invalid_metrics', $exception->errorCode());
    }

    public function testPreservesPreviousException(): void
    {
        $previous = new \RuntimeException('inner detail');
        $exception = new ProductIndexRunInitException($previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    public function testCarriesAbortedRebuildResult(): void
    {
        $result = $this->createMock(RebuildResultInterface::class);
        $exception = new ProductIndexActivationException(null, $result);

        self::assertSame($result, $exception->rebuildResult());
    }

    public function testSanitizedMessageContainsNoInternalDetail(): void
    {
        $exception = new ProductIndexBackendUnavailableException(new \RuntimeException('connection refused to 10.0.0.1:9200'));

        self::assertStringNotContainsString('10.0.0.1', $exception->getMessage());
        self::assertStringNotContainsString('9200', $exception->getMessage());
    }
}
