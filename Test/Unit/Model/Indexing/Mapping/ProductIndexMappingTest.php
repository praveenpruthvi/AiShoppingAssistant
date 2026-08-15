<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Mapping;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexNameInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Mapping\ProductIndexMapping;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildRunContext;
use Aavirbhava\AiShoppingAssistant\Model\Store\StoreScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductIndexMapping::class)]
final class ProductIndexMappingTest extends TestCase
{
    private const RUN_ID = '9f6f0c80-5d3b-4b2a-8e7c-1a2b3c4d5e6f';

    private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const BASE_URL_HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private ProductIndexMapping $mapping;

    protected function setUp(): void
    {
        $this->mapping = new ProductIndexMapping();
    }

    public function testCreatesStrictStoreScopedBody(): void
    {
        $scope = new StoreScope(2, 1, 'default');
        $context = new RebuildRunContext(self::RUN_ID, 1, [$scope], 1.0);

        $body = $this->mapping->createBody(
            $scope,
            $context,
            768,
            self::FINGERPRINT,
            self::BASE_URL_HASH,
            'prefix_store_2_run_token'
        );

        self::assertFalse($body['mappings']['dynamic']);
        self::assertSame(1, $body['mappings']['_meta']['schema_version']);
        self::assertSame(ProductIndexMappingInterface::MAPPING_VERSION, $body['mappings']['_meta']['mapping_version']);
        self::assertSame(2, $body['mappings']['_meta']['store_id']);
        self::assertSame(1, $body['mappings']['_meta']['website_id']);
        self::assertSame(self::RUN_ID, $body['mappings']['_meta']['run_id']);
        self::assertSame('prefix_store_2_run_token', $body['mappings']['_meta']['physical_index']);
        self::assertSame(self::FINGERPRINT, $body['mappings']['_meta']['embedding_fingerprint']);
        self::assertSame(768, $body['mappings']['_meta']['embedding_dimensions']);
        self::assertSame(self::BASE_URL_HASH, $body['mappings']['_meta']['embedding_base_url_hash']);
        self::assertArrayNotHasKey('provider', $body['mappings']['_meta']);
        self::assertArrayNotHasKey('base_url', $body['mappings']['_meta']);
        self::assertArrayNotHasKey('api_key', $body['mappings']['_meta']);
    }

    public function testVectorFieldUsesStoreDimensions(): void
    {
        $scope = new StoreScope(2, 1, 'default');
        $context = new RebuildRunContext(self::RUN_ID, 1, [$scope], 1.0);

        $body = $this->mapping->createBody($scope, $context, 1536, self::FINGERPRINT, self::BASE_URL_HASH, 'index');

        self::assertSame('knn_vector', $body['mappings']['properties'][ProductIndexMappingInterface::FIELD_EMBEDDING]['type']);
        self::assertSame(1536, $body['mappings']['properties'][ProductIndexMappingInterface::FIELD_EMBEDDING]['dimension']);
    }

    public function testRejectsInvalidDimensions(): void
    {
        $scope = new StoreScope(2, 1, 'default');
        $context = new RebuildRunContext(self::RUN_ID, 1, [$scope], 1.0);

        $this->expectException(IndexCompatibilityMismatchException::class);
        $this->mapping->createBody($scope, $context, 0, self::FINGERPRINT, self::BASE_URL_HASH, 'index');
    }

    public function testRejectsNonHexFingerprint(): void
    {
        $scope = new StoreScope(2, 1, 'default');
        $context = new RebuildRunContext(self::RUN_ID, 1, [$scope], 1.0);

        $this->expectException(ProductIndexNameInvalidException::class);
        $this->mapping->createBody($scope, $context, 768, 'not-a-hash', self::BASE_URL_HASH, 'index');
    }

    public function testRejectsNonHexBaseUrlHash(): void
    {
        $scope = new StoreScope(2, 1, 'default');
        $context = new RebuildRunContext(self::RUN_ID, 1, [$scope], 1.0);

        $this->expectException(ProductIndexNameInvalidException::class);
        $this->mapping->createBody($scope, $context, 768, self::FINGERPRINT, 'not-a-hash', 'index');
    }
}