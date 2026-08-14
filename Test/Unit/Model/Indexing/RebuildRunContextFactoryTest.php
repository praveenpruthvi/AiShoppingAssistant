<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductDocumentSchema;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexRunInitException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildRunContext;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildRunContextFactory;
use Aavirbhava\AiShoppingAssistant\Model\Store\StoreScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RebuildRunContextFactory::class)]
final class RebuildRunContextFactoryTest extends TestCase
{
    public function testCreatesRunContextWithServerGeneratedUuid(): void
    {
        $factory = new RebuildRunContextFactory();
        $scope = new StoreScope(3, 1, 'default');

        $context = $factory->create([$scope]);

        self::assertInstanceOf(RebuildRunContextInterface::class, $context);
        self::assertSame(ProductDocumentSchema::VERSION, $context->schemaVersion());
        self::assertSame([$scope], $context->enabledScopes());
        self::assertSame(1, preg_match(RebuildRunContext::RUN_ID_PATTERN, $context->runId()));
        self::assertGreaterThan(0.0, $context->startedAt());
    }

    public function testDeduplicatesAndSortsScopesByStoreId(): void
    {
        $factory = new RebuildRunContextFactory();
        $a = new StoreScope(5, 1, 'default');
        $b = new StoreScope(2, 1, 'default');
        $duplicate = new StoreScope(2, 1, 'default');

        $context = $factory->create([$a, $b, $duplicate]);
        $scopes = $context->enabledScopes();

        self::assertCount(2, $scopes);
        self::assertSame([2, 5], [$scopes[0]->storeId(), $scopes[1]->storeId()]);
    }

    public function testRejectsNonScopeEntry(): void
    {
        $this->expectException(ProductIndexRunInitException::class);
        (new RebuildRunContextFactory())->create(['nope']);
    }

    public function testRejectsEmptyScopeList(): void
    {
        $this->expectException(ProductIndexRunInitException::class);
        (new RebuildRunContextFactory())->create([]);
    }
}
