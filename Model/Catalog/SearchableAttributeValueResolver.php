<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductAttributePolicyInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\SearchableAttributeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\SearchableAttributeValueResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute as EavAttribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\DataObject;

final class SearchableAttributeValueResolver implements SearchableAttributeValueResolverInterface
{
    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly ProductAttributePolicyInterface $attributePolicy
    ) {
    }

    public function resolve(
        StoreScopeInterface $scope,
        IndexingConfigInterface $config,
        ProductInterface $product
    ): array {
        $codes = $config->searchableAttributeCodes();

        if ($codes === []) {
            return [];
        }

        $budget = $config->maxAttributeValuesPerProduct();

        $resolved = [];
        $usedValues = 0;

        foreach ($codes as $code) {
            if (!$this->attributePolicy->isAllowed($code)) {
                continue;
            }

            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);

            if (!$attribute instanceof EavAttribute) {
                continue;
            }

            $label = $this->attributeLabel($attribute, $scope->storeId());

            if ($label === '') {
                continue;
            }

            $values = $this->attributeValues($attribute, $scope, $product, $code);

            if ($values === []) {
                continue;
            }

            $remaining = $budget - $usedValues;
            if ($remaining <= 0) {
                break;
            }

            $values = array_slice($values, 0, $remaining);
            $usedValues += count($values);

            $resolved[] = new SearchableAttribute($code, $label, $values);
        }

        usort(
            $resolved,
            static fn (SearchableAttributeInterface $a, SearchableAttributeInterface $b): int => $a->code() <=> $b->code()
        );

        return $resolved;
    }

    private function attributeLabel(EavAttribute $attribute, int $storeId): string
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

    /**
     * @return list<string>
     */
    private function attributeValues(
        EavAttribute $attribute,
        StoreScopeInterface $scope,
        ProductInterface $product,
        string $code
    ): array {
        if (!$attribute->usesSource()) {
            return $this->scalarValues($product, $code);
        }

        $raw = $product->getData($code);

        if (is_array($raw)) {
            $optionIds = array_map('strval', array_filter($raw, 'is_numeric'));
        } else {
            $optionIds = preg_split('/\s*,\s*/', trim((string) $raw));
            $optionIds = array_filter($optionIds, static fn ($v): bool => $v !== '' && is_numeric($v));
            $optionIds = array_map('strval', $optionIds);
        }

        if ($optionIds === []) {
            return [];
        }

        $labelsByOption = $this->optionLabelsByStore($attribute, $scope->storeId());

        $labels = [];
        foreach ($optionIds as $optionId) {
            $label = $labelsByOption[$optionId] ?? null;
            if ($label !== null && $label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function scalarValues(ProductInterface $product, string $code): array
    {
        if (!$product instanceof DataObject) {
            return [];
        }

        $value = $product->getData($code);

        if ($value === null || $value === '') {
            return [];
        }

        if (is_bool($value)) {
            return [$value ? '1' : '0'];
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $entry) {
                $parts[] = is_scalar($entry) ? (string) $entry : '';
            }
        } else {
            $parts = preg_split('/\s*,\s*/', trim((string) $value)) ?: [];
        }

        $parts = array_values(array_filter($parts, static fn ($p): bool => $p !== ''));

        return $parts === [] ? [] : array_values(array_unique($parts));
    }

    /**
     * @return array<int, string> option id => label
     */
    private function optionLabelsByStore(EavAttribute $attribute, int $storeId): array
    {
        $cloned = clone $attribute;
        $cloned->setData('store_id', $storeId);

        $labels = [];
        foreach ($cloned->getSource()->getAllOptions(false) as $option) {
            $value = $option['value'] ?? null;
            $label = $option['label'] ?? null;

            if (is_numeric($value) && is_string($label) && $label !== '') {
                $labels[(string) (int) $value] = $label;
            }
        }

        return $labels;
    }
}