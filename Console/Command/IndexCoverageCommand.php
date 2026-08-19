<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Console\Command;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Diagnostics\IndexCoverageChecker;
use Aavirbhava\AiShoppingAssistant\Model\Diagnostics\IndexCoverageReport;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `aavirbhava:ai-shopping-assistant:index-coverage` (Task 23): a fast
 * diagnostic comparing one store's real, salable/visible/enabled catalog
 * SKUs against the assistant's real OpenSearch index document count for
 * that store, and listing the specific SKUs on either side of the diff.
 *
 * Deliberately simple: no repair/reconciliation action of any kind — this
 * only reports what is currently true. A store whose index is unreachable
 * or was never built is reported as such, not treated as a fatal error, so
 * a multi-store run still reports every other store.
 *
 * Not final: Magento's DI compiler generates an interceptor for every
 * console command (matching every other Magento core command, none of
 * which are final either), and `setup:upgrade`/`setup:di:compile` fail
 * outright if it cannot extend this class.
 */
class IndexCoverageCommand extends Command
{
    private const OPTION_STORE_ID = 'store-id';

    /**
     * Caps how many individual SKUs this command prints per diff list — the
     * summary counts are always exact regardless of this cap; this only
     * bounds how much a very large diff floods the terminal.
     */
    private const MAX_LISTED_SKUS = 50;

    public function __construct(
        private readonly StoreScopeProviderInterface $storeScopeProvider,
        private readonly IndexCoverageChecker $checker
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('aavirbhava:ai-shopping-assistant:index-coverage')
            ->setDescription(
                'Compares real catalog product counts to the AI shopping assistant\'s OpenSearch index.'
            )
            ->addOption(
                self::OPTION_STORE_ID,
                null,
                InputOption::VALUE_REQUIRED,
                'Check only this store view id. Defaults to every active store view.'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scopes = $this->resolveScopes($input, $output);
        if ($scopes === null) {
            return Command::FAILURE;
        }

        $exitCode = Command::SUCCESS;

        foreach ($scopes as $scope) {
            $exitCode = max($exitCode, $this->checkOneStore($scope, $output));
        }

        return $exitCode;
    }

    /**
     * @return list<StoreScopeInterface>|null null when an explicitly given
     *     store id does not resolve to an active store
     */
    private function resolveScopes(InputInterface $input, OutputInterface $output): ?array
    {
        $storeId = $input->getOption(self::OPTION_STORE_ID);

        if ($storeId === null) {
            return $this->storeScopeProvider->activeStores();
        }

        if (!ctype_digit((string) $storeId)) {
            $output->writeln(sprintf('<error>--%s must be a positive integer.</error>', self::OPTION_STORE_ID));

            return null;
        }

        try {
            return [$this->storeScopeProvider->requireActive((int) $storeId)];
        } catch (\Throwable $exception) {
            $output->writeln(sprintf('<error>Store id %d is not an active store view.</error>', (int) $storeId));

            return null;
        }
    }

    private function checkOneStore(StoreScopeInterface $scope, OutputInterface $output): int
    {
        $output->writeln(sprintf(
            '<info>Store %d (%s)</info>',
            $scope->storeId(),
            $scope->storeCode()
        ));

        try {
            $report = $this->checker->check($scope);
        } catch (ProductIndexingException $exception) {
            $output->writeln(sprintf(
                '  <error>Index unreachable: %s (%s)</error>',
                $exception->getMessage(),
                $exception->errorCode()
            ));
            $output->writeln('');

            return Command::FAILURE;
        }

        $this->renderReport($report, $output);
        $output->writeln('');

        return $report->indexAvailable() && $report->isFullyCovered() ? Command::SUCCESS : Command::FAILURE;
    }

    private function renderReport(IndexCoverageReport $report, OutputInterface $output): void
    {
        if (!$report->indexAvailable()) {
            $output->writeln(sprintf(
                '  Catalog: %d salable/visible/enabled product(s).',
                $report->catalogCount
            ));
            $output->writeln('  <comment>No assistant index alias exists yet for this store — never indexed.</comment>');

            return;
        }

        $table = new Table($output);
        $table->setHeaders(['', 'Count'])
            ->addRow(['Real catalog (salable/visible/enabled)', $report->catalogCount])
            ->addRow(['Assistant OpenSearch index', $report->indexCount])
            ->addRow(['Missing from index', count($report->missingFromIndex)])
            ->addRow(['Indexed but not in real catalog', count($report->missingFromCatalog)]);
        $table->render();

        if ($report->isFullyCovered()) {
            $output->writeln('  <info>Fully covered — every real catalog SKU is indexed, no orphaned documents.</info>');

            return;
        }

        $this->renderSkuList($output, '  Missing from index (in catalog, not indexed):', $report->missingFromIndex);
        $this->renderSkuList(
            $output,
            '  Indexed but not in real catalog (orphaned documents):',
            $report->missingFromCatalog
        );
    }

    /**
     * @param list<string> $skus
     */
    private function renderSkuList(OutputInterface $output, string $label, array $skus): void
    {
        if ($skus === []) {
            return;
        }

        $output->writeln($label);
        foreach (array_slice($skus, 0, self::MAX_LISTED_SKUS) as $sku) {
            $output->writeln('    - ' . $sku);
        }

        $remaining = count($skus) - self::MAX_LISTED_SKUS;
        if ($remaining > 0) {
            $output->writeln(sprintf('    ... and %d more.', $remaining));
        }
    }
}
