<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Console\Command;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Console\Command\IndexCoverageCommand;
use Aavirbhava\AiShoppingAssistant\Model\Diagnostics\IndexCoverageChecker;
use Aavirbhava\AiShoppingAssistant\Model\Diagnostics\IndexCoverageReport;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(IndexCoverageCommand::class)]
final class IndexCoverageCommandTest extends TestCase
{
    private function scope(int $storeId, string $code): StoreScopeInterface
    {
        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn($storeId);
        $scope->method('storeCode')->willReturn($code);

        return $scope;
    }

    public function testReportsFullCoverageAcrossEveryActiveStoreByDefault(): void
    {
        $scope = $this->scope(1, 'default');

        $storeScopeProvider = $this->createMock(StoreScopeProviderInterface::class);
        $storeScopeProvider->method('activeStores')->willReturn([$scope]);

        $checker = $this->createMock(IndexCoverageChecker::class);
        $checker->method('check')->with($scope)
            ->willReturn(new IndexCoverageReport(1, 'default', 5, 5, [], []));

        $tester = new CommandTester(new IndexCoverageCommand($storeScopeProvider, $checker));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Fully covered', $tester->getDisplay());
    }

    public function testListsSpecificSkusMissingFromTheIndex(): void
    {
        $scope = $this->scope(1, 'default');

        $storeScopeProvider = $this->createMock(StoreScopeProviderInterface::class);
        $storeScopeProvider->method('activeStores')->willReturn([$scope]);

        $checker = $this->createMock(IndexCoverageChecker::class);
        $checker->method('check')->willReturn(
            new IndexCoverageReport(1, 'default', 3, 2, ['MISSING-SKU'], [])
        );

        $tester = new CommandTester(new IndexCoverageCommand($storeScopeProvider, $checker));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('MISSING-SKU', $tester->getDisplay());
    }

    public function testListsOrphanedIndexDocumentsNotInTheRealCatalog(): void
    {
        $scope = $this->scope(1, 'default');

        $storeScopeProvider = $this->createMock(StoreScopeProviderInterface::class);
        $storeScopeProvider->method('activeStores')->willReturn([$scope]);

        $checker = $this->createMock(IndexCoverageChecker::class);
        $checker->method('check')->willReturn(
            new IndexCoverageReport(1, 'default', 2, 3, [], ['STALE-SKU'])
        );

        $tester = new CommandTester(new IndexCoverageCommand($storeScopeProvider, $checker));
        $tester->execute([]);

        self::assertStringContainsString('STALE-SKU', $tester->getDisplay());
        self::assertStringContainsString('orphaned', $tester->getDisplay());
    }

    public function testReportsNeverIndexedWithoutTreatingItAsAFatalError(): void
    {
        $scope = $this->scope(1, 'default');

        $storeScopeProvider = $this->createMock(StoreScopeProviderInterface::class);
        $storeScopeProvider->method('activeStores')->willReturn([$scope]);

        $checker = $this->createMock(IndexCoverageChecker::class);
        $checker->method('check')->willReturn(new IndexCoverageReport(1, 'default', 7, null, [], []));

        $tester = new CommandTester(new IndexCoverageCommand($storeScopeProvider, $checker));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('never indexed', $tester->getDisplay());
    }

    public function testAnUnreachableIndexIsReportedPerStoreRatherThanAbortingTheWholeRun(): void
    {
        $failingScope = $this->scope(1, 'default');
        $healthyScope = $this->scope(2, 'second_store');

        $storeScopeProvider = $this->createMock(StoreScopeProviderInterface::class);
        $storeScopeProvider->method('activeStores')->willReturn([$failingScope, $healthyScope]);

        $checker = $this->createMock(IndexCoverageChecker::class);
        $checker->method('check')->willReturnCallback(
            function (StoreScopeInterface $scope) use ($failingScope) {
                if ($scope === $failingScope) {
                    throw new ProductIndexingException(
                        ProductIndexingException::ERROR_OPENSEARCH_BACKEND_UNAVAILABLE,
                        new Phrase('The assistant search backend is unavailable.')
                    );
                }

                return new IndexCoverageReport(2, 'second_store', 4, 4, [], []);
            }
        );

        $tester = new CommandTester(new IndexCoverageCommand($storeScopeProvider, $checker));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Index unreachable', $tester->getDisplay());
        self::assertStringContainsString('Fully covered', $tester->getDisplay());
    }

    public function testStoreIdOptionChecksOnlyThatStore(): void
    {
        $scope = $this->scope(3, 'third_store');

        $storeScopeProvider = $this->createMock(StoreScopeProviderInterface::class);
        $storeScopeProvider->expects(self::never())->method('activeStores');
        $storeScopeProvider->method('requireActive')->with(3)->willReturn($scope);

        $checker = $this->createMock(IndexCoverageChecker::class);
        $checker->method('check')->with($scope)->willReturn(new IndexCoverageReport(3, 'third_store', 1, 1, [], []));

        $tester = new CommandTester(new IndexCoverageCommand($storeScopeProvider, $checker));
        $exitCode = $tester->execute(['--store-id' => '3']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('third_store', $tester->getDisplay());
    }

    public function testRejectsANonNumericStoreIdOption(): void
    {
        $storeScopeProvider = $this->createMock(StoreScopeProviderInterface::class);
        $checker = $this->createMock(IndexCoverageChecker::class);
        $checker->expects(self::never())->method('check');

        $tester = new CommandTester(new IndexCoverageCommand($storeScopeProvider, $checker));
        $exitCode = $tester->execute(['--store-id' => 'not-a-number']);

        self::assertSame(Command::FAILURE, $exitCode);
    }
}
