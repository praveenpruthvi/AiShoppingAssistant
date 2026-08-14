<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Dto;

use Aavirbhava\AiShoppingAssistant\Model\Dto\EmbeddingBatch;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmbeddingBatch::class)]
final class EmbeddingBatchTest extends TestCase
{
    public function testAcceptsVectorsMatchingDeclaredDimensions(): void
    {
        $batch = new EmbeddingBatch([[0.1, 0.2], [0.3, 0.4]], 2, 'local', 'test-model');

        self::assertSame(2, $batch->count());
    }

    public function testRejectsMismatchedVectorDimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EmbeddingBatch([[0.1]], 2, 'local', 'test-model');
    }
}
