<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\AliasActivationFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\BulkIndexFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\BulkResponseInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\EmbeddingEnrichmentException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalIndexTargetInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexDocumentStateInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexRunStateInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexScopeMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchCapabilityUnsupportedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchConfigurationInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexAbortException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexAbortFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexActivationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBatchNormalizationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBatchWriteException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexCreateFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexIncrementalSchedulerUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexMappingInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexNameInvalidException;
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
            'incremental target invalid' => [IncrementalIndexTargetInvalidException::class, 'incremental_target_invalid'],
            'index document state invalid' => [IndexDocumentStateInvalidException::class, 'index_document_state_invalid'],
            'opensearch backend unavailable' => [OpenSearchBackendUnavailableException::class, 'opensearch_backend_unavailable'],
            'opensearch configuration invalid' => [OpenSearchConfigurationInvalidException::class, 'opensearch_configuration_invalid'],
            'opensearch capability unsupported' => [OpenSearchCapabilityUnsupportedException::class, 'opensearch_capability_unsupported'],
            'index name invalid' => [ProductIndexNameInvalidException::class, 'index_name_invalid'],
            'index create failed' => [ProductIndexCreateFailedException::class, 'index_create_failed'],
            'index mapping invalid' => [ProductIndexMappingInvalidException::class, 'index_mapping_invalid'],
            'embedding enrichment' => [EmbeddingEnrichmentException::class, 'embedding_enrichment_failed'],
            'bulk index' => [BulkIndexFailedException::class, 'bulk_index_failed'],
            'bulk response invalid' => [BulkResponseInvalidException::class, 'bulk_response_invalid'],
            'alias activation' => [AliasActivationFailedException::class, 'alias_activation_failed'],
            'index abort' => [ProductIndexAbortFailedException::class, 'index_abort_failed'],
            'index run state invalid' => [IndexRunStateInvalidException::class, 'index_run_state_invalid'],
            'index scope mismatch' => [IndexScopeMismatchException::class, 'index_scope_mismatch'],
            'index compatibility mismatch' => [IndexCompatibilityMismatchException::class, 'index_compatibility_mismatch'],
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
