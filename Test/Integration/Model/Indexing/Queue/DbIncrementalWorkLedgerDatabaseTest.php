<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalLedgerPersistenceException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\DbIncrementalWorkLedger;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalWorkClaim;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalWorkState;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildFence\DbRebuildFence;
use Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Indexing\Queue\Fixture\MutableClock;
use Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Indexing\Queue\Fixture\SequenceLeaseTokenGenerator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

final class DbIncrementalWorkLedgerDatabaseTest extends TestCase
{
    private const PRODUCT_ID = 987654321;
    private const PRODUCT_ID_TWO = 987654322;

    private ResourceConnection $resource;
    private AdapterInterface $connection;
    private string $table;
    private string $fenceTable;
    private MutableClock $clock;
    private SequenceLeaseTokenGenerator $tokens;
    private DbIncrementalWorkLedger $ledger;
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
        $this->table = $this->resource->getTableName('aavirbhava_ai_incremental_product_work');
        $this->fenceTable = $this->resource->getTableName(DbRebuildFence::TABLE);
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-08-16 00:00:00'));
        $this->tokens = new SequenceLeaseTokenGenerator();
        $this->ledger = new DbIncrementalWorkLedger($this->resource, $this->clock, $this->tokens);
        $this->fence = new DbRebuildFence($this->resource, $this->clock, $this->tokens);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testRecordClaimCompleteAndGenerationHandoffAreSerialized(): void
    {
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        $this->assertRow(['generation' => '1', 'state' => IncrementalWorkState::PENDING]);

        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        $this->assertRow(['generation' => '2', 'state' => IncrementalWorkState::PENDING, 'attempts' => '0']);

        $claim = $this->ledger->claimDueWork(self::PRODUCT_ID);
        self::assertNotNull($claim);
        self::assertSame(2, $claim->generation());
        self::assertSame(0, $claim->attempts());
        self::assertNull($this->ledger->claimDueWork(self::PRODUCT_ID));
        $this->assertRow([
            'generation' => '2',
            'claimed_generation' => '2',
            'state' => IncrementalWorkState::PROCESSING,
        ]);

        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        self::assertNull($this->ledger->claimDueWork(self::PRODUCT_ID));
        $this->assertRow([
            'generation' => '3',
            'claimed_generation' => '2',
            'state' => IncrementalWorkState::PROCESSING,
            'lease_token' => $claim->leaseToken(),
        ]);

        self::assertTrue($this->ledger->complete($claim));
        $this->assertRow([
            'generation' => '3',
            'claimed_generation' => null,
            'state' => IncrementalWorkState::PENDING,
            'attempts' => '0',
        ]);

        $newerClaim = $this->ledger->claimDueWork(self::PRODUCT_ID);
        self::assertNotNull($newerClaim);
        self::assertSame(3, $newerClaim->generation());
        self::assertTrue($this->ledger->complete($newerClaim));
        $this->assertRow(['generation' => '3', 'state' => IncrementalWorkState::COMPLETE]);
    }

    public function testOlderRetryAndTerminalCannotBlockNewerGeneration(): void
    {
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        $claim = $this->ledger->claimDueWork(self::PRODUCT_ID);
        self::assertNotNull($claim);
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);

        self::assertTrue($this->ledger->recordRetry($claim, 'opensearch_backend_unavailable', 60));
        $this->assertRow([
            'generation' => '2',
            'claimed_generation' => null,
            'state' => IncrementalWorkState::PENDING,
            'attempts' => '0',
            'last_error_code' => null,
        ]);

        $claim = $this->ledger->claimDueWork(self::PRODUCT_ID);
        self::assertNotNull($claim);
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        self::assertTrue($this->ledger->recordTerminal($claim, 'unknown'));
        $this->assertRow([
            'generation' => '3',
            'claimed_generation' => null,
            'state' => IncrementalWorkState::PENDING,
            'attempts' => '0',
        ]);
    }

    public function testStaleTokenAndGenerationCannotMutateState(): void
    {
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        $claim = $this->ledger->claimDueWork(self::PRODUCT_ID);
        self::assertNotNull($claim);

        $staleToken = new IncrementalWorkClaim(self::PRODUCT_ID, $claim->generation(), 0, str_repeat('c', 64));
        self::assertFalse($this->ledger->complete($staleToken));

        $staleGeneration = new IncrementalWorkClaim(self::PRODUCT_ID, 2, 0, $claim->leaseToken());
        self::assertFalse($this->ledger->recordTerminal($staleGeneration, 'unknown'));
        $this->assertRow(['generation' => '1', 'state' => IncrementalWorkState::PROCESSING]);
    }

    public function testExpiredLeaseConsumesBoundedAttemptsAndBlocks(): void
    {
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $claim = $this->ledger->claimDueWork(self::PRODUCT_ID);
            self::assertNotNull($claim);
            $this->expireLease();
            self::assertSame(1, $this->ledger->recoverExpiredLeases(10));

            $expectedState = $attempt === 5 ? IncrementalWorkState::BLOCKED : IncrementalWorkState::RETRY_WAIT;
            $this->assertRow(['attempts' => (string)$attempt, 'state' => $expectedState]);

            if ($attempt < 5) {
                $this->clock->advance(3600);
            }
        }
    }

    public function testExpiredOldGenerationReleasesNewerPendingWithoutChargingAttempts(): void
    {
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        $claim = $this->ledger->claimDueWork(self::PRODUCT_ID);
        self::assertNotNull($claim);
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        $this->expireLease();

        self::assertSame(1, $this->ledger->recoverExpiredLeases(10));
        $this->assertRow([
            'generation' => '2',
            'claimed_generation' => null,
            'state' => IncrementalWorkState::PENDING,
            'attempts' => '0',
            'last_error_code' => null,
        ]);
    }

    public function testPublicationVisibilityExpiryPermitsRepublishing(): void
    {
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        $queued = $this->ledger->markQueuedForWakeup(self::PRODUCT_ID, 300);
        self::assertNotNull($queued);
        self::assertSame([], $this->ledger->dueProductIds(10));

        $this->clock->advance(301);
        self::assertSame([self::PRODUCT_ID], $this->ledger->dueProductIds(10));
        self::assertTrue($this->ledger->releaseQueuedWakeup($queued));
    }

    public function testInvalidIdsMalformedTokensAndRawErrorsAreSanitized(): void
    {
        $this->expectException(InvalidProductIndexEntityIdsException::class);
        $this->ledger->recordProductChanges([0]);
    }

    public function testMalformedGeneratedTokenFailsSanitizedAndDoesNotPersistToken(): void
    {
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        $this->tokens->setNext('bad token with spaces');

        try {
            $this->ledger->claimDueWork(self::PRODUCT_ID);
            self::fail('Expected sanitized ledger persistence failure.');
        } catch (IncrementalLedgerPersistenceException) {
            $this->assertRow(['lease_token' => null, 'state' => IncrementalWorkState::PENDING]);
        }
    }

    public function testRecoverySelectionIsBoundedAndDeterministic(): void
    {
        $this->ledger->recordProductChanges([self::PRODUCT_ID_TWO, self::PRODUCT_ID]);

        self::assertSame([self::PRODUCT_ID], $this->ledger->dueProductIds(1));
    }

    public function testClaimIsBlockedWhileRebuildFenceIsActive(): void
    {
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        $this->fence->acquire(300);

        self::assertNull($this->ledger->claimDueWork(self::PRODUCT_ID));
        $this->assertRow(['state' => IncrementalWorkState::PENDING, 'generation' => '1']);
    }

    public function testConsumedQueuedWakeupBecomesImmediatelyReplayableWhileFenced(): void
    {
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        self::assertNotNull($this->ledger->markQueuedForWakeup(self::PRODUCT_ID, 300));
        self::assertSame([], $this->ledger->dueProductIds(10));
        $this->fence->acquire(300);

        self::assertNull($this->ledger->claimDueWork(self::PRODUCT_ID));

        $this->assertRow([
            'state' => IncrementalWorkState::PENDING,
            'generation' => '1',
            'attempts' => '0',
            'lease_token' => null,
        ]);
        self::assertSame([self::PRODUCT_ID], $this->ledger->dueProductIds(10));
    }

    public function testGenerationScheduledDuringRebuildSurvivesCutover(): void
    {
        $token = $this->fence->acquire(300);
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        $this->fence->release($token);

        self::assertSame([self::PRODUCT_ID], $this->ledger->dueProductIds(10));
        $claim = $this->ledger->claimDueWork(self::PRODUCT_ID);
        self::assertNotNull($claim);
        self::assertSame(1, $claim->generation());
    }

    public function testMalformedFenceRowFailsClaimClosed(): void
    {
        $this->ledger->recordProductChanges([self::PRODUCT_ID]);
        $this->connection->insert(
            $this->fenceTable,
            [
                'fence_id' => DbRebuildFence::FENCE_ID,
                'is_active' => 1,
                'owner_token' => 'bad token',
                'lease_expires_at' => '2026-08-16 00:10:00',
                'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
                'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]
        );

        $this->expectException(IncrementalLedgerPersistenceException::class);
        $this->ledger->claimDueWork(self::PRODUCT_ID);
    }

    /**
     * @param array<string, string|null> $expected
     */
    private function assertRow(array $expected): void
    {
        $row = $this->row();

        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $row);
            self::assertSame($value, $row[$key] === null ? null : (string)$row[$key], $key);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $row = $this->connection->fetchRow(
            $this->connection->select()
                ->from($this->table)
                ->where('product_id = ?', self::PRODUCT_ID)
        );

        self::assertIsArray($row);

        return $row;
    }

    private function expireLease(): void
    {
        $this->connection->update(
            $this->table,
            ['lease_expires_at' => '2026-08-15 23:59:59'],
            ['product_id = ?' => self::PRODUCT_ID]
        );
    }

    private function cleanup(): void
    {
        $this->connection->delete($this->table, ['product_id IN (?)' => [self::PRODUCT_ID, self::PRODUCT_ID_TWO]]);
        $this->connection->delete($this->fenceTable, ['fence_id = ?' => DbRebuildFence::FENCE_ID]);
    }
}
