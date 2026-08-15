<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Indexing\Reconciliation;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalReconciliationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Reconciliation\DbIncrementalReconciliationCheckpoint;
use Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Indexing\Queue\Fixture\MutableClock;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

final class DbIncrementalReconciliationCheckpointDatabaseTest extends TestCase
{
    private ResourceConnection $resource;
    private AdapterInterface $connection;
    private string $table;
    private MutableClock $clock;
    private DbIncrementalReconciliationCheckpoint $checkpoint;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 9);
        require_once $root . '/app/bootstrap.php';

        $bootstrap = \Magento\Framework\App\Bootstrap::create($root, $_SERVER);
        $objectManager = $bootstrap->getObjectManager();

        try {
            $objectManager->get(State::class)->setAreaCode('adminhtml');
        } catch (\Throwable) {
        }

        $this->resource = $objectManager->get(ResourceConnection::class);
        $this->connection = $this->resource->getConnection();
        $this->table = $this->resource->getTableName(DbIncrementalReconciliationCheckpoint::TABLE);
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-08-16 00:00:00'));
        $this->checkpoint = new DbIncrementalReconciliationCheckpoint($this->resource, $this->clock);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testCurrentCreatesHarmlessSingletonCursorWithoutResettingExistingRow(): void
    {
        $this->checkpoint->advance(42);
        self::assertSame(42, $this->checkpoint->current()->lastProductId());

        $this->clock->advance(60);
        self::assertSame(42, $this->checkpoint->current()->lastProductId());
        $this->assertRow(['last_product_id' => '42', 'completed_at' => null]);
    }

    public function testRequestFullPassResetsCursorWithoutProductContent(): void
    {
        $this->checkpoint->advance(42);
        $this->checkpoint->requestFullPass();

        self::assertSame(0, $this->checkpoint->current()->lastProductId());
        $this->assertRow(['last_product_id' => '0', 'completed_at' => null]);
    }

    public function testCompletePassResetsCursorWithCompletionTimestamp(): void
    {
        $this->checkpoint->advance(99);
        $this->checkpoint->completePass();

        self::assertSame(0, $this->checkpoint->current()->lastProductId());
        $this->assertRow(['last_product_id' => '0', 'completed_at' => '2026-08-16 00:00:00']);
    }

    public function testInvalidAdvanceFailsClosed(): void
    {
        $this->expectException(IncrementalReconciliationException::class);
        $this->checkpoint->advance(0);
    }

    /**
     * @param array<string, string|null> $expected
     */
    private function assertRow(array $expected): void
    {
        $row = $this->connection->fetchRow(
            $this->connection->select()
                ->from($this->table)
                ->where('cursor_id = ?', DbIncrementalReconciliationCheckpoint::CURSOR_ID)
        );
        self::assertIsArray($row);

        foreach ($expected as $key => $value) {
            self::assertSame($value, $row[$key]);
        }

        self::assertArrayNotHasKey('payload', $row);
        self::assertArrayNotHasKey('document', $row);
        self::assertArrayNotHasKey('error', $row);
    }

    private function cleanup(): void
    {
        $this->connection->delete($this->table, ['cursor_id = ?' => DbIncrementalReconciliationCheckpoint::CURSOR_ID]);
    }
}
