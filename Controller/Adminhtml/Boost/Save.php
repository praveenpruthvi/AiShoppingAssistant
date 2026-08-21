<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Boost;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\MerchandisingBoostRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\MerchandisingBoostRow;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * Saves one boost (editing an existing row, identified by `boost_id`) or
 * creates one new boost row per product id in `product_ids` (the bulk
 * "Add to AI Assistant Boost" mass-action flow) — both paths go through
 * the exact same MerchandisingBoostRepositoryInterface::save() call, one
 * row at a time, so a partial failure part-way through a bulk save still
 * leaves every already-saved row correctly persisted rather than rolling
 * back silently.
 */
class Save extends Action implements HttpPostActionInterface
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
        $request = $this->getRequest();

        $boostWeight = (float) $request->getParam('boost_weight', 0);
        $startDate = $this->nullableDate((string) $request->getParam('start_date', ''));
        $endDate = $this->nullableDate((string) $request->getParam('end_date', ''));
        $isActive = (bool) $request->getParam('is_active', false);

        $existingBoostId = $request->getParam('boost_id');
        $productIds = $this->parseProductIds((string) $request->getParam('product_ids', ''));

        if ($existingBoostId !== null && $existingBoostId !== '') {
            return $this->saveExisting(
                (int) $existingBoostId,
                $productIds[0] ?? null,
                $boostWeight,
                $startDate,
                $endDate,
                $isActive,
                $resultRedirect
            );
        }

        return $this->saveNew($productIds, $boostWeight, $startDate, $endDate, $isActive, $resultRedirect);
    }

    private function saveExisting(
        int $boostId,
        ?int $productId,
        float $boostWeight,
        ?string $startDate,
        ?string $endDate,
        bool $isActive,
        Redirect $resultRedirect
    ): Redirect {
        if ($productId === null) {
            $this->messageManager->addErrorMessage(__('No product was associated with this boost.'));

            return $resultRedirect->setPath('*/*/index');
        }

        try {
            $this->repository->save(
                new MerchandisingBoostRow($boostId, $productId, $boostWeight, $startDate, $endDate, $isActive)
            );
            $this->messageManager->addSuccessMessage(__('The merchandising boost was saved.'));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());

            return $resultRedirect->setPath('*/*/edit', ['boost_id' => $boostId]);
        }

        return $resultRedirect->setPath('*/*/index');
    }

    /**
     * @param list<int> $productIds
     */
    private function saveNew(
        array $productIds,
        float $boostWeight,
        ?string $startDate,
        ?string $endDate,
        bool $isActive,
        Redirect $resultRedirect
    ): Redirect {
        if ($productIds === []) {
            $this->messageManager->addErrorMessage(__('No products were selected.'));

            return $resultRedirect->setPath('*/*/index');
        }

        $saved = 0;
        $failed = [];

        foreach ($productIds as $productId) {
            try {
                $this->repository->save(
                    new MerchandisingBoostRow(null, $productId, $boostWeight, $startDate, $endDate, $isActive)
                );
                $saved++;
            } catch (LocalizedException $exception) {
                $failed[] = $productId;
            }
        }

        if ($saved > 0) {
            $this->messageManager->addSuccessMessage(
                __('%1 merchandising boost(s) were saved.', $saved)
            );
        }

        if ($failed !== []) {
            $this->messageManager->addErrorMessage(
                __('Could not save a boost for product id(s): %1.', implode(', ', $failed))
            );
        }

        return $resultRedirect->setPath('*/*/index');
    }

    /**
     * @return list<int>
     */
    private function parseProductIds(string $raw): array
    {
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && is_numeric($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    private function nullableDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // The form's date inputs submit `Y-m-d` only; normalize to a full
        // MySQL datetime so start/end comparisons in MerchandisingBoostRow
        // and ActiveBoostReader's own SQL stay consistent in format.
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($parsed === false) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
        }

        return $parsed !== false ? $parsed->format('Y-m-d H:i:s') : null;
    }
}
