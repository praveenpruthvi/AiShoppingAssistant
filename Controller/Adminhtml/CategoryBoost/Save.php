<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\CategoryBoost;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\CategoryBoostRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\CategoryBoostRow;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * Saves an EXISTING category boost row (identified by `boost_id`) from
 * the standalone grid's own edit form — see Controller\Adminhtml\
 * CategoryBoost\Edit's own docblock for why this entry point never
 * creates a new boost. category_id is not resubmitted/changeable from
 * this form (kept read-only, matching Block\Adminhtml\CategoryBoost\Edit's
 * own display-only treatment of it) — it comes from the already-loaded
 * existing boost row instead, never trusted from the request body.
 */
class Save extends Action implements HttpPostActionInterface
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
        $request = $this->getRequest();

        $boostId = (int) $request->getParam('boost_id');

        if ($boostId < 1) {
            $this->messageManager->addErrorMessage(__('No category boost was specified.'));

            return $resultRedirect->setPath('*/*/index');
        }

        try {
            $existing = $this->repository->getById($boostId);
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());

            return $resultRedirect->setPath('*/*/index');
        }

        $boostWeight = (float) $request->getParam('boost_weight', 0);
        $startDate = $this->nullableDate((string) $request->getParam('start_date', ''));
        $endDate = $this->nullableDate((string) $request->getParam('end_date', ''));
        $isActive = (bool) $request->getParam('is_active', false);

        try {
            $this->repository->save(
                new CategoryBoostRow($boostId, $existing->categoryId(), $boostWeight, $startDate, $endDate, $isActive)
            );
            $this->messageManager->addSuccessMessage(__('The category boost was saved.'));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());

            return $resultRedirect->setPath('*/*/edit', ['boost_id' => $boostId]);
        }

        return $resultRedirect->setPath('*/*/index');
    }

    private function nullableDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // The form's date inputs submit `Y-m-d` only; normalize to a full
        // MySQL datetime so start/end comparisons in CategoryBoostRow and
        // ActiveCategoryBoostReader's own SQL stay consistent in format —
        // mirrors Controller\Adminhtml\Boost\Save's own identical helper,
        // including its `!` prefix fix for a real PHP createFromFormat()
        // gotcha — see that file's own comment for the full explanation.
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($parsed === false) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
        }

        return $parsed !== false ? $parsed->format('Y-m-d H:i:s') : null;
    }
}
