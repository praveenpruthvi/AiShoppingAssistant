<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Fake;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductDocumentWriterInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;

/**
 * In-memory writer that records the full run lifecycle for tests.
 *
 * Lives under Test/ only and is never registered in production DI.
 */
final class FakeProductDocumentWriter implements ProductDocumentWriterInterface
{
    public bool $begun = false;

    public bool $activated = false;

    public int $abortCount = 0;

    /**
     * @var list<StoreScopeInterface>
     */
    public array $preparedStores = [];

    /**
     * @var list<array{scope: StoreScopeInterface, documents: list<ProductDocumentInterface>}>
     */
    public array $writtenBatches = [];

    public ?RebuildRunContextInterface $lastContext = null;

    /**
     * @var list<array{method: string, throwable: \Throwable}>
     */
    private array $failures = [];

    public function beginRun(RebuildRunContextInterface $context): void
    {
        $this->assertNoFailure('beginRun');
        $this->begun = true;
        $this->lastContext = $context;
    }

    public function beginStore(StoreScopeInterface $scope): void
    {
        $this->assertNoFailure('beginStore');
        $this->preparedStores[] = $scope;
    }

    public function writeBatch(array $documents): void
    {
        $this->assertNoFailure('writeBatch');
        $this->writtenBatches[] = [
            'scope' => $this->currentScope(),
            'documents' => $documents,
        ];
    }

    public function finishStore(): void
    {
        $this->assertNoFailure('finishStore');
    }

    public function activateRun(): void
    {
        $this->assertNoFailure('activateRun');
        $this->activated = true;
    }

    public function abortRun(): void
    {
        $this->abortCount++;
        $this->assertNoFailure('abortRun');
    }

    /**
     * Makes the writer fail when a method is called. Use for failure-path tests.
     */
    public function failOn(string $method, \Throwable $throwable): void
    {
        $this->failures[] = ['method' => $method, 'throwable' => $throwable];
    }

    private function assertNoFailure(string $method): void
    {
        foreach ($this->failures as $failure) {
            if ($failure['method'] === $method) {
                throw $failure['throwable'];
            }
        }
    }

    private function currentScope(): ?StoreScopeInterface
    {
        $count = count($this->preparedStores);
        return $count > 0 ? $this->preparedStores[$count - 1] : null;
    }
}
