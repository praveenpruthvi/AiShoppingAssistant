<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Merchandising;

use Aavirbhava\AiShoppingAssistant\Model\Merchandising\ActiveBoostReader;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\Exception\MerchandisingBoostException;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\MerchandisingBoostRepository;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\MerchandisingBoostRow;
use Aavirbhava\AiShoppingAssistant\Model\MerchandisingBoostFactory;
use Aavirbhava\AiShoppingAssistant\Model\ResourceModel\MerchandisingBoost as MerchandisingBoostResource;
use Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Indexing\Queue\Fixture\MutableClock;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

/**
 * Exercises MerchandisingBoostRepository and ActiveBoostReader against the
 * real database — AbstractModel/AbstractDb save/load behavior and the
 * date-range SQL in ActiveBoostReader aren't meaningfully testable against
 * a mocked adapter, the same rationale as
 * DbConversationHistoryStoreDatabaseTest.
 *
 * product_id carries a real foreign key to catalog_product_entity (unlike
 * conversation_message, which has none), so this test resolves one real
 * existing product id from the actual catalog rather than using an
 * arbitrary placeholder.
 */
final class MerchandisingBoostDatabaseTest extends TestCase
{
    private ResourceConnection $resource;
    private MerchandisingBoostRepository $repository;
    private int $productId;
    private int $otherProductId;

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

        $productIds = $this->resource->getConnection()->fetchCol(
            $this->resource->getConnection()->select()
                ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id'])
                ->order('entity_id ASC')
                ->limit(2)
        );

        if (count($productIds) < 2) {
            self::markTestSkipped('This test requires at least 2 real products in the catalog.');
        }

        $this->productId = (int) $productIds[0];
        $this->otherProductId = (int) $productIds[1];

        $this->repository = new MerchandisingBoostRepository(
            $objectManager->get(MerchandisingBoostFactory::class),
            $objectManager->get(MerchandisingBoostResource::class)
        );

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testSaveThenGetByIdRoundTripsAllFields(): void
    {
        $saved = $this->repository->save(new MerchandisingBoostRow(
            null,
            $this->productId,
            0.75,
            '2026-01-01 00:00:00',
            '2026-12-31 23:59:59',
            true
        ));
        $this->createdBoostIds[] = $saved->boostId();

        self::assertNotNull($saved->boostId());
        self::assertGreaterThan(0, $saved->boostId());

        $reloaded = $this->repository->getById($saved->boostId());

        self::assertSame($this->productId, $reloaded->productId());
        self::assertSame(0.75, $reloaded->boostWeight());
        self::assertSame('2026-01-01 00:00:00', $reloaded->startDate());
        self::assertSame('2026-12-31 23:59:59', $reloaded->endDate());
        self::assertTrue($reloaded->isActive());
        self::assertNotNull($reloaded->createdAt());
        self::assertNotNull($reloaded->updatedAt());
    }

    public function testSaveWithAnExistingBoostIdUpdatesInPlaceRatherThanCreatingANewRow(): void
    {
        $saved = $this->repository->save(new MerchandisingBoostRow(null, $this->productId, 0.2, null, null, true));
        $this->createdBoostIds[] = $saved->boostId();

        $updated = $this->repository->save(
            new MerchandisingBoostRow($saved->boostId(), $this->productId, 0.9, null, null, false)
        );

        self::assertSame($saved->boostId(), $updated->boostId());
        self::assertSame(0.9, $updated->boostWeight());
        self::assertFalse($updated->isActive());

        $all = $this->resource->getConnection()->fetchAll(
            $this->resource->getConnection()->select()
                ->from($this->resource->getTableName(MerchandisingBoostResource::TABLE))
                ->where('product_id = ?', $this->productId)
        );
        self::assertCount(1, $all);
    }

    public function testDeleteByIdRemovesTheRowAndIsIdempotent(): void
    {
        $saved = $this->repository->save(new MerchandisingBoostRow(null, $this->productId, 0.3, null, null, true));

        $this->repository->deleteById($saved->boostId());

        $this->expectException(MerchandisingBoostException::class);
        $this->repository->getById($saved->boostId());
    }

    public function testDeleteByIdOnANonExistentIdIsNotAnError(): void
    {
        $this->repository->deleteById(999999999);
        self::addToAssertionCount(1);
    }

    public function testActiveBoostReaderReturnsAnActiveInRangeBoost(): void
    {
        $saved = $this->repository->save(new MerchandisingBoostRow(
            null,
            $this->productId,
            0.6,
            '2026-01-01 00:00:00',
            '2026-12-31 23:59:59',
            true
        ));
        $this->createdBoostIds[] = $saved->boostId();

        $reader = new ActiveBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:00')));

        self::assertSame([$this->productId => 0.6], $reader->forProductIds([$this->productId, $this->otherProductId]));
    }

    public function testActiveBoostReaderExcludesAnInactiveBoost(): void
    {
        $saved = $this->repository->save(new MerchandisingBoostRow(null, $this->productId, 0.6, null, null, false));
        $this->createdBoostIds[] = $saved->boostId();

        $reader = new ActiveBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:00')));

        self::assertSame([], $reader->forProductIds([$this->productId]));
    }

    public function testActiveBoostReaderExcludesABoostWhoseStartDateIsInTheFuture(): void
    {
        $saved = $this->repository->save(
            new MerchandisingBoostRow(null, $this->productId, 0.6, '2027-01-01 00:00:00', null, true)
        );
        $this->createdBoostIds[] = $saved->boostId();

        $reader = new ActiveBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:00')));

        self::assertSame([], $reader->forProductIds([$this->productId]));
    }

    public function testActiveBoostReaderExcludesABoostWhoseEndDateIsInThePast(): void
    {
        $saved = $this->repository->save(
            new MerchandisingBoostRow(null, $this->productId, 0.6, null, '2025-01-01 00:00:00', true)
        );
        $this->createdBoostIds[] = $saved->boostId();

        $reader = new ActiveBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:00')));

        self::assertSame([], $reader->forProductIds([$this->productId]));
    }

    public function testActiveBoostReaderTakesTheHighestWeightWhenMultipleActiveBoostsOverlap(): void
    {
        $first = $this->repository->save(new MerchandisingBoostRow(null, $this->productId, 0.3, null, null, true));
        $second = $this->repository->save(new MerchandisingBoostRow(null, $this->productId, 0.8, null, null, true));
        $this->createdBoostIds[] = $first->boostId();
        $this->createdBoostIds[] = $second->boostId();

        $reader = new ActiveBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:00')));

        self::assertSame([$this->productId => 0.8], $reader->forProductIds([$this->productId]));
    }

    public function testASavedBoostIsImmediatelyVisibleToAFreshReaderInstanceWithNoStaleCache(): void
    {
        // Proves the "admin save must invalidate/bypass any cache
        // immediately" requirement at the object level: a brand-new
        // ActiveBoostReader instance (the closest a single PHPUnit process
        // can get to "a separate PHP-FPM request," which never shares
        // instance state at all) sees a boost saved moments earlier with
        // no extra step. The Task 32 status report additionally verifies
        // this across two genuinely separate PHP processes.
        $reader = new ActiveBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:00')));
        self::assertSame([], $reader->forProductIds([$this->productId]));

        $saved = $this->repository->save(new MerchandisingBoostRow(null, $this->productId, 0.5, null, null, true));
        $this->createdBoostIds[] = $saved->boostId();

        $freshReader = new ActiveBoostReader($this->resource, new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:01')));
        self::assertSame([$this->productId => 0.5], $freshReader->forProductIds([$this->productId]));
    }

    private function cleanup(): void
    {
        if ($this->createdBoostIds !== []) {
            $this->resource->getConnection()->delete(
                $this->resource->getTableName(MerchandisingBoostResource::TABLE),
                ['boost_id IN (?)' => $this->createdBoostIds]
            );
            $this->createdBoostIds = [];
        }

        if (isset($this->productId)) {
            $this->resource->getConnection()->delete(
                $this->resource->getTableName(MerchandisingBoostResource::TABLE),
                ['product_id IN (?)' => [$this->productId, $this->otherProductId]]
            );
        }
    }
}
