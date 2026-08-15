<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIncrementalIndexerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalProductIndexConsumer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(IncrementalProductIndexConsumer::class)]
final class IncrementalProductIndexConsumerTest extends TestCase
{
    /**
     * @var ProductIncrementalIndexerInterface&MockObject
     */
    private $indexer;

    protected function setUp(): void
    {
        $this->indexer = $this->createMock(ProductIncrementalIndexerInterface::class);
    }

    private function consumer(): IncrementalProductIndexConsumer
    {
        return new IncrementalProductIndexConsumer($this->indexer);
    }

    public function testProcessCallsIncrementalIndexerExactlyOnce(): void
    {
        $this->indexer->expects(self::once())
            ->method('process')
            ->with(42);

        $this->consumer()->process('42');
    }

    /**
     * @dataProvider malformedPayloadProvider
     */
    public function testMalformedPayloadFailsClosed(mixed $payload): void
    {
        $this->indexer->expects(self::never())->method('process');

        $this->expectException(InvalidProductIndexEntityIdsException::class);
        $this->consumer()->process($payload);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedPayloadProvider(): array
    {
        return [
            'empty string' => [''],
            'zero' => ['0'],
            'negative' => ['-1'],
            'non numeric' => ['abc'],
            'fractional' => ['1.2'],
            'array' => [[42]],
        ];
    }

    public function testIndexingExceptionPropagatesFromHandler(): void
    {
        $expected = new ProductIndexBackendUnavailableException();
        $this->indexer->expects(self::once())
            ->method('process')
            ->with(42)
            ->willThrowException($expected);

        try {
            $this->consumer()->process('42');
            self::fail('Expected indexing exception');
        } catch (ProductIndexBackendUnavailableException $exception) {
            self::assertSame($expected, $exception);
        }
    }

    public function testConstructionDoesNotIndex(): void
    {
        $this->indexer->expects(self::never())->method('process');

        $this->consumer();
    }
}
