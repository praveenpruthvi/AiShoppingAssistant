<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Merchandising;

use Aavirbhava\AiShoppingAssistant\Model\Merchandising\ActiveCategoryBoostReader;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\CategoryBoostRepository;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\CategoryBoostRow;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\Exception\MerchandisingBoostException;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\ProductCategoryMembershipReader;
use Aavirbhava\AiShoppingAssistant\Model\CategoryBoostFactory;
use Aavirbhava\AiShoppingAssistant\Model\ResourceModel\CategoryBoost as CategoryBoostResource;
use Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Indexing\Queue\Fixture\MutableClock;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

/**
 * Exercises CategoryBoostRepository, ActiveCategoryBoostReader, and
 * ProductCategoryMembershipReader against the real database — mirrors
 * MerchandisingBoostDatabaseTest exactly (see that class's own docblock
 * for why AbstractModel/AbstractDb save/load behavior and date-range SQL
 * aren't meaningfully testable against a mocked adapter), plus the one
 * genuinely new piece: ProductCategoryMembershipReader's real query
 * against `catalog_category_product`, which needs a real product that
 * genuinely belongs to a real category to prove anything at all.
 *
 * category_id carries a real foreign key to catalog_category_entity
 * (like MerchandisingBoostDatabaseTest's own product_id), so this test
 * resolves real existing category ids from the actual catalog rather
 * than using an arbitrary placeholder.
 */
final class CategoryBoostDatabaseTest extends TestCase
{
    private ResourceConnection $resource;
    private CategoryBoostRepository $repository;
    private int $categoryId;
    private int $otherCategoryId;
    private int $productIdInCategory;

    /**
     * @var list<int>
     */
    private array $createdBoostIds = [];

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

        // A real category that genuinely has at least one real product
        // assigned to it — needed to prove ProductCategoryMembershipReader's
        // real query against catalog_category_product, not just
        // CategoryBoostRepository/ActiveCategoryBoostReader's own tables.
        $membershipRow = $this->resource->getConnection()->fetchRow(
            $this->resource->getConnection()->select()
                ->from($this->resource->getTableName('catalog_category_product'), ['category_id', 'product_id'])
                ->order('category_id ASC')
                ->limit(1)
        );

        $otherCategoryIds = $this->resource->getConnection()->fetchCol(
            $this->resource->getConnection()->select()
                ->from($this->resource->getTableName('catalog_category_entity'), ['entity_id'])
                ->order('entity_id ASC')
                ->limit(2)
        );

        if (!$membershipRow || count($otherCategoryIds) < 2) {
            self::markTestSkipped('This test requires real category/product-category data in the catalog.');
        }

        $this->categoryId = (int) $membershipRow['category_id'];
        $this->productIdInCategory = (int) $membershipRow['product_id'];
        $this->otherCategoryId = (int) $otherCategoryIds[0] === $this->categoryId
            ? (int) $otherCategoryIds[1]
            : (int) $otherCategoryIds[0];

        $this->repository = new CategoryBoostRepository(
            $objectManager->get(CategoryBoostFactory::class),
            $objectManager->get(CategoryBoostResource::class)
        );

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testSaveThenGetByIdRoundTripsAllFields(): void
    {
        $saved = $this->repository->save(new CategoryBoostRow(
            null,
            $this->categoryId,
            0.75,
            '2026-01-01 00:00:00',
            '2026-12-31 23:59:59',
            true
        ));
        $this->createdBoostIds[] = $saved->boostId();

        self::assertNotNull($saved->boostId());
        self::assertGreaterThan(0, $saved->boostId());

        $reloaded = $this->repository->getById($saved->boostId());

        self::assertSame($this->categoryId, $reloaded->categoryId());
        self::assertSame(0.75, $reloaded->boostWeight());
        self::assertSame('2026-01-01 00:00:00', $reloaded->startDate());
        self::assertSame('2026-12-31 23:59:59', $reloaded->endDate());
        self::assertTrue($reloaded->isActive());
        self::assertNotNull($reloaded->createdAt());
        self::assertNotNull($reloaded->updatedAt());
    }

    public function testSaveWithAnExistingBoostIdUpdatesInPlaceRatherThanCreatingANewRow(): void
    {
        $saved = $this->repository->save(new CategoryBoostRow(null, $this->categoryId, 0.2, null, null, true));
        $this->createdBoostIds[] = $saved->boostId();

        $updated = $this->repository->save(
            new CategoryBoostRow($saved->boostId(), $this->categoryId, 0.9, null, null, false)
        );

        self::assertSame($saved->boostId(), $updated->boostId());
        self::assertSame(0.9, $updated->boostWeight());
        self::assertFalse($updated->isActive());

        $all = $this->resource->getConnection()->fetchAll(
            $this->resource->getConnection()->select()
                ->from($this->resource->getTableName(CategoryBoostResource::TABLE))
                ->where('category_id = ?', $this->categoryId)
        );
        self::assertCount(1, $all);
    }

    public function testFindByCategoryIdReturnsTheSavedBoost(): void
    {
        self::assertNull($this->repository->findByCategoryId($this->categoryId));

        $saved = $this->repository->save(new CategoryBoostRow(null, $this->categoryId, 0.4, null, null, true));
        $this->createdBoostIds[] = $saved->boostId();

        $found = $this->repository->findByCategoryId($this->categoryId);

        self::assertNotNull($found);
        self::assertSame($saved->boostId(), $found->boostId());
        self::assertSame(0.4, $found->boostWeight());
    }

    public function testFindByCategoryIdReturnsNullForACategoryWithNoBoost(): void
    {
        self::assertNull($this->repository->findByCategoryId($this->otherCategoryId));
    }

    public function testDeleteByIdRemovesTheRowAndIsIdempotent(): void
    {
        $saved = $this->repository->save(new CategoryBoostRow(null, $this->categoryId, 0.3, null, null, true));

        $this->repository->deleteById($saved->boostId());

        $this->expectException(MerchandisingBoostException::class);
        $this->repository->getById($saved->boostId());
    }

    public function testDeleteByIdOnANonExistentIdIsNotAnError(): void
    {
        $this->repository->deleteById(999999999);
        self::addToAssertionCount(1);
    }

    public function testActiveCategoryBoostReaderReturnsAnActiveInRangeBoost(): void
    {
        $saved = $this->repository->save(new CategoryBoostRow(
            null,
            $this->categoryId,
            0.6,
            '2026-01-01 00:00:00',
            '2026-12-31 23:59:59',
            true
        ));
        $this->createdBoostIds[] = $saved->boostId();

        $reader = new ActiveCategoryBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:00')));

        self::assertSame(
            [$this->categoryId => 0.6],
            $reader->forCategoryIds([$this->categoryId, $this->otherCategoryId])
        );
    }

    public function testActiveCategoryBoostReaderExcludesAnInactiveBoost(): void
    {
        $saved = $this->repository->save(new CategoryBoostRow(null, $this->categoryId, 0.6, null, null, false));
        $this->createdBoostIds[] = $saved->boostId();

        $reader = new ActiveCategoryBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:00')));

        self::assertSame([], $reader->forCategoryIds([$this->categoryId]));
    }

    public function testActiveCategoryBoostReaderExcludesABoostWhoseStartDateIsInTheFuture(): void
    {
        $saved = $this->repository->save(
            new CategoryBoostRow(null, $this->categoryId, 0.6, '2027-01-01 00:00:00', null, true)
        );
        $this->createdBoostIds[] = $saved->boostId();

        $reader = new ActiveCategoryBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:00')));

        self::assertSame([], $reader->forCategoryIds([$this->categoryId]));
    }

    public function testActiveCategoryBoostReaderExcludesABoostWhoseEndDateIsInThePast(): void
    {
        $saved = $this->repository->save(
            new CategoryBoostRow(null, $this->categoryId, 0.6, null, '2025-01-01 00:00:00', true)
        );
        $this->createdBoostIds[] = $saved->boostId();

        $reader = new ActiveCategoryBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:00')));

        self::assertSame([], $reader->forCategoryIds([$this->categoryId]));
    }

    public function testActiveCategoryBoostReaderTakesTheHighestWeightWhenMultipleActiveBoostsOverlap(): void
    {
        $first = $this->repository->save(new CategoryBoostRow(null, $this->categoryId, 0.3, null, null, true));
        $second = $this->repository->save(new CategoryBoostRow(null, $this->categoryId, 0.8, null, null, true));
        $this->createdBoostIds[] = $first->boostId();
        $this->createdBoostIds[] = $second->boostId();

        $reader = new ActiveCategoryBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:00')));

        self::assertSame([$this->categoryId => 0.8], $reader->forCategoryIds([$this->categoryId]));
    }

    public function testASavedCategoryBoostIsImmediatelyVisibleToAFreshReaderInstanceWithNoStaleCache(): void
    {
        $reader = new ActiveCategoryBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:00')));
        self::assertSame([], $reader->forCategoryIds([$this->categoryId]));

        $saved = $this->repository->save(new CategoryBoostRow(null, $this->categoryId, 0.5, null, null, true));
        $this->createdBoostIds[] = $saved->boostId();

        $freshReader = new ActiveCategoryBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:01')));
        self::assertSame([$this->categoryId => 0.5], $freshReader->forCategoryIds([$this->categoryId]));
    }

    /**
     * Proves ProductCategoryMembershipReader's real query against the
     * real catalog_category_product table — the piece MerchandisingBoostSignal
     * needs to know which category(ies) a product's own boost should
     * combine with, live, scoped to only the products currently in a
     * candidate set.
     */
    public function testProductCategoryMembershipReaderFindsARealProductsRealCategoryMembership(): void
    {
        $reader = new ProductCategoryMembershipReader($this->resource);

        $result = $reader->forProductIds([$this->productIdInCategory]);

        self::assertArrayHasKey($this->productIdInCategory, $result);
        self::assertContains($this->categoryId, $result[$this->productIdInCategory]);
    }

    public function testProductCategoryMembershipReaderReturnsNothingForAProductWithNoRealCategoryAssignment(): void
    {
        // A product id that certainly does not exist has, by definition,
        // no real category_product row.
        $reader = new ProductCategoryMembershipReader($this->resource);

        self::assertSame([], $reader->forProductIds([999999999]));
    }

    private function cleanup(): void
    {
        if ($this->createdBoostIds !== []) {
            $this->resource->getConnection()->delete(
                $this->resource->getTableName(CategoryBoostResource::TABLE),
                ['boost_id IN (?)' => $this->createdBoostIds]
            );
            $this->createdBoostIds = [];
        }

        if (isset($this->categoryId)) {
            $this->resource->getConnection()->delete(
                $this->resource->getTableName(CategoryBoostResource::TABLE),
                ['category_id IN (?)' => [$this->categoryId, $this->otherCategoryId]]
            );
        }
    }
}
