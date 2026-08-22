<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Block\Adminhtml\CategoryBoost;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\CategoryBoostInterface;
use Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\CategoryBoost\Edit as EditController;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;

/**
 * Pure view for the category boost edit form — mirrors
 * Block\Adminhtml\Boost\Edit exactly, resolving the boosted category's
 * real name for display instead of a bare numeric id. category_id itself
 * is deliberately never an editable form field here — see
 * Controller\Adminhtml\CategoryBoost\Save's own docblock.
 */
class Edit extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly CategoryRepositoryInterface $categoryRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getError(): ?string
    {
        $error = $this->registry->registry(EditController::REGISTRY_KEY_ERROR);

        return is_string($error) ? $error : null;
    }

    public function getExistingBoost(): ?CategoryBoostInterface
    {
        $boost = $this->registry->registry(EditController::REGISTRY_KEY_BOOST);

        return $boost instanceof CategoryBoostInterface ? $boost : null;
    }

    public function getCategoryName(): string
    {
        $boost = $this->getExistingBoost();
        if ($boost === null) {
            return '';
        }

        try {
            return (string) $this->categoryRepository->get($boost->categoryId())->getName();
        } catch (NoSuchEntityException) {
            return (string) __('(category no longer exists)');
        }
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('aavirbhava_aishoppingassistant/categoryboost/save');
    }

    public function getGridUrl(): string
    {
        return $this->getUrl('aavirbhava_aishoppingassistant/categoryboost/index');
    }
}
