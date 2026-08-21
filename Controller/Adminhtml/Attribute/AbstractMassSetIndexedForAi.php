<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Attribute;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\AttributeIndexingSelectionRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * Shared by MassEnableForAi/MassDisableForAi — both differ only in the
 * boolean they persist, matching the same shared-logic-not-duplicated
 * discipline this module already uses for Boost\Save's own new-vs-
 * existing branches. The grid POSTs `attribute_id` (this grid's own
 * primary key, set via Grid::_prepareMassaction()'s
 * setMassactionIdField('attribute_id')) — resolved to the real
 * attribute CODE this repository is actually keyed by via the real EAV
 * attribute repository, one id-to-code lookup per selected row (cheap
 * at mass-action scale, never a bulk SQL trick that could silently skip
 * a row Magento's own attribute repository would have rejected as
 * invalid).
 */
abstract class AbstractMassSetIndexedForAi extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Catalog::attributes_attributes';

    public function __construct(
        Context $context,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly AttributeIndexingSelectionRepositoryInterface $repository
    ) {
        parent::__construct($context);
    }

    abstract protected function isIndexed(): bool;

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $attributeIds = $this->getRequest()->getParam('attribute_id');
        $attributeIds = is_array($attributeIds) ? $attributeIds : [];

        if ($attributeIds === []) {
            $this->messageManager->addErrorMessage(__('No attributes were selected.'));

            return $resultRedirect->setPath('catalog/product_attribute/');
        }

        $codes = [];
        $failed = [];
        foreach ($attributeIds as $attributeId) {
            try {
                $attribute = $this->attributeRepository->get(
                    ProductAttributeInterface::ENTITY_TYPE_CODE,
                    (string) $attributeId
                );
                $codes[] = (string) $attribute->getAttributeCode();
            } catch (LocalizedException $exception) {
                $failed[] = $attributeId;
            }
        }

        if ($codes !== []) {
            $this->repository->setIndexed($codes, $this->isIndexed());
            $this->messageManager->addSuccessMessage(
                __(
                    '%1 attribute(s) were updated. A full reindex '
                    . '(indexer:reindex or the Admin Playground) is '
                    . 'required for this to take effect in search results.',
                    count($codes)
                )
            );
        }

        if ($failed !== []) {
            $this->messageManager->addErrorMessage(
                __('Could not resolve attribute id(s): %1.', implode(', ', $failed))
            );
        }

        return $resultRedirect->setPath('catalog/product_attribute/');
    }
}
