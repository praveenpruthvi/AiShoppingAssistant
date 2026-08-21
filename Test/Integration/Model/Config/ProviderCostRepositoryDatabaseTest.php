<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Config;

use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Config\ProviderCostRepository;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ProviderCostRepository against the real database — the
 * insertOnDuplicate upsert semantics aren't meaningfully re-verifiable
 * against a mocked adapter, the same rationale as every other
 * ResourceConnection-direct repository in this module
 * (AttributeIndexingSelectionRepositoryDatabaseTest, DbCostUsageTrackerDatabaseTest).
 *
 * Uses real, but clearly test-namespaced ("aiassist_test_...") provider
 * identifiers rather than any of this store's genuinely configured
 * providers, and cleans them up in setUp()/tearDown() so this test can
 * never leave stray rows behind or collide with real pricing.
 */
final class ProviderCostRepositoryDatabaseTest extends TestCase
{
    private const TEST_PROVIDER = 'aiassist_test_provider';
    private const TEST_PROVIDER_OTHER = 'aiassist_test_other_provider';

    private ResourceConnection $resource;
    private ProviderCostRepository $repository;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 8);
        require_once $root . '/app/bootstrap.php';

        $bootstrap = \Magento\Framework\App\Bootstrap::create($root, $_SERVER);
        $objectManager = $bootstrap->getObjectManager();

        try {
            $objectManager->get(State::class)->setAreaCode('adminhtml');
        } catch (\Throwable) {
        }

        $this->resource = $objectManager->get(ResourceConnection::class);
        $this->repository = new ProviderCostRepository(
            $this->resource,
            $objectManager->get(ClockInterface::class)
        );
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testSetPriceThenReadBackViaAll(): void
    {
        $this->repository->setPrice(self::TEST_PROVIDER, 0.005, 0.015);

        $all = $this->repository->all();

        self::assertArrayHasKey(self::TEST_PROVIDER, $all);
        self::assertSame(0.005, $all[self::TEST_PROVIDER]['input']);
        self::assertSame(0.015, $all[self::TEST_PROVIDER]['output']);
    }

    public function testAProviderWithNoRowIsAbsentFromAll(): void
    {
        self::assertArrayNotHasKey(self::TEST_PROVIDER, $this->repository->all());
    }

    public function testRepeatedSetPriceCallsUpsertRatherThanDuplicateOrError(): void
    {
        $this->repository->setPrice(self::TEST_PROVIDER, 0.005, 0.015);
        $this->repository->setPrice(self::TEST_PROVIDER, 0.010, 0.020);

        $all = $this->repository->all();

        self::assertSame(0.010, $all[self::TEST_PROVIDER]['input']);
        self::assertSame(0.020, $all[self::TEST_PROVIDER]['output']);
    }

    public function testSettingOneProviderDoesNotAffectAnother(): void
    {
        $this->repository->setPrice(self::TEST_PROVIDER, 0.005, 0.015);
        $this->repository->setPrice(self::TEST_PROVIDER_OTHER, 0.001, 0.002);

        $all = $this->repository->all();

        self::assertSame(0.005, $all[self::TEST_PROVIDER]['input']);
        self::assertSame(0.001, $all[self::TEST_PROVIDER_OTHER]['input']);
    }

    public function testAnExplicitZeroPriceIsPreservedNotTreatedAsAbsent(): void
    {
        $this->repository->setPrice(self::TEST_PROVIDER, 0.0, 0.0);

        $all = $this->repository->all();

        self::assertArrayHasKey(self::TEST_PROVIDER, $all);
        self::assertSame(0.0, $all[self::TEST_PROVIDER]['input']);
        self::assertSame(0.0, $all[self::TEST_PROVIDER]['output']);
    }

    public function testNegativePriceIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->repository->setPrice(self::TEST_PROVIDER, -1.0, 0.0);
    }

    public function testInvalidIdentifierIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->repository->setPrice('Not Valid!', 0.0, 0.0);
    }

    private function cleanup(): void
    {
        $connection = $this->resource->getConnection();
        $connection->delete(
            $this->resource->getTableName('aavirbhava_ai_provider_cost'),
            ['provider_identifier IN (?)' => [self::TEST_PROVIDER, self::TEST_PROVIDER_OTHER]]
        );
    }
}
