<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\DbIncrementalWorkLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DbIncrementalWorkLedger::class)]
final class DbIncrementalWorkLedgerTransactionTest extends TestCase
{
    public function testFinalizeAndExpiredLeaseDecisionsUseRowLockingTransactions(): void
    {
        $source = (string)file_get_contents($this->ledgerPath());

        self::assertGreaterThanOrEqual(3, substr_count($source, 'beginTransaction()'));
        self::assertGreaterThanOrEqual(4, substr_count($source, '->forUpdate(true)'));
        self::assertGreaterThanOrEqual(2, substr_count($source, "'generation = ?' => (int)\$row['generation']"));
        self::assertGreaterThanOrEqual(2, substr_count($source, 'commit()'));
        self::assertGreaterThanOrEqual(2, substr_count($source, 'rollBack()'));
        self::assertStringContainsString('new IncrementalLedgerPersistenceException()', $source);
    }

    public function testClaimsLockRebuildFenceBeforeProductRow(): void
    {
        $source = (string)file_get_contents($this->ledgerPath());
        $claimStart = strpos($source, 'public function claimDueWork');
        $fenceCheck = strpos($source, '$this->rebuildFenceActive($connection)', (int)$claimStart);
        $productLock = strpos($source, "->from(\$this->table())", (int)$fenceCheck);

        self::assertIsInt($claimStart);
        self::assertIsInt($fenceCheck);
        self::assertIsInt($productLock);
        self::assertLessThan($productLock, $fenceCheck);
    }

    private function ledgerPath(): string
    {
        $reflection = new \ReflectionClass(DbIncrementalWorkLedger::class);
        $path = $reflection->getFileName();
        self::assertIsString($path);

        return $path;
    }
}
