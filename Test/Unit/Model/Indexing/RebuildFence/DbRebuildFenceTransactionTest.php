<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\RebuildFence;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildFence\DbRebuildFence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DbRebuildFence::class)]
final class DbRebuildFenceTransactionTest extends TestCase
{
    public function testAcquireUsesRowLockingAndExactOwnerTokenPredicates(): void
    {
        $source = (string)file_get_contents($this->fencePath());

        self::assertStringContainsString('beginTransaction()', $source);
        self::assertStringContainsString('->forUpdate(true)', $source);
        self::assertStringContainsString("'fence_id = ?' => self::FENCE_ID", $source);
        self::assertStringContainsString('\'owner_token = ?\' => $ownerToken', $source);
        self::assertStringContainsString('rollBack()', $source);
        self::assertStringContainsString('new RebuildFenceException()', $source);
    }

    private function fencePath(): string
    {
        $reflection = new \ReflectionClass(DbRebuildFence::class);
        $path = $reflection->getFileName();
        self::assertIsString($path);

        return $path;
    }
}
