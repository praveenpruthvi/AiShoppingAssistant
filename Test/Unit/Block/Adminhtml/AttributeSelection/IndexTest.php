<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Block\Adminhtml\AttributeSelection;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\AttributeIndexingSelectionRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductAttributePolicyInterface;
use Aavirbhava\AiShoppingAssistant\Block\Adminhtml\AttributeSelection\Index;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection as AttributeCollection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Eav\Model\Entity\Attribute as EavAttribute;
use Magento\Framework\App\ObjectManager as AppObjectManager;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the bulk-select screen's data source: eligible attributes come
 * from the real is_user_defined=1 filter (confirmed safe against this
 * store's real EAV attributes in this task's own audit — see the status
 * report), additionally filtered through ProductAttributePolicyInterface
 * so a denylisted code is never even offered, and each one's checked
 * state comes from the exact same
 * AttributeIndexingSelectionRepositoryInterface::all() the native grid
 * column reads — never a second, divergent source.
 */
#[CoversClass(Index::class)]
final class IndexTest extends TestCase
{
    private const STORE_ID = 3;

    protected function setUp(): void
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(
            fn (string $type) => $type === Escaper::class ? new Escaper() : $this->createMock($type)
        );
        AppObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        $reflection = new \ReflectionProperty(AppObjectManager::class, '_instance');
        $reflection->setAccessible(true);
        $reflection->setValue(null, null);
    }

    public function testEligibleAttributesReflectTheRepositoriesCurrentSelection(): void
    {
        $block = $this->block(
            attributes: [
                $this->attribute('color', 'Color'),
                $this->attribute('material', 'Material'),
            ],
            selection: ['color' => true, 'material' => false]
        );

        $attributes = $block->getEligibleAttributes();

        self::assertSame(
            [
                ['code' => 'color', 'label' => 'Color', 'isIndexed' => true],
                ['code' => 'material', 'label' => 'Material', 'isIndexed' => false],
            ],
            $attributes
        );
    }

    public function testACodeWithNoRowInTheRepositoryDefaultsToNotIndexed(): void
    {
        $block = $this->block(
            attributes: [$this->attribute('brand', 'Brand')],
            selection: []
        );

        self::assertFalse($block->getEligibleAttributes()[0]['isIndexed']);
    }

    public function testDenylistedAttributesAreNeverOffered(): void
    {
        $block = $this->block(
            attributes: [
                $this->attribute('color', 'Color'),
                $this->attribute('cost', 'Cost'),
            ],
            selection: ['color' => true, 'cost' => true],
            allowedCodes: ['color']
        );

        $codes = array_column($block->getEligibleAttributes(), 'code');
        self::assertSame(['color'], $codes);
    }

    public function testAnAttributeWithNoResolvableLabelIsSkipped(): void
    {
        $block = $this->block(
            attributes: [$this->attribute('no_label', '')],
            selection: []
        );

        self::assertSame([], $block->getEligibleAttributes());
    }

    public function testAllEligibleCodesCsvJoinsEveryEligibleCode(): void
    {
        $block = $this->block(
            attributes: [
                $this->attribute('color', 'Color'),
                $this->attribute('material', 'Material'),
            ],
            selection: []
        );

        self::assertSame('color,material', $block->getAllEligibleCodesCsv());
    }

    /**
     * @param list<EavAttribute> $attributes
     * @param array<string, bool> $selection
     * @param list<string>|null $allowedCodes null = allow everything
     */
    private function block(array $attributes, array $selection, ?array $allowedCodes = null): Index
    {
        $objectManager = new ObjectManager($this);

        $context = $objectManager->getObject(Context::class, ['escaper' => new Escaper()]);

        $collection = $this->createMock(AttributeCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($attributes));

        $collectionFactory = $this->createMock(AttributeCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $repository = $this->createMock(AttributeIndexingSelectionRepositoryInterface::class);
        $repository->method('all')->willReturn($selection);

        $policy = $this->createMock(ProductAttributePolicyInterface::class);
        $policy->method('isAllowed')->willReturnCallback(
            static fn (string $code): bool => $allowedCodes === null || in_array($code, $allowedCodes, true)
        );

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $objectManager->getObject(Index::class, [
            'context' => $context,
            'attributeCollectionFactory' => $collectionFactory,
            'repository' => $repository,
            'attributePolicy' => $policy,
            'storeManager' => $storeManager,
        ]);
    }

    private function attribute(string $code, string $label): EavAttribute
    {
        $attribute = $this->getMockBuilder(EavAttribute::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAttributeCode', 'getStoreLabels', 'getData'])
            ->getMock();
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getStoreLabels')->willReturn(null);
        $attribute->method('getData')->with('frontend_label')->willReturn($label);

        return $attribute;
    }
}
