<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Boost;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\MerchandisingBoostRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * Deletes a single boost row from the standalone grid — goes through the
 * same MerchandisingBoostRepositoryInterface the save flow uses.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AiShoppingAssistant::boost';

    public function __construct(
        Context $context,
        private readonly MerchandisingBoostRepositoryInterface $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $boostId = (int) $this->getRequest()->getParam('boost_id');

        if ($boostId < 1) {
            $this->messageManager->addErrorMessage(__('No boost was specified.'));

            return $resultRedirect->setPath('*/*/index');
        }

        try {
            $this->repository->deleteById($boostId);
            $this->messageManager->addSuccessMessage(__('The merchandising boost was deleted.'));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        return $resultRedirect->setPath('*/*/index');
    }
}
