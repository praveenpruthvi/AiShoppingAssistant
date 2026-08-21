<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\AttributeIndexingSelectionRepository;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

/**
 * Exercises AttributeIndexingSelectionRepository against the real
 * database — the insertOnDuplicate upsert semantics aren't meaningfully
 * re-verifiable against a mocked adapter, the same rationale as every
 * other ResourceConnection-direct repository in this module
 * (DbCostUsageTrackerDatabaseTest, MerchandisingBoostDatabaseTest).
 *
 * Uses a real, but clearly test-namespaced ("aiassist_test_...") set of
 * attribute codes rather than any of this store's genuinely configured
 * codes, and cleans them up in setUp()/tearDown() so this test can never
 * leave stray rows behind or collide with this store's real selection.
 */
final class AttributeIndexingSelectionRepositoryDatabaseTest extends TestCase
{
    private ResourceConnection $resource;
    private AttributeIndexingSelectionRepository $repository;

    /**
     * @var list<string>
     */
    private array $usedCodes = [
        'aiassist_test_alpha',
        'aiassist_test_beta',
        'aiassist_test_gamma',
    ];

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
        $this->repository = new AttributeIndexingSelectionRepository(
            $this->resource,
            $objectManager->get(ClockInterface::class)
        );
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testSetIndexedTrueThenReadBackIsIndexed(): void
    {
        $this->repository->setIndexed(['aiassist_test_alpha'], true);

        self::assertTrue($this->repository->isIndexed('aiassist_test_alpha'));
        self::assertContains('aiassist_test_alpha', $this->repository->indexedCodes());
    }

    public function testACodeWithNoRowIsNotIndexed(): void
    {
        self::assertFalse($this->repository->isIndexed('aiassist_test_alpha'));
    }

    public function testSetIndexedFalseAfterTrueCorrectlyDeselects(): void
    {
        $this->repository->setIndexed(['aiassist_test_alpha'], true);
        self::assertTrue($this->repository->isIndexed('aiassist_test_alpha'));

        $this->repository->setIndexed(['aiassist_test_alpha'], false);
        self::assertFalse($this->repository->isIndexed('aiassist_test_alpha'));
        self::assertNotContains('aiassist_test_alpha', $this->repository->indexedCodes());
    }

    public function testSettingOneCodeDoesNotAffectAnother(): void
    {
        $this->repository->setIndexed(['aiassist_test_alpha'], true);
        $this->repository->setIndexed(['aiassist_test_beta'], true);
        $this->repository->setIndexed(['aiassist_test_alpha'], false);

        self::assertFalse($this->repository->isIndexed('aiassist_test_alpha'));
        self::assertTrue($this->repository->isIndexed('aiassist_test_beta'));
    }

    public function testSetIndexedAcceptsMultipleCodesInOneCall(): void
    {
        $this->repository->setIndexed(['aiassist_test_alpha', 'aiassist_test_beta', 'aiassist_test_gamma'], true);

        $codes = $this->repository->indexedCodes();
        self::assertContains('aiassist_test_alpha', $codes);
        self::assertContains('aiassist_test_beta', $codes);
        self::assertContains('aiassist_test_gamma', $codes);
    }

    public function testAllReturnsEveryRowRegardlessOfSelectionState(): void
    {
        $this->repository->setIndexed(['aiassist_test_alpha'], true);
        $this->repository->setIndexed(['aiassist_test_beta'], false);

        $all = $this->repository->all();

        self::assertArrayHasKey('aiassist_test_alpha', $all);
        self::assertArrayHasKey('aiassist_test_beta', $all);
        self::assertTrue($all['aiassist_test_alpha']);
        self::assertFalse($all['aiassist_test_beta']);
    }

    public function testRepeatedSetIndexedCallsUpsertRatherThanDuplicateOrError(): void
    {
        $this->repository->setIndexed(['aiassist_test_alpha'], true);
        $this->repository->setIndexed(['aiassist_test_alpha'], true);
        $this->repository->setIndexed(['aiassist_test_alpha'], false);
        $this->repository->setIndexed(['aiassist_test_alpha'], true);

        self::assertTrue($this->repository->isIndexed('aiassist_test_alpha'));
    }

    private function cleanup(): void
    {
        $connection = $this->resource->getConnection();
        $connection->delete(
            $this->resource->getTableName('aavirbhava_ai_attribute_indexing_selection'),
            ['attribute_code IN (?)' => $this->usedCodes]
        );
    }
}
