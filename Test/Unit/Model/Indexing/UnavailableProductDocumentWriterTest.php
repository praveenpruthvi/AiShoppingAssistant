<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildRunContext;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\UnavailableProductDocumentWriter;
use Aavirbhava\AiShoppingAssistant\Model\Store\StoreScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnavailableProductDocumentWriter::class)]
final class UnavailableProductDocumentWriterTest extends TestCase
{
    private const RUN_ID = '9f6f0c80-5d3b-4b2a-8e7c-1a2b3c4d5e6f';

    private UnavailableProductDocumentWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new UnavailableProductDocumentWriter();
    }

    public function testBeginRunThrowsBackendUnavailable(): void
    {
        $this->expectException(ProductIndexBackendUnavailableException::class);
        $this->writer->beginRun(
            new RebuildRunContext(self::RUN_ID, 1, [new StoreScope(2, 1, 'default')], 1.0)
        );
    }

    public function testBeginStoreThrowsBackendUnavailable(): void
    {
        $this->expectException(ProductIndexBackendUnavailableException::class);
        $this->writer->beginStore(new StoreScope(2, 1, 'default'));
    }

    public function testWriteBatchThrowsBackendUnavailable(): void
    {
        $this->expectException(ProductIndexBackendUnavailableException::class);
        $this->writer->writeBatch([]);
    }

    public function testFinishStoreThrowsBackendUnavailable(): void
    {
        $this->expectException(ProductIndexBackendUnavailableException::class);
        $this->writer->finishStore();
    }

    public function testActivateRunThrowsBackendUnavailable(): void
    {
        $this->expectException(ProductIndexBackendUnavailableException::class);
        $this->writer->activateRun();
    }

    public function testAbortRunIsSafeAndIdempotent(): void
    {
        $this->writer->abortRun();
        $this->writer->abortRun();
        self::assertTrue(true);
    }
}
