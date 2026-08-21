<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog\AttributeGrid;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\AttributeIndexingSelectionRepositoryInterface;
use Magento\Backend\Block\Context;
use Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer;
use Magento\Framework\DataObject;

/**
 * Renders the "Indexed for AI Assistant" grid column by reading the same
 * shared AttributeIndexingSelectionRepositoryInterface both admin entry
 * points and the indexing pipeline read/write — never a second, separate
 * data source. `Column::getRenderer()` caches and reuses one renderer
 * instance across every row of one grid render (confirmed by reading the
 * real core class before writing this), so `all()` is fetched once per
 * page load here, not once per row.
 */
final class IndexedForAiColumnRenderer extends AbstractRenderer
{
    private ?array $selection = null;

    public function __construct(
        Context $context,
        private readonly AttributeIndexingSelectionRepositoryInterface $attributeIndexingSelectionRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(DataObject $row)
    {
        $code = (string) $row->getData('attribute_code');

        return $this->selection()[$code] ?? false ? __('Yes') : __('No');
    }

    /**
     * @return array<string, bool>
     */
    private function selection(): array
    {
        if ($this->selection === null) {
            $this->selection = $this->attributeIndexingSelectionRepository->all();
        }

        return $this->selection;
    }
}
