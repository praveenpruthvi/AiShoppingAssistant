<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\AttributeIndexingSelectionRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductSnapshotProvider;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

/**
 * Proves the real, load-bearing requirement of this task end to end
 * against real data: toggling one real attribute's selection in
 * AttributeIndexingSelectionRepositoryInterface genuinely changes what
 * ProductSnapshotProvider — the actual entry point the indexing pipeline
 * calls — includes for a real product, both by way of
 * ConfigurationReaderInterface::readIndexing() (the pipeline's own
 * config seam) and SearchableAttributeValueResolver underneath it. A
 * real product/attribute pair already present in this store (SKU
 * MP01-32-Black, attribute "color") is used rather than a synthetic
 * fixture — this is genuinely "did the selection change reach real
 * indexing output," not a mock proving the wiring exists.
 *
 * Restores whatever this store's real "color" selection state was
 * before the test in tearDown() — this attribute is part of this
 * store's real, live-relied-upon seeded selection (see
 * SeedAttributeIndexingSelection), so this test must never leave it
 * toggled off after itself.
 */
final class AttributeSelectionAffectsIndexingPipelineTest extends TestCase
{
    private const TEST_SKU = 'MP01-32-Black';
    private const TEST_ATTRIBUTE_CODE = 'color';

    private AttributeIndexingSelectionRepositoryInterface $repository;
    private ConfigurationReaderInterface $configurationReader;
    private ProductSnapshotProvider $snapshotProvider;
    private StoreScopeProviderInterface $storeScopeProvider;
    private int $productEntityId;
    private bool $originalColorSelection;

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

        $this->repository = $objectManager->get(AttributeIndexingSelectionRepositoryInterface::class);
        $this->configurationReader = $objectManager->get(ConfigurationReaderInterface::class);
        $this->snapshotProvider = $objectManager->get(ProductSnapshotProvider::class);
        $this->storeScopeProvider = $objectManager->get(StoreScopeProviderInterface::class);

        $this->originalColorSelection = $this->repository->isIndexed(self::TEST_ATTRIBUTE_CODE);

        $resource = $objectManager->get(\Magento\Framework\App\ResourceConnection::class);
        $entityId = $resource->getConnection()->fetchOne(
            $resource->getConnection()->select()
                ->from($resource->getTableName('catalog_product_entity'), ['entity_id'])
                ->where('sku = ?', self::TEST_SKU)
                ->limit(1)
        );

        if ($entityId === false) {
            self::markTestSkipped('Real product ' . self::TEST_SKU . ' is not present in this store.');
        }

        $this->productEntityId = (int) $entityId;
    }

    protected function tearDown(): void
    {
        $this->repository->setIndexed([self::TEST_ATTRIBUTE_CODE], $this->originalColorSelection);
    }

    public function testAttributeIsIncludedInTheRealPipelineWhenSelected(): void
    {
        $this->repository->setIndexed([self::TEST_ATTRIBUTE_CODE], true);

        $codes = $this->resolveRealAttributeCodesForTestProduct();

        self::assertContains(self::TEST_ATTRIBUTE_CODE, $codes);
    }

    public function testAttributeIsExcludedFromTheRealPipelineWhenDeselected(): void
    {
        $this->repository->setIndexed([self::TEST_ATTRIBUTE_CODE], false);

        $codes = $this->resolveRealAttributeCodesForTestProduct();

        self::assertNotContains(self::TEST_ATTRIBUTE_CODE, $codes);
    }

    public function testConfigurationReaderReadIndexingReflectsTheLiveSelectionWithNoCacheOrReindexNeeded(): void
    {
        $this->repository->setIndexed([self::TEST_ATTRIBUTE_CODE], false);
        self::assertNotContains(
            self::TEST_ATTRIBUTE_CODE,
            $this->configurationReader->readIndexing(1)->searchableAttributeCodes()
        );

        $this->repository->setIndexed([self::TEST_ATTRIBUTE_CODE], true);
        self::assertContains(
            self::TEST_ATTRIBUTE_CODE,
            $this->configurationReader->readIndexing(1)->searchableAttributeCodes()
        );
    }

    /**
     * @return list<string>
     */
    private function resolveRealAttributeCodesForTestProduct(): array
    {
        $scope = $this->storeScopeProvider->requireActive(1);
        $config = $this->configurationReader->readIndexing(1);

        $batch = $this->snapshotProvider->load($scope, $config, [$this->productEntityId]);

        $codes = [];
        foreach ($batch->snapshots() as $snapshot) {
            if ($snapshot->entityId() === $this->productEntityId) {
                foreach ($snapshot->attributes() as $attribute) {
                    $codes[] = $attribute->code();
                }
            }
        }

        return $codes;
    }
}
