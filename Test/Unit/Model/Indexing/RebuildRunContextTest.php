<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexRunInitException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildRunContext;
use Aavirbhava\AiShoppingAssistant\Model\Store\StoreScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RebuildRunContext::class)]
final class RebuildRunContextTest extends TestCase
{
    private const RUN_ID = '9f6f0c80-5d3b-4b2a-8e7c-1a2b3c4d5e6f';

    public function testExposesRunValues(): void
    {
        $scope = new StoreScope(2, 1, 'default');
        $context = new RebuildRunContext(self::RUN_ID, 1, [$scope], 1723400000.5);

        self::assertInstanceOf(RebuildRunContextInterface::class, $context);
        self::assertSame(self::RUN_ID, $context->runId());
        self::assertSame(1, $context->schemaVersion());
        self::assertSame([$scope], $context->enabledScopes());
        self::assertSame(1723400000.5, $context->startedAt());
    }

    public function testRejectsInvalidRunId(): void
    {
        $this->expectException(ProductIndexRunInitException::class);
        new RebuildRunContext('not-a-uuid', 1, [new StoreScope(2, 1, 'default')], 1.0);
    }

    public function testRejectsNonV4RunId(): void
    {
        $this->expectException(ProductIndexRunInitException::class);
        new RebuildRunContext('9f6f0c80-5d3b-5b2a-8e7c-1a2b3c4d5e6f', 1, [new StoreScope(2, 1, 'default')], 1.0);
    }

    public function testRejectsZeroSchemaVersion(): void
    {
        $this->expectException(ProductIndexRunInitException::class);
        new RebuildRunContext(self::RUN_ID, 0, [new StoreScope(2, 1, 'default')], 1.0);
    }

    public function testRejectsEmptyScopeList(): void
    {
        $this->expectException(ProductIndexRunInitException::class);
        new RebuildRunContext(self::RUN_ID, 1, [], 1.0);
    }

    public function testRejectsInvalidScopeEntry(): void
    {
        $this->expectException(ProductIndexRunInitException::class);
        new RebuildRunContext(self::RUN_ID, 1, ['not-a-scope'], 1.0);
    }

    public function testRejectsNegativeStartTime(): void
    {
        $this->expectException(ProductIndexRunInitException::class);
        new RebuildRunContext(self::RUN_ID, 1, [new StoreScope(2, 1, 'default')], -1.0);
    }
}
