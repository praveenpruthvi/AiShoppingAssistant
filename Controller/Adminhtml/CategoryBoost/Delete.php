<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\CategoryBoost;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\CategoryBoostRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * Deletes a single category boost row from the standalone grid — goes
 * through the same CategoryBoostRepositoryInterface the save flow uses,
 * mirrors Controller\Adminhtml\Boost\Delete exactly.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AiShoppingAssistant::category_boost';

    public function __construct(
        Context $context,
        private readonly CategoryBoostRepositoryInterface $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $boostId = (int) $this->getRequest()->getParam('boost_id');

        if ($boostId < 1) {
            $this->messageManager->addErrorMessage(__('No category boost was specified.'));

            return $resultRedirect->setPath('*/*/index');
        }

        try {
            $this->repository->deleteById($boostId);
            $this->messageManager->addSuccessMessage(__('The category boost was deleted.'));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        return $resultRedirect->setPath('*/*/index');
    }
}
