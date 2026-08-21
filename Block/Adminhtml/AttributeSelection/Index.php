<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Block\Adminhtml\AttributeSelection;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\AttributeIndexingSelectionRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductAttributePolicyInterface;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the checkbox list for the bulk-select screen. Eligible
 * attributes are filtered to `is_user_defined = 1` — confirmed against
 * this store's real EAV attribute table before relying on it (see this
 * task's own status report): every `is_user_defined = 0` attribute on
 * `catalog_product` is a genuine Magento-core/system field (name, sku,
 * price, status, images, meta fields, dates, ...), never a real
 * merchant-facing product-fact attribute this screen would need to
 * offer — and additionally filtered through
 * ProductAttributePolicyInterface::isAllowed() so a merchant is never
 * even shown a denylisted code as if selecting it would do anything.
 */
class Index extends Template
{
    public function __construct(
        Context $context,
        private readonly AttributeCollectionFactory $attributeCollectionFactory,
        private readonly AttributeIndexingSelectionRepositoryInterface $repository,
        private readonly ProductAttributePolicyInterface $attributePolicy,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return list<array{code: string, label: string, isIndexed: bool}>
     */
    public function getEligibleAttributes(): array
    {
        $selection = $this->repository->all();
        $storeId = (int) $this->storeManager->getStore()->getId();

        $collection = $this->attributeCollectionFactory->create();
        $collection->addFieldToFilter('is_user_defined', 1);
        $collection->addFieldToFilter('frontend_input', ['neq' => 'gallery']);
        $collection->setOrder('frontend_label', 'ASC');

        $attributes = [];
        foreach ($collection as $attribute) {
            $code = (string) $attribute->getAttributeCode();

            if (!$this->attributePolicy->isAllowed($code)) {
                continue;
            }

            $label = $this->attributeLabel($attribute, $storeId);
            if ($label === '') {
                continue;
            }

            $attributes[] = [
                'code' => $code,
                'label' => $label,
                'isIndexed' => $selection[$code] ?? false,
            ];
        }

        return $attributes;
    }

    /**
     * @return list<string>
     */
    public function getAllEligibleCodesCsv(): string
    {
        return implode(',', array_column($this->getEligibleAttributes(), 'code'));
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('aavirbhava_aishoppingassistant/attributeselection/save');
    }

    private function attributeLabel(mixed $attribute, int $storeId): string
    {
        $labels = $attribute->getStoreLabels();
        if (is_array($labels) && isset($labels[$storeId])) {
            $label = trim((string) $labels[$storeId]);
            if ($label !== '') {
                return $label;
            }
        }

        return trim((string) $attribute->getData('frontend_label'));
    }
}
