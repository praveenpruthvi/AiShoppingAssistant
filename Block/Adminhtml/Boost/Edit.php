<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Block\Adminhtml\Boost;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\MerchandisingBoostInterface;
use Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Boost\Edit as EditController;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;

/**
 * Pure view for the boost edit/create form — reads whatever
 * Controller\Adminhtml\Boost\Edit registered (a list of product ids to
 * boost, or an existing MerchandisingBoostInterface row to edit) and
 * resolves each product id's real name/SKU for display, so an admin isn't
 * asked to configure a boost against a bare numeric id.
 */
class Edit extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly ProductRepositoryInterface $productRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getError(): ?string
    {
        $error = $this->registry->registry(EditController::REGISTRY_KEY_ERROR);

        return is_string($error) ? $error : null;
    }

    public function getExistingBoost(): ?MerchandisingBoostInterface
    {
        $boost = $this->registry->registry(EditController::REGISTRY_KEY_BOOST);

        return $boost instanceof MerchandisingBoostInterface ? $boost : null;
    }

    /**
     * @return list<int>
     */
    public function getProductIds(): array
    {
        $ids = $this->registry->registry(EditController::REGISTRY_KEY_PRODUCT_IDS);

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /**
     * @return list<array{id: int, sku: string, name: string}>
     */
    public function getProducts(): array
    {
        $products = [];
        foreach ($this->getProductIds() as $productId) {
            try {
                $product = $this->productRepository->getById($productId);
                $products[] = ['id' => $productId, 'sku' => $product->getSku(), 'name' => (string) $product->getName()];
            } catch (NoSuchEntityException) {
                $products[] = ['id' => $productId, 'sku' => '', 'name' => (string) __('(product no longer exists)')];
            }
        }

        return $products;
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('aavirbhava_aishoppingassistant/boost/save');
    }

    public function getGridUrl(): string
    {
        return $this->getUrl('aavirbhava_aishoppingassistant/boost/index');
    }
}
