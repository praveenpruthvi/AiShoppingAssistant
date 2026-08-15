<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Document;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexedProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingVector;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Document\IndexedProductDocument;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeProductDocumentFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndexedProductDocument::class)]
final class IndexedProductDocumentTest extends TestCase
{
    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const FINGERPRINT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testExposesEnrichedDocument(): void
    {
        $document = (new FakeProductDocumentFactory())->make();
        $vector = new EmbeddingVector([0.1, 0.2, 0.3], 3);

        $indexed = new IndexedProductDocument($document, $vector, self::HASH, self::FINGERPRINT, '2026-01-01T00:00:00+00:00');

        self::assertInstanceOf(IndexedProductDocumentInterface::class, $indexed);
        self::assertSame($document, $indexed->document());
        self::assertSame($vector, $indexed->embedding());
        self::assertSame(self::HASH, $indexed->embeddingHash());
        self::assertSame(self::FINGERPRINT, $indexed->embeddingFingerprint());
        self::assertSame('2026-01-01T00:00:00+00:00', $indexed->indexedAt());
    }

    public function testRejectsInvalidEmbeddingHash(): void
    {
        $document = (new FakeProductDocumentFactory())->make();
        $vector = new EmbeddingVector([0.1, 0.2, 0.3], 3);

        $this->expectException(IndexCompatibilityMismatchException::class);
        new IndexedProductDocument($document, $vector, 'not-a-hash', self::FINGERPRINT, '2026-01-01T00:00:00+00:00');
    }

    public function testRejectsInvalidEmbeddingFingerprint(): void
    {
        $document = (new FakeProductDocumentFactory())->make();
        $vector = new EmbeddingVector([0.1, 0.2, 0.3], 3);

        $this->expectException(IndexCompatibilityMismatchException::class);
        new IndexedProductDocument($document, $vector, self::HASH, 'not-a-fingerprint', '2026-01-01T00:00:00+00:00');
    }
}