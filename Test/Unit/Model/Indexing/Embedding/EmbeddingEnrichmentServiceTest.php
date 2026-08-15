<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\EmbeddingConfigSnapshotServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\FrozenEmbeddingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ContentHashService;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Embedding\EmbeddingEnrichmentService;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Embedding\FrozenEmbeddingConfig;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\EmbeddingEnrichmentException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeEmbeddingGenerationService;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeProductDocumentFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmbeddingEnrichmentService::class)]
final class EmbeddingEnrichmentServiceTest extends TestCase
{
    private const FINGERPRINT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const BASE_URL_HASH = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private FakeEmbeddingGenerationService $generation;

    /**
     * @var EmbeddingConfigSnapshotServiceInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $snapshot;

    private EmbeddingEnrichmentService $service;

    /**
     * Drives the mocked matches() result; the first call returns this, and when
     * $flipAfterFirstCall is true later calls return false.
     */
    private bool $matchesResult = true;

    private bool $flipAfterFirstCall = false;

    private int $matchesCalls = 0;

    protected function setUp(): void
    {
        $this->generation = new FakeEmbeddingGenerationService();
        $this->snapshot = $this->createMock(EmbeddingConfigSnapshotServiceInterface::class);
        $this->snapshot->method('matches')->willReturnCallback(function (): bool {
            $this->matchesCalls++;
            if ($this->flipAfterFirstCall && $this->matchesCalls > 1) {
                return false;
            }

            return $this->matchesResult;
        });
        $this->service = new EmbeddingEnrichmentService($this->generation, new ContentHashService(), $this->snapshot);
    }

    private function config(): FrozenEmbeddingConfigInterface
    {
        return new FrozenEmbeddingConfig(
            1,
            'openai',
            'text-embedding-3-small',
            'https://api.example.com',
            4,
            self::FINGERPRINT,
            self::BASE_URL_HASH
        );
    }

    public function testEnrichesDocumentsInOrder(): void
    {
        $factory = new FakeProductDocumentFactory();
        $documents = [
            $factory->make(1, 1, 'SKU-1'),
            $factory->make(1, 2, 'SKU-2'),
            $factory->make(1, 3, 'SKU-3'),
        ];

        $indexed = $this->service->enrich($this->config(), $documents);

        self::assertCount(3, $indexed);
        self::assertSame('SKU-1', $indexed[0]->document()->sku());
        self::assertSame('SKU-2', $indexed[1]->document()->sku());
        self::assertSame('SKU-3', $indexed[2]->document()->sku());
        self::assertSame(self::FINGERPRINT, $indexed[0]->embeddingFingerprint());
        self::assertTrue($this->generation->calls[0]['inputType']->isDocument());
    }

    public function testUsesDocumentInputType(): void
    {
        $this->service->enrich($this->config(), [(new FakeProductDocumentFactory())->make()]);

        self::assertTrue($this->generation->lastInputWasDocument);
        self::assertCount(1, $this->generation->calls);
        self::assertSame(1, $this->generation->calls[0]['storeId']);
    }

    public function testReturnsEmptyForEmptyDocuments(): void
    {
        self::assertSame([], $this->service->enrich($this->config(), []));
        self::assertSame([], $this->generation->calls);
    }

    public function testHashCorrelatesWithVectorValues(): void
    {
        $document = (new FakeProductDocumentFactory())->make(1, 42, 'SKU-42');
        $indexed = $this->service->enrich($this->config(), [$document]);

        $expected = (new ContentHashService())->hash($this->generation->vectorFor('Test Product Shoes blue'));

        self::assertSame($expected, $indexed[0]->embeddingHash());
    }

    public function testChunksLargeBatchesWithinLimit(): void
    {
        $factory = new FakeProductDocumentFactory();
        $documents = [];
        for ($i = 0; $i < 110; $i++) {
            $documents[] = $factory->make(1, $i + 1, 'SKU-' . $i);
        }

        $indexed = $this->service->enrich($this->config(), $documents);

        self::assertCount(110, $indexed);
        self::assertCount(3, $this->generation->calls);
        self::assertLessThanOrEqual(EmbeddingEnrichmentService::MAX_DOCUMENTS_PER_BATCH, count($this->generation->calls[0]['texts']));
        self::assertSame(10, count($this->generation->calls[2]['texts']));
    }

    public function testFailsClosedWhenVectorCountMismatches(): void
    {
        $this->generation->failOn(1);

        $this->expectException(EmbeddingEnrichmentException::class);
        $this->service->enrich($this->config(), [(new FakeProductDocumentFactory())->make()]);
    }

    public function testFailsWhenConfigChangesBeforeProviderCall(): void
    {
        $this->matchesResult = false;

        $this->expectException(IndexCompatibilityMismatchException::class);
        $this->service->enrich($this->config(), [(new FakeProductDocumentFactory())->make()]);
        self::assertSame([], $this->generation->calls);
    }

    public function testFailsWhenConfigChangesAfterProviderCall(): void
    {
        $this->flipAfterFirstCall = true;

        $this->expectException(IndexCompatibilityMismatchException::class);
        $this->service->enrich($this->config(), [(new FakeProductDocumentFactory())->make()]);
        self::assertCount(1, $this->generation->calls);
    }
}