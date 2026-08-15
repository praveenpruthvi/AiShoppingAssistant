<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Naming;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexNamingServiceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexNameInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Naming\IndexNamingService;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildRunContext;
use Aavirbhava\AiShoppingAssistant\Model\Store\StoreScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndexNamingService::class)]
final class IndexNamingServiceTest extends TestCase
{
    private const RUN_ID = '9f6f0c80-5d3b-4b2a-8e7c-1a2b3c4d5e6f';

    private IndexNamingService $service;

    protected function setUp(): void
    {
        $this->service = new IndexNamingService();
    }

    public function testReadAliasBuildsStoreScopedName(): void
    {
        $scope = new StoreScope(2, 1, 'default');

        self::assertSame(
            'aavirbhava_ai_product_rag_store_2_current',
            $this->service->readAlias('aavirbhava_ai_product_rag', $scope)
        );
    }

    public function testPhysicalIndexBuildsImmutableRunName(): void
    {
        $scope = new StoreScope(2, 1, 'default');
        $context = new RebuildRunContext(self::RUN_ID, 1, [$scope], 1.0);

        self::assertSame(
            'aavirbhava_ai_product_rag_store_2_run_9f6f0c805d3b4b2a8e7c1a2b3c4d5e6f',
            $this->service->physicalIndex('aavirbhava_ai_product_rag', $scope, $context)
        );
    }

    public function testRunTokenIsLowercaseAlphanumericTrimmed(): void
    {
        $scope = new StoreScope(2, 1, 'default');
        $context = new RebuildRunContext(self::RUN_ID, 1, [$scope], 1.0);

        $index = $this->service->physicalIndex('prefix', $scope, $context);

        self::assertMatchesRegularExpression('/^prefix_store_2_run_[a-z0-9]{1,32}$/', $index);
        $token = substr($index, strlen('prefix_store_2_run_'));
        self::assertSame(32, strlen($token));
        self::assertSame($token, strtolower($token));
    }

    public function testRejectsInvalidPrefix(): void
    {
        $scope = new StoreScope(2, 1, 'default');

        $this->expectException(ProductIndexNameInvalidException::class);
        $this->service->readAlias('Invalid-Prefix!', $scope);
    }

    public function testRejectsInvalidPrefixOnPhysicalIndex(): void
    {
        $scope = new StoreScope(2, 1, 'default');
        $context = new RebuildRunContext(self::RUN_ID, 1, [$scope], 1.0);

        $this->expectException(ProductIndexNameInvalidException::class);
        $this->service->physicalIndex('UPPER', $scope, $context);
    }

    public function testIsPrefixValid(): void
    {
        self::assertTrue($this->service->isPrefixValid('aavirbhava_ai_product_rag'));
        self::assertTrue($this->service->isPrefixValid('a'));
        self::assertFalse($this->service->isPrefixValid(''));
        self::assertFalse($this->service->isPrefixValid('Invalid'));
        self::assertFalse($this->service->isPrefixValid('1prefix'));
    }

    public function testIsAssistantOwnedIndexIsRunShaped(): void
    {
        $prefix = 'aavirbhava_ai';

        self::assertTrue($this->service->isAssistantOwnedIndex($prefix, 'aavirbhava_ai_store_2_run_abc'));
        self::assertFalse($this->service->isAssistantOwnedIndex($prefix, 'aavirbhava_ai_store_2_current'));
        self::assertFalse($this->service->isAssistantOwnedIndex($prefix, 'magento_product_2_default'));
        self::assertFalse($this->service->isAssistantOwnedIndex($prefix, ''));
        self::assertFalse($this->service->isAssistantOwnedIndex('Invalid!', 'aavirbhava_ai_store_2_run_abc'));
    }

    public function testParseAssistantIndexReturnsStoreAndRunToken(): void
    {
        $parsed = $this->service->parseAssistantIndex('aavirbhava_ai', 'aavirbhava_ai_store_2_run_abc123');

        self::assertNotNull($parsed);
        self::assertSame(2, $parsed['store_id']);
        self::assertSame('abc123', $parsed['run_token']);
    }

    public function testParseAssistantIndexRejectsNonRunShapedNames(): void
    {
        $prefix = 'aavirbhava_ai';

        self::assertNull($this->service->parseAssistantIndex($prefix, 'aavirbhava_ai_store_2_current'));
        self::assertNull($this->service->parseAssistantIndex($prefix, 'aavirbhava_ai_store_2'));
        self::assertNull($this->service->parseAssistantIndex($prefix, 'aavirbhava_ai_store_x_run_abc'));
        self::assertNull($this->service->parseAssistantIndex($prefix, 'aavirbhava_ai_store_2_run_ABC'));
        self::assertNull($this->service->parseAssistantIndex($prefix, 'aavirbhava_ai_store_2_run_'));
        self::assertNull($this->service->parseAssistantIndex($prefix, 'magento_product_2_run_abc'));
        self::assertNull($this->service->parseAssistantIndex('Invalid!', 'aavirbhava_ai_store_2_run_abc'));
    }
}