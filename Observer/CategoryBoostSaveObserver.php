<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Observer;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\CategoryBoostInterface;
use Aavirbhava\AiShoppingAssistant\Api\Merchandising\CategoryBoostRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\CategoryBoostRow;
use Magento\Catalog\Model\Category;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Persists the category edit form's own boost field (Task 33's Entry
 * Point A, view/adminhtml/ui_component/category_form.xml) into
 * CategoryBoostRepositoryInterface. Wired to the real, core
 * `catalog_category_save_after` event, NOT `catalog_category_prepare_save`
 * — deliberately: `catalog_category_prepare_save` fires BEFORE
 * $category->save() for a genuinely NEW category, so its entity id is
 * not yet populated at that point (confirmed by reading
 * Controller\Adminhtml\Category\Save.php directly); `_save_after`
 * (AbstractModel::afterSave(), confirmed directly in
 * vendor/magento/framework/Model/AbstractModel.php) fires only once the
 * entity id is guaranteed real and populated, for both new and existing
 * categories alike — the correct point for a save that has a foreign key
 * dependency on the category's own id.
 *
 * Upserts against CategoryBoostRepositoryInterface::findByCategoryId()
 * (see that method's own docblock for why this repository, unlike
 * MerchandisingBoostRepository, needs a find-by-owning-entity lookup at
 * all) rather than always creating a new row — this entry point is a
 * field ON the category's own form, not a "create a new boost" link, so
 * saving the category again must update the SAME boost row, never
 * accumulate duplicates.
 *
 * A weight of 0 (or the field simply not submitted, e.g. the boost
 * fieldset was left untouched) deactivates rather than deletes an
 * existing boost — preserves its configured dates/history, matching
 * is_active's own documented purpose (CategoryBoostRow's docblock);
 * deleting outright is still available from the standalone grid
 * (Entry Point B) if a merchant genuinely wants the row gone.
 */
final class CategoryBoostSaveObserver implements ObserverInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CategoryBoostRepositoryInterface $repository,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $category = $observer->getEvent()->getCategory();

        if (!$category instanceof Category || !$category->getId()) {
            return;
        }

        $rawWeight = $this->request->getParam('aavirbhava_category_boost_weight');

        if ($rawWeight === null || $rawWeight === '') {
            return;
        }

        $categoryId = (int) $category->getId();
        $boostWeight = (float) $rawWeight;
        $existing = $this->repository->findByCategoryId($categoryId);

        if ($boostWeight <= 0.0) {
            $this->deactivateIfNeeded($categoryId, $existing);

            return;
        }

        $startDate = $this->nullableDate((string) $this->request->getParam('aavirbhava_category_boost_start_date', ''));
        $endDate = $this->nullableDate((string) $this->request->getParam('aavirbhava_category_boost_end_date', ''));

        try {
            $this->repository->save(new CategoryBoostRow(
                $existing?->boostId(),
                $categoryId,
                $boostWeight,
                $startDate,
                $endDate,
                true
            ));
        } catch (LocalizedException $exception) {
            // The category itself already saved successfully by this
            // point — a boost-sub-field validation failure must never
            // surface as a crashed category save. Logged and shown as a
            // non-blocking admin warning instead.
            $this->logger->error('AI shopping assistant: category boost could not be saved.', [
                'category_id' => $categoryId,
                'exception' => $exception->getMessage(),
            ]);
            $this->messageManager->addWarningMessage(
                __('The category was saved, but its AI Assistant boost could not be: %1', $exception->getMessage())
            );
        }
    }

    private function deactivateIfNeeded(int $categoryId, ?CategoryBoostInterface $existing): void
    {
        if ($existing === null || !$existing->isActive()) {
            return;
        }

        try {
            $this->repository->save(new CategoryBoostRow(
                $existing->boostId(),
                $categoryId,
                $existing->boostWeight(),
                $existing->startDate(),
                $existing->endDate(),
                false
            ));
        } catch (LocalizedException $exception) {
            $this->logger->error('AI shopping assistant: category boost could not be deactivated.', [
                'category_id' => $categoryId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function nullableDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // The `!` prefix is required — see Controller\Adminhtml\Boost\Save's
        // own comment for the real PHP createFromFormat() gotcha this
        // avoids (unspecified fields otherwise default to the current
        // wall-clock time, not midnight).
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($parsed === false) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
        }

        return $parsed !== false ? $parsed->format('Y-m-d H:i:s') : null;
    }
}
