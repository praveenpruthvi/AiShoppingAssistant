<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Indexing\RebuildFence;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\RebuildFenceException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildFence\DbRebuildFence;
use Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Indexing\Queue\Fixture\MutableClock;
use Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Indexing\Queue\Fixture\SequenceLeaseTokenGenerator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

final class DbRebuildFenceDatabaseTest extends TestCase
{
    private ResourceConnection $resource;
    private AdapterInterface $connection;
    private string $table;
    private MutableClock $clock;
    private SequenceLeaseTokenGenerator $tokens;
    private DbRebuildFence $fence;

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
        $this->table = $this->resource->getTableName(DbRebuildFence::TABLE);
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-08-16 00:00:00'));
        $this->tokens = new SequenceLeaseTokenGenerator();
        $this->fence = new DbRebuildFence($this->resource, $this->clock, $this->tokens);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testActiveFenceCannotBeResetByAnotherAcquire(): void
    {
        $token = $this->fence->acquire(300);
        $this->tokens->setNext(str_repeat('c', 64));

        try {
            $this->fence->acquire(300);
            self::fail('Expected active fence to reject a second acquire.');
        } catch (RebuildFenceException) {
            $this->assertFenceRow('1', $token);
        }
    }

    public function testConcurrentAcquireIsRejectedWhileActive(): void
    {
        $this->fence->acquire(300);

        $this->expectException(RebuildFenceException::class);
        $this->fence->acquire(300);
    }

    public function testExactTokenRenewAndRelease(): void
    {
        $token = $this->fence->acquire(300);
        $wrong = str_repeat('d', 64);

        $this->expectFenceFailure(fn() => $this->fence->renew($wrong, 300));
        $this->expectFenceFailure(fn() => $this->fence->release($wrong));
        $this->fence->renew($token, 300);
        $this->fence->assertOwned($token);
        $this->fence->release($token);
        $this->assertFenceRow('0', null);
    }

    public function testExpiredOwnerCanBeTakenOverUnderLock(): void
    {
        $old = $this->fence->acquire(300);
        $this->connection->update(
            $this->table,
            ['lease_expires_at' => '2026-08-15 23:59:59'],
            ['fence_id = ?' => DbRebuildFence::FENCE_ID]
        );
        $this->tokens->setNext(str_repeat('e', 64));

        $new = $this->fence->acquire(300);

        self::assertNotSame($old, $new);
        $this->assertFenceRow('1', $new);
    }

    /**
     * @dataProvider malformedRowProvider
     *
     * @param array<string, mixed> $row
     */
    public function testMalformedRowsFailClosed(array $row): void
    {
        $this->insertFenceRow($row);

        $this->expectException(RebuildFenceException::class);
        $this->fence->acquire(300);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedRowProvider(): array
    {
        return [
            'invalid active flag' => [['is_active' => 2, 'owner_token' => null, 'lease_expires_at' => null]],
            'active missing token' => [['is_active' => 1, 'owner_token' => null, 'lease_expires_at' => '2026-08-16 00:10:00']],
            'active malformed token' => [['is_active' => 1, 'owner_token' => 'bad token', 'lease_expires_at' => '2026-08-16 00:10:00']],
            'active malformed timestamp' => [['is_active' => 1, 'owner_token' => str_repeat('f', 64), 'lease_expires_at' => '0000-00-00 00:00:00']],
            'inactive token present' => [['is_active' => 0, 'owner_token' => str_repeat('g', 64), 'lease_expires_at' => null]],
            'inactive expiry present' => [['is_active' => 0, 'owner_token' => null, 'lease_expires_at' => '2026-08-16 00:10:00']],
        ];
    }

    private function expectFenceFailure(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected sanitized rebuild fence failure.');
        } catch (RebuildFenceException) {
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertFenceRow(array $row): void
    {
        $this->connection->insert(
            $this->table,
            [
                'fence_id' => DbRebuildFence::FENCE_ID,
                'is_active' => $row['is_active'],
                'owner_token' => $row['owner_token'],
                'lease_expires_at' => $row['lease_expires_at'],
                'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
                'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]
        );
    }

    private function assertFenceRow(string $active, ?string $token): void
    {
        $row = $this->connection->fetchRow(
            $this->connection->select()
                ->from($this->table())
                ->where('fence_id = ?', DbRebuildFence::FENCE_ID)
        );

        self::assertIsArray($row);
        self::assertSame($active, (string)$row['is_active']);
        self::assertSame($token, $row['owner_token']);
    }

    private function table(): string
    {
        return $this->table;
    }

    private function cleanup(): void
    {
        $this->connection->delete($this->table, ['fence_id = ?' => DbRebuildFence::FENCE_ID]);
    }
}
