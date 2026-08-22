<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Plugin\Catalog\DataProvider;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\CategoryBoostRepositoryInterface;
use Magento\Catalog\Model\Category\DataProvider;

/**
 * Supplies the boost fields' saved values into the category edit form
 * (view/adminhtml/ui_component/category_form.xml's own new fieldset).
 *
 * A plugin on the concrete Magento\Catalog\Model\Category\DataProvider
 * class — NOT the product form's usual "register a data Modifier"
 * technique — because category's DataProvider is a genuinely different,
 * simpler base than product's: its getData() (read directly, confirmed
 * in vendor/magento/module-catalog/Model/Category/DataProvider.php)
 * hard-overrides the parent AbstractDataProvider::getData() entirely,
 * sourcing data purely from the category entity's own getData() and
 * NEVER invoking any modifier's modifyData() at all — the standard
 * product-form injection pattern silently does nothing here. A plugin on
 * this class's own getData() is the same real, core-precedented
 * mechanism Magento_CatalogUrlRewrite itself already uses for its own
 * category-form field (Plugin\Catalog\Block\Adminhtml\Category\Tab\
 * Attributes, wired via a <plugin> on this exact class in that module's
 * own etc/adminhtml/di.xml — confirmed by reading that module directly,
 * not assumed).
 */
class CategoryBoostDataProviderPlugin
{
    public function __construct(
        private readonly CategoryBoostRepositoryInterface $repository
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $result
     *
     * @return array<int, array<string, mixed>>
     */
    public function afterGetData(DataProvider $subject, array $result): array
    {
        $category = $subject->getCurrentCategory();

        if (!$category || !$category->getId()) {
            return $result;
        }

        $categoryId = (int) $category->getId();

        if (!isset($result[$categoryId])) {
            return $result;
        }

        $boost = $this->repository->findByCategoryId($categoryId);

        $result[$categoryId]['aavirbhava_category_boost_weight'] = $boost !== null ? $boost->boostWeight() : null;
        $result[$categoryId]['aavirbhava_category_boost_start_date'] = $boost?->startDate() !== null
            ? substr($boost->startDate(), 0, 10)
            : null;
        $result[$categoryId]['aavirbhava_category_boost_end_date'] = $boost?->endDate() !== null
            ? substr($boost->endDate(), 0, 10)
            : null;

        return $result;
    }
}
