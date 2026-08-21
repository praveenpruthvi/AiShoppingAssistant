<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog\AttributeGrid;

use Magento\Catalog\Block\Adminhtml\Product\Attribute\Grid as CoreAttributeGrid;

/**
 * ENTRY POINT A — extends the real, native Stores > Attributes > Product
 * grid with an "Indexed for AI Assistant" column and a 2-item mass
 * action, wired in by swapping this class in for the core one via a
 * `<preference>` (see etc/adminhtml/di.xml) rather than a layout
 * `<referenceBlock>` override: `Magento\Backend\Block\Widget\Grid\
 * Container::_prepareLayout()` creates this grid block directly in PHP
 * with an auto-generated (unstable, unaddressable) layout name — there
 * is no reliable `<referenceBlock name="...">` anchor to hook, confirmed
 * by reading `Container::_prepareLayout()`/`Controller\Adminhtml\
 * Product\Attribute\Index::execute()` before choosing this approach. A
 * `<preference>` on a concrete class (not just an interface) is valid,
 * real Magento behavior — `Layout::createBlock()` resolves through the
 * ObjectManager, which honors preferences for any requested type string.
 *
 * This grid is a legacy `Backend\Block\Widget\Grid\Extended` (Prototype-
 * based), not a Ui Component — confirmed by reading the real core class
 * before writing this, not assumed. The new column reads is_indexed via
 * a custom renderer (one `AttributeIndexingSelectionRepositoryInterface
 * ::all()` call per grid render, not a per-row query — cheap at the
 * scale of a real attribute set) rather than a SQL join into the core
 * collection's own internal query-building, which this class
 * deliberately does not touch.
 */
class Grid extends CoreAttributeGrid
{
    protected function _prepareColumns()
    {
        parent::_prepareColumns();

        $this->addColumnAfter(
            'indexed_for_ai',
            [
                'header' => __('Indexed for AI Assistant'),
                'index' => 'attribute_code',
                'sortable' => false,
                'filter' => false,
                'align' => 'center',
                'renderer' => IndexedForAiColumnRenderer::class,
            ],
            'is_comparable'
        );

        return $this;
    }

    protected function _prepareMassaction()
    {
        parent::_prepareMassaction();

        $this->setMassactionIdField('attribute_id');
        $this->getMassactionBlock()->setFormFieldName('attribute');

        $this->getMassactionBlock()->addItem(
            'aavirbhava_enable_for_ai',
            [
                'label' => __('Enable for AI Assistant Indexing'),
                'url' => $this->getUrl('aavirbhava_aishoppingassistant/attribute/massEnableForAi'),
            ]
        );

        $this->getMassactionBlock()->addItem(
            'aavirbhava_disable_for_ai',
            [
                'label' => __('Disable for AI Assistant Indexing'),
                'url' => $this->getUrl('aavirbhava_aishoppingassistant/attribute/massDisableForAi'),
            ]
        );

        return $this;
    }
}
