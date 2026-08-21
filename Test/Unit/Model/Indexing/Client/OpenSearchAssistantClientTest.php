<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Client;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\StoragePayloadInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Client\OpenSearchAssistantClient;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Client\OpenSearchClientBuilderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Client\OpenSearchClientFactory;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Client\OpenSearchClientFactoryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Document\StoragePayload;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\AliasActivationFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\BulkIndexFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\BulkResponseInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexDocumentStateInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchCapabilityUnsupportedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchConfigurationInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexCreateFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\SearchQueryFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\SearchResponseInvalidException;
use Magento\Elasticsearch\Model\Config;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\Missing404Exception;
use OpenSearch\Namespaces\IndicesNamespace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenSearchAssistantClient::class)]
final class OpenSearchAssistantClientTest extends TestCase
{
    private const INDEX = 'prefix_store_2_run_token';

    private const SECRET_HOST = 'https://secret-search.example.internal';

    private const SECRET_USER = 'super-secret-user';

    private const SECRET_PASS = 'super-secret-pass';

    /**
     * @var Config&\PHPUnit\Framework\MockObject\MockObject
     */
    private $elasticsearchConfig;

    /**
     * @var OpenSearchClientFactoryInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $factory;

    /**
     * @var Client&\PHPUnit\Framework\MockObject\MockObject
     */
    private $opensearch;

    /**
     * @var IndicesNamespace&\PHPUnit\Framework\MockObject\MockObject
     */
    private $indices;

    private OpenSearchAssistantClient $client;

    protected function setUp(): void
    {
        $this->elasticsearchConfig = $this->createMock(Config::class);
        $this->elasticsearchConfig->method('prepareClientOptions')->willReturn([
            'hostname' => self::SECRET_HOST,
            'port' => 9200,
            'index' => 'magento2',
            'enableAuth' => 1,
            'username' => self::SECRET_USER,
            'password' => self::SECRET_PASS,
            'timeout' => 15,
        ]);

        $this->opensearch = $this->createMock(Client::class);
        $this->indices = $this->createMock(IndicesNamespace::class);
        $this->opensearch->method('indices')->willReturn($this->indices);

        $this->factory = $this->createMock(OpenSearchClientFactoryInterface::class);
        $this->factory->method('create')->willReturn($this->opensearch);

        $this->client = new OpenSearchAssistantClient($this->elasticsearchConfig, $this->factory);
    }

    private function payload(string $id = '2_42'): StoragePayloadInterface
    {
        return new StoragePayload($id, ['document_id' => $id, 'name' => 'Test']);
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function bulkResponse(array $ids, array $overrides = []): array
    {
        $items = [];
        foreach ($ids as $id) {
            $items[] = ['index' => ['_index' => self::INDEX, '_id' => $id, 'status' => 201]];
        }

        return array_merge(['errors' => false, 'items' => $items], $overrides);
    }

    private static function assertSanitized(\Throwable $throwable): void
    {
        self::assertNull($throwable->getPrevious());
        self::assertStringNotContainsString(self::SECRET_HOST, $throwable->getMessage());
        self::assertStringNotContainsString(self::SECRET_USER, $throwable->getMessage());
        self::assertStringNotContainsString(self::SECRET_PASS, $throwable->getMessage());
    }

    public function testBuildsClientLazilyAndCaches(): void
    {
        $this->factory->expects(self::once())->method('create');

        $this->client->ping();
        $this->client->ping();
    }

    public function testWritesBulkMetadataAndSourceSeparately(): void
    {
        $captured = [];
        $this->opensearch->method('bulk')->willReturnCallback(
            function (array $params) use (&$captured): array {
                $captured[] = $params;
                return $this->bulkResponse(['2_42']);
            }
        );

        $this->client->writeDocuments(self::INDEX, [$this->payload('2_42')]);

        $body = $captured[0]['body'];
        self::assertSame(['index' => ['_index' => self::INDEX, '_id' => '2_42']], $body[0]);
        self::assertSame(['document_id' => '2_42', 'name' => 'Test'], $body[1]);
        self::assertArrayNotHasKey('_id', $body[1]);
        self::assertArrayNotHasKey('_index', $body[1]);
    }

    public function testEmptyDocumentListIsNoOp(): void
    {
        $this->opensearch->expects(self::never())->method('bulk');

        $this->client->writeDocuments(self::INDEX, []);
    }

    public function testBulkRejectsDuplicateSubmittedIdsBeforeTransport(): void
    {
        $this->opensearch->expects(self::never())->method('bulk');

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload('2_42'), $this->payload('2_42')]);
    }

    public function testBulkRejectsEmptySubmittedIdBeforeTransport(): void
    {
        $payload = new class implements StoragePayloadInterface {
            public function id(): string
            {
                return '';
            }

            public function source(): array
            {
                return ['document_id' => ''];
            }
        };

        $this->opensearch->expects(self::never())->method('bulk');

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$payload]);
    }

    public function testBulkRejectsNonStoragePayloadInput(): void
    {
        $this->opensearch->expects(self::never())->method('bulk');

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [['document_id' => 'x']]);
    }

    public function testBulkAcceptsValidResponses(): void
    {
        $this->opensearch->method('bulk')->willReturn($this->bulkResponse(['2_42', '2_43']));

        $this->client->writeDocuments(self::INDEX, [$this->payload('2_42'), $this->payload('2_43')]);
        self::assertTrue(true);
    }

    public function testBulkRejectsNonArrayResponse(): void
    {
        $this->opensearch->method('bulk')->willReturn('nope');

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkRejectsMissingErrorsKey(): void
    {
        $this->opensearch->method('bulk')->willReturn(['items' => []]);

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkRejectsNonBoolErrors(): void
    {
        $this->opensearch->method('bulk')->willReturn($this->bulkResponse(['2_42'], ['errors' => 1]));

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkFailsWhenTopLevelErrorsTrue(): void
    {
        $this->opensearch->method('bulk')->willReturn($this->bulkResponse(['2_42'], ['errors' => true]));

        $this->expectException(BulkIndexFailedException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkRejectsMissingItemsKey(): void
    {
        $this->opensearch->method('bulk')->willReturn(['errors' => false]);

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkRejectsNonListItems(): void
    {
        $this->opensearch->method('bulk')->willReturn($this->bulkResponse(['2_42'], ['items' => ['x' => []]]));

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkRejectsItemCountMismatch(): void
    {
        $this->opensearch->method('bulk')->willReturn($this->bulkResponse(['2_42']));

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload('2_42'), $this->payload('2_43')]);
    }

    public function testBulkRejectsItemWithoutIndexKey(): void
    {
        $this->opensearch->method('bulk')->willReturn([
            'errors' => false,
            'items' => [['delete' => ['status' => 200]]],
        ]);

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkRejectsItemWithExtraKeys(): void
    {
        $response = $this->bulkResponse(['2_42']);
        $response['items'][0]['extra'] = true;

        $this->opensearch->method('bulk')->willReturn($response);

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkRejectsNonArrayIndexResult(): void
    {
        $this->opensearch->method('bulk')->willReturn(['errors' => false, 'items' => [['index' => 'oops']]]);

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkRejectsItemWithError(): void
    {
        $response = $this->bulkResponse(['2_42']);
        $response['items'][0]['index']['error'] = ['type' => 'mapper_parsing_exception'];

        $this->opensearch->method('bulk')->willReturn($response);

        $this->expectException(BulkIndexFailedException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkRejectsItemWhenErrorKeyExistsWithNullValue(): void
    {
        $response = $this->bulkResponse(['2_42']);
        $response['items'][0]['index']['error'] = null;

        $this->opensearch->method('bulk')->willReturn($response);

        $this->expectException(BulkIndexFailedException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkRejectsNon2xxStatus(): void
    {
        $response = $this->bulkResponse(['2_42']);
        $response['items'][0]['index']['status'] = 409;

        $this->opensearch->method('bulk')->willReturn($response);

        $this->expectException(BulkIndexFailedException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkRejectsMissingStatus(): void
    {
        $response = $this->bulkResponse(['2_42']);
        unset($response['items'][0]['index']['status']);

        $this->opensearch->method('bulk')->willReturn($response);

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkRejectsNonIntStatus(): void
    {
        $response = $this->bulkResponse(['2_42']);
        $response['items'][0]['index']['status'] = 'created';

        $this->opensearch->method('bulk')->willReturn($response);

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload()]);
    }

    public function testBulkRejectsReorderedOrWrongIds(): void
    {
        $this->opensearch->method('bulk')->willReturn($this->bulkResponse(['2_43', '2_42']));

        $this->expectException(BulkResponseInvalidException::class);
        $this->client->writeDocuments(self::INDEX, [$this->payload('2_42'), $this->payload('2_43')]);
    }

    public function testBulkTransportFailureIsSanitized(): void
    {
        $this->opensearch->method('bulk')->willThrowException(
            new \RuntimeException(self::SECRET_HOST . ' ' . self::SECRET_USER . ' ' . self::SECRET_PASS)
        );

        try {
            $this->client->writeDocuments(self::INDEX, [$this->payload()]);
            self::fail('Expected BulkIndexFailedException');
        } catch (BulkIndexFailedException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testBulkPreservesSanitizedClientExceptionInstance(): void
    {
        $expected = new BulkResponseInvalidException();
        $this->opensearch->method('bulk')->willThrowException($expected);

        try {
            $this->client->writeDocuments(self::INDEX, [$this->payload()]);
            self::fail('Expected BulkResponseInvalidException');
        } catch (BulkResponseInvalidException $exception) {
            self::assertSame($expected, $exception);
        }
    }

    public function testIndexMetaReturnsMetaWhenPresent(): void
    {
        $meta = ['assistant_index' => true, 'store_id' => 2, 'physical_index' => self::INDEX];
        $this->indices->method('getMapping')->willReturn([
            self::INDEX => ['mappings' => ['_meta' => $meta]],
        ]);

        self::assertSame($meta, $this->client->indexMeta(self::INDEX));
    }

    public function testIndexMetaRejectsMissingMeta(): void
    {
        $this->indices->method('getMapping')->willReturn([
            self::INDEX => ['mappings' => ['dynamic' => false]],
        ]);

        $this->expectException(OpenSearchBackendUnavailableException::class);
        $this->client->indexMeta(self::INDEX);
    }

    public function testIndexMetaRejectsUnexpectedIndexKey(): void
    {
        $this->indices->method('getMapping')->willReturn([
            'prefix_store_2_run_other' => ['mappings' => ['_meta' => ['assistant_index' => true]]],
        ]);

        $this->expectException(OpenSearchBackendUnavailableException::class);
        $this->client->indexMeta(self::INDEX);
    }

    public function testIndexMetaRejectsMultiIndexResponse(): void
    {
        $this->indices->method('getMapping')->willReturn([
            self::INDEX => ['mappings' => ['_meta' => ['assistant_index' => true]]],
            'prefix_store_2_run_other' => ['mappings' => ['_meta' => ['assistant_index' => true]]],
        ]);

        $this->expectException(OpenSearchBackendUnavailableException::class);
        $this->client->indexMeta(self::INDEX);
    }

    public function testIndexMetaRejectsMalformedMappings(): void
    {
        $this->indices->method('getMapping')->willReturn([
            self::INDEX => ['mappings' => 'invalid'],
        ]);

        $this->expectException(OpenSearchBackendUnavailableException::class);
        $this->client->indexMeta(self::INDEX);
    }

    public function testIndexMetaFailureIsSanitized(): void
    {
        $this->indices->method('getMapping')->willThrowException(new \RuntimeException(self::SECRET_HOST));

        try {
            $this->client->indexMeta(self::INDEX);
            self::fail('Expected OpenSearchBackendUnavailableException');
        } catch (OpenSearchBackendUnavailableException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testDocumentStateReturnsSanitizedState(): void
    {
        $source = [
            'document_id' => '2_42',
            'complete_document_hash' => str_repeat('a', 64),
            'embedding_content_hash' => str_repeat('b', 64),
            'embedding_fingerprint' => str_repeat('c', 64),
            'embedding' => [0.1, 0.2, 0.3, 0.4],
        ];
        $this->opensearch->method('get')->willReturn(['found' => true, '_id' => '2_42', '_source' => $source]);

        $state = $this->client->documentState(self::INDEX, '2_42');

        self::assertNotNull($state);
        self::assertSame('2_42', $state->documentId());
        self::assertSame(str_repeat('a', 64), $state->completeDocumentHash());
    }

    public function testDocumentStateRequestsOnlyStateFields(): void
    {
        $captured = null;
        $this->opensearch->method('get')->willReturnCallback(
            function (array $params) use (&$captured): array {
                $captured = $params;

                return ['found' => false];
            }
        );

        $this->client->documentState(self::INDEX, '2_42');

        self::assertSame(self::INDEX, $captured['index'] ?? null);
        self::assertSame('2_42', $captured['id'] ?? null);
        self::assertSame(
            ['document_id', 'complete_document_hash', 'embedding_content_hash', 'embedding_fingerprint', 'embedding'],
            $captured['_source_includes'] ?? null
        );
    }

    public function testDocumentStateReturnsNullWhenMissing(): void
    {
        $this->opensearch->method('get')->willReturn(['found' => false]);

        self::assertNull($this->client->documentState(self::INDEX, '2_42'));
    }

    public function testDocumentStateRejectsMissingFound(): void
    {
        $this->opensearch->method('get')->willReturn(['_id' => '2_42', '_source' => []]);

        $this->expectException(IndexDocumentStateInvalidException::class);
        $this->client->documentState(self::INDEX, '2_42');
    }

    public function testDocumentStateRejectsNonBooleanFound(): void
    {
        $this->opensearch->method('get')->willReturn(['found' => 'false']);

        $this->expectException(IndexDocumentStateInvalidException::class);
        $this->client->documentState(self::INDEX, '2_42');
    }

    public function testDocumentStateRejectsFoundTrueWithoutId(): void
    {
        $this->opensearch->method('get')->willReturn(['found' => true, '_source' => []]);

        $this->expectException(IndexDocumentStateInvalidException::class);
        $this->client->documentState(self::INDEX, '2_42');
    }

    public function testDocumentStateRejectsMismatchedId(): void
    {
        $this->opensearch->method('get')->willReturn(['found' => true, '_id' => '2_99', '_source' => []]);

        $this->expectException(IndexDocumentStateInvalidException::class);
        $this->client->documentState(self::INDEX, '2_42');
    }

    public function testDocumentStateRejectsMalformedFalseFoundResponse(): void
    {
        $this->opensearch->method('get')->willReturn(['found' => false, '_source' => []]);

        $this->expectException(IndexDocumentStateInvalidException::class);
        $this->client->documentState(self::INDEX, '2_42');
    }

    public function testDocumentStateRejectsFalseFoundWithMalformedId(): void
    {
        $this->opensearch->method('get')->willReturn(['found' => false, '_id' => 42]);

        $this->expectException(IndexDocumentStateInvalidException::class);
        $this->client->documentState(self::INDEX, '2_42');
    }

    public function testDocumentStateRejectsMalformedResponse(): void
    {
        $this->opensearch->method('get')->willReturn(['found' => true, '_id' => '2_42', '_source' => 'invalid']);

        $this->expectException(IndexDocumentStateInvalidException::class);
        $this->client->documentState(self::INDEX, '2_42');
    }

    public function testDocumentStateHandlesConcreteMissing404AsNotFound(): void
    {
        $this->opensearch->method('get')->willThrowException(new Missing404Exception('missing', 404));

        self::assertNull($this->client->documentState(self::INDEX, '2_42'));
    }

    public function testDocumentStateFailureIsSanitized(): void
    {
        $this->opensearch->method('get')->willThrowException(
            new \RuntimeException(self::SECRET_HOST . ' ' . self::SECRET_USER . ' ' . self::SECRET_PASS)
        );

        try {
            $this->client->documentState(self::INDEX, '2_42');
            self::fail('Expected OpenSearchBackendUnavailableException');
        } catch (OpenSearchBackendUnavailableException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testDocumentStateGenericRuntime404IsBackendUnavailable(): void
    {
        $this->opensearch->method('get')->willThrowException(
            new \RuntimeException(self::SECRET_HOST . ' sensitive 404', 404)
        );

        try {
            $this->client->documentState(self::INDEX, '2_42');
            self::fail('Expected OpenSearchBackendUnavailableException');
        } catch (OpenSearchBackendUnavailableException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testDeleteDocumentAcceptsNotFoundResult(): void
    {
        $this->opensearch->method('delete')->willReturn(['_id' => '2_42', 'result' => 'not_found']);

        $this->client->deleteDocument(self::INDEX, '2_42');
        self::assertTrue(true);
    }

    public function testDeleteDocumentHandlesConcreteMissing404AsSuccess(): void
    {
        $this->opensearch->method('delete')->willThrowException(new Missing404Exception('missing', 404));

        $this->client->deleteDocument(self::INDEX, '2_42');
        self::assertTrue(true);
    }

    public function testDeleteDocumentGenericRuntime404IsBackendUnavailable(): void
    {
        $this->opensearch->method('delete')->willThrowException(
            new \RuntimeException(self::SECRET_HOST . ' sensitive 404', 404)
        );

        try {
            $this->client->deleteDocument(self::INDEX, '2_42');
            self::fail('Expected OpenSearchBackendUnavailableException');
        } catch (OpenSearchBackendUnavailableException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testDeleteDocumentRejectsMissingId(): void
    {
        $this->opensearch->method('delete')->willReturn(['result' => 'deleted']);

        $this->expectException(IndexDocumentStateInvalidException::class);
        $this->client->deleteDocument(self::INDEX, '2_42');
    }

    public function testDeleteDocumentRejectsMismatchedId(): void
    {
        $this->opensearch->method('delete')->willReturn(['_id' => '2_99', 'result' => 'deleted']);

        $this->expectException(IndexDocumentStateInvalidException::class);
        $this->client->deleteDocument(self::INDEX, '2_42');
    }

    public function testDeleteDocumentRejectsMalformedResponse(): void
    {
        $this->opensearch->method('delete')->willReturn(['result' => 'noop']);

        $this->expectException(IndexDocumentStateInvalidException::class);
        $this->client->deleteDocument(self::INDEX, '2_42');
    }

    public function testWriteDocumentUsesValidatedBulkBoundary(): void
    {
        $this->opensearch->method('bulk')->willReturn($this->bulkResponse(['2_42']));

        $this->client->writeDocument(self::INDEX, $this->payload('2_42'));
        self::assertTrue(true);
    }

    public function testPingReturnsFalseOnFailure(): void
    {
        $this->opensearch->method('ping')->willThrowException(new \RuntimeException(self::SECRET_HOST));

        self::assertFalse($this->client->ping());
    }

    public function testDistributionFailureIsSanitized(): void
    {
        $this->opensearch->method('info')->willThrowException(new \RuntimeException(self::SECRET_HOST));

        try {
            $this->client->distribution();
            self::fail('Expected OpenSearchBackendUnavailableException');
        } catch (OpenSearchBackendUnavailableException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testDistributionRejectsNonArrayInfoResponse(): void
    {
        $this->opensearch->method('info')->willReturn('invalid');

        $this->expectException(OpenSearchCapabilityUnsupportedException::class);
        $this->client->distribution();
    }

    public function testDistributionRejectsMissingVersionObject(): void
    {
        $this->opensearch->method('info')->willReturn([]);

        $this->expectException(OpenSearchCapabilityUnsupportedException::class);
        $this->client->distribution();
    }

    public function testDistributionWithoutDistributionFieldIsUnsupported(): void
    {
        $this->opensearch->method('info')->willReturn(['version' => ['number' => '2.12.0']]);

        $this->expectException(OpenSearchCapabilityUnsupportedException::class);
        $this->client->distribution();
    }

    public function testDistributionRejectsNonScalarVersionNumber(): void
    {
        $this->opensearch->method('info')->willReturn([
            'version' => ['distribution' => 'opensearch', 'number' => []],
        ]);

        $this->expectException(OpenSearchCapabilityUnsupportedException::class);
        $this->client->distribution();
    }

    public function testCreateIndexFailureIsSanitized(): void
    {
        $this->indices->method('create')->willThrowException(new \RuntimeException(self::SECRET_HOST));

        try {
            $this->client->createIndex(self::INDEX, []);
            self::fail('Expected ProductIndexCreateFailedException');
        } catch (ProductIndexCreateFailedException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testRefreshFailureIsSanitized(): void
    {
        $this->indices->method('refresh')->willThrowException(new \RuntimeException(self::SECRET_HOST));

        try {
            $this->client->refresh(self::INDEX);
            self::fail('Expected OpenSearchBackendUnavailableException');
        } catch (OpenSearchBackendUnavailableException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testAliasTargetsReturnsIndexKeys(): void
    {
        $this->indices->method('existsAlias')->willReturn(true);
        $this->indices->method('getAlias')->willReturn([
            'prefix_store_2_run_old' => ['aliases' => ['a' => []]],
        ]);

        self::assertSame(['prefix_store_2_run_old'], $this->client->aliasTargets('alias'));
    }

    public function testAliasTargetsEmptyWhenAliasMissing(): void
    {
        $this->indices->method('existsAlias')->willReturn(false);
        $this->indices->expects(self::never())->method('getAlias');

        self::assertSame([], $this->client->aliasTargets('alias'));
    }

    public function testUpdateAliasesFailureIsSanitized(): void
    {
        $this->indices->method('updateAliases')->willThrowException(new \RuntimeException(self::SECRET_HOST));

        try {
            $this->client->updateAliases([['add' => ['alias' => 'a', 'index' => self::INDEX]]]);
            self::fail('Expected AliasActivationFailedException');
        } catch (AliasActivationFailedException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testDeleteIndexFailureIsSanitized(): void
    {
        $this->indices->method('delete')->willThrowException(new \RuntimeException(self::SECRET_HOST));

        try {
            $this->client->deleteIndex(self::INDEX);
            self::fail('Expected OpenSearchBackendUnavailableException');
        } catch (OpenSearchBackendUnavailableException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testListIndicesReturnsMatchedIndexNames(): void
    {
        $this->indices->method('get')->willReturn([
            'prefix_store_2_run_a' => ['settings' => []],
            'prefix_store_2_run_b' => ['settings' => []],
        ]);

        self::assertSame(
            ['prefix_store_2_run_a', 'prefix_store_2_run_b'],
            $this->client->listIndices('prefix_store_2_run_*')
        );
    }

    public function testListIndicesReturnsEmptyWhenNothingMatchesRatherThanFailing(): void
    {
        $this->indices->method('get')->willThrowException(new Missing404Exception('missing', 404));

        self::assertSame([], $this->client->listIndices('prefix_store_2_run_*'));
    }

    public function testListIndicesFailureIsSanitized(): void
    {
        $this->indices->method('get')->willThrowException(new \RuntimeException(self::SECRET_HOST));

        try {
            $this->client->listIndices('prefix_store_2_run_*');
            self::fail('Expected OpenSearchBackendUnavailableException');
        } catch (OpenSearchBackendUnavailableException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testIndexAliasesReturnsAliasNamesForExactIndex(): void
    {
        $this->indices->method('getAlias')->willReturn([
            self::INDEX => ['aliases' => ['prefix_store_2_current' => []]],
        ]);

        self::assertSame(['prefix_store_2_current'], $this->client->indexAliases(self::INDEX));
    }

    public function testIndexAliasesReturnsEmptyWhenIndexHasNoAliases(): void
    {
        $this->indices->method('getAlias')->willThrowException(new Missing404Exception('missing', 404));

        self::assertSame([], $this->client->indexAliases(self::INDEX));
    }

    public function testIndexAliasesRejectsMalformedResponse(): void
    {
        $this->indices->method('getAlias')->willReturn([self::INDEX => ['aliases' => 'invalid']]);

        $this->expectException(OpenSearchBackendUnavailableException::class);
        $this->client->indexAliases(self::INDEX);
    }

    public function testIndexAliasesFailureIsSanitized(): void
    {
        $this->indices->method('getAlias')->willThrowException(new \RuntimeException(self::SECRET_HOST));

        try {
            $this->client->indexAliases(self::INDEX);
            self::fail('Expected OpenSearchBackendUnavailableException');
        } catch (OpenSearchBackendUnavailableException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testIndexCreatedAtReturnsCreationDateAsInt(): void
    {
        $this->indices->method('getSettings')->willReturn([
            self::INDEX => ['settings' => ['index' => ['creation_date' => '1699999999123']]],
        ]);

        self::assertSame(1699999999123, $this->client->indexCreatedAt(self::INDEX));
    }

    public function testIndexCreatedAtRejectsMissingCreationDate(): void
    {
        $this->indices->method('getSettings')->willReturn([self::INDEX => ['settings' => ['index' => []]]]);

        $this->expectException(OpenSearchBackendUnavailableException::class);
        $this->client->indexCreatedAt(self::INDEX);
    }

    public function testIndexCreatedAtFailureIsSanitized(): void
    {
        $this->indices->method('getSettings')->willThrowException(new \RuntimeException(self::SECRET_HOST));

        try {
            $this->client->indexCreatedAt(self::INDEX);
            self::fail('Expected OpenSearchBackendUnavailableException');
        } catch (OpenSearchBackendUnavailableException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testClientBuildFailureIsSanitizedConfigurationError(): void
    {
        $this->factory->method('create')->willThrowException(
            new \RuntimeException(self::SECRET_HOST . ' ' . self::SECRET_PASS)
        );

        try {
            $this->client->distribution();
            self::fail('Expected OpenSearchConfigurationInvalidException');
        } catch (OpenSearchConfigurationInvalidException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testMalformedClientFactoryConfigurationFailsClosed(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('prepareClientOptions')->willReturn([
            'hostname' => self::SECRET_HOST . '?debug=1',
            'port' => 9200,
            'enableAuth' => 0,
            'timeout' => 15,
        ]);

        $builder = $this->createMock(OpenSearchClientBuilderInterface::class);
        $builder->expects(self::never())->method('fromConfig');

        $client = new OpenSearchAssistantClient($config, new OpenSearchClientFactory($builder));

        try {
            $client->distribution();
            self::fail('Expected OpenSearchConfigurationInvalidException');
        } catch (OpenSearchConfigurationInvalidException $exception) {
            self::assertSanitized($exception);
        }
    }

    public function testSearchReturnsVerifiedHits(): void
    {
        $this->opensearch->method('search')->willReturn([
            'hits' => [
                'hits' => [
                    ['_id' => '2_42', '_score' => 1.5, '_source' => ['name' => 'Test']],
                    ['_id' => '2_43', '_score' => 0.9, '_source' => ['name' => 'Other']],
                ],
            ],
        ]);

        $hits = $this->client->search(self::INDEX, ['query' => ['match_all' => []]]);

        self::assertSame(
            [
                ['_id' => '2_42', '_score' => 1.5, '_source' => ['name' => 'Test']],
                ['_id' => '2_43', '_score' => 0.9, '_source' => ['name' => 'Other']],
            ],
            $hits
        );
    }

    public function testSearchPassesIndexAndBody(): void
    {
        $captured = [];
        $this->opensearch->method('search')->willReturnCallback(
            function (array $params) use (&$captured): array {
                $captured[] = $params;
                return ['hits' => ['hits' => []]];
            }
        );

        $this->client->search(self::INDEX, ['query' => ['match_all' => []]]);

        self::assertSame(self::INDEX, $captured[0]['index']);
        self::assertSame(['query' => ['match_all' => []]], $captured[0]['body']);
    }

    public function testSearchReturnsEmptyListWhenNoHits(): void
    {
        $this->opensearch->method('search')->willReturn(['hits' => ['hits' => []]]);

        self::assertSame([], $this->client->search(self::INDEX, []));
    }

    public function testSearchRejectsMissingHitsKey(): void
    {
        $this->opensearch->method('search')->willReturn(['took' => 1]);

        $this->expectException(SearchResponseInvalidException::class);
        $this->client->search(self::INDEX, []);
    }

    public function testSearchRejectsNonListHits(): void
    {
        $this->opensearch->method('search')->willReturn(['hits' => ['hits' => 'not-a-list']]);

        $this->expectException(SearchResponseInvalidException::class);
        $this->client->search(self::INDEX, []);
    }

    public function testSearchRejectsHitMissingId(): void
    {
        $this->opensearch->method('search')->willReturn([
            'hits' => ['hits' => [['_score' => 1.0, '_source' => []]]],
        ]);

        $this->expectException(SearchResponseInvalidException::class);
        $this->client->search(self::INDEX, []);
    }

    public function testSearchRejectsHitWithNonNumericScore(): void
    {
        $this->opensearch->method('search')->willReturn([
            'hits' => ['hits' => [['_id' => '2_42', '_score' => 'high', '_source' => []]]],
        ]);

        $this->expectException(SearchResponseInvalidException::class);
        $this->client->search(self::INDEX, []);
    }

    public function testSearchRejectsHitWithNonArraySource(): void
    {
        $this->opensearch->method('search')->willReturn([
            'hits' => ['hits' => [['_id' => '2_42', '_score' => 1.0, '_source' => 'not-an-array']]],
        ]);

        $this->expectException(SearchResponseInvalidException::class);
        $this->client->search(self::INDEX, []);
    }

    public function testSearchTransportFailureIsSanitized(): void
    {
        $this->opensearch->method('search')->willThrowException(
            new \RuntimeException(self::SECRET_HOST . ' ' . self::SECRET_USER . ' ' . self::SECRET_PASS)
        );

        try {
            $this->client->search(self::INDEX, []);
            self::fail('Expected SearchQueryFailedException');
        } catch (SearchQueryFailedException $exception) {
            self::assertSanitized($exception);
        }
    }
}
