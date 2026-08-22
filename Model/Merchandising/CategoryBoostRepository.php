<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Merchandising;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\CategoryBoostInterface;
use Aavirbhava\AiShoppingAssistant\Api\Merchandising\CategoryBoostRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Model\CategoryBoost as CategoryBoostModel;
use Aavirbhava\AiShoppingAssistant\Model\CategoryBoostFactory;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\Exception\MerchandisingBoostException;
use Aavirbhava\AiShoppingAssistant\Model\ResourceModel\CategoryBoost as CategoryBoostResource;
use Magento\Framework\Exception\LocalizedException;

/**
 * The one save/load/delete path both the category edit form's own boost
 * field (Task 33) and the standalone category boost grid's inline
 * edit/delete actions go through — mirrors MerchandisingBoostRepository
 * exactly, with one addition (findByCategoryId()) that product boost's
 * own repository doesn't need — see CategoryBoostRepositoryInterface's
 * own docblock for why.
 */
final class CategoryBoostRepository implements CategoryBoostRepositoryInterface
{
    public function __construct(
        private readonly CategoryBoostFactory $modelFactory,
        private readonly CategoryBoostResource $resource
    ) {
    }

    public function save(CategoryBoostInterface $boost): CategoryBoostInterface
    {
        // Re-validates via the DTO's own constructor even though a caller
        // building a CategoryBoostRow already passed its checks — belt-
        // and-suspenders against a caller implementing the interface
        // directly rather than going through the DTO, matching
        // MerchandisingBoostRepository's own convention.
        $validated = new CategoryBoostRow(
            $boost->boostId(),
            $boost->categoryId(),
            $boost->boostWeight(),
            $boost->startDate(),
            $boost->endDate(),
            $boost->isActive()
        );

        $model = $validated->boostId() !== null
            ? $this->loadModel($validated->boostId())
            : $this->modelFactory->create();

        $model->setData('category_id', $validated->categoryId());
        $model->setData('boost_weight', $validated->boostWeight());
        $model->setData('start_date', $validated->startDate());
        $model->setData('end_date', $validated->endDate());
        $model->setData('is_active', $validated->isActive() ? 1 : 0);

        try {
            $this->resource->save($model);
        } catch (LocalizedException $exception) {
            throw new MerchandisingBoostException(__('The category boost could not be saved.'), $exception);
        }

        return $this->toRow($model);
    }

    public function getById(int $boostId): CategoryBoostInterface
    {
        return $this->toRow($this->loadModel($boostId));
    }

    public function findByCategoryId(int $categoryId): ?CategoryBoostInterface
    {
        if ($categoryId < 1) {
            return null;
        }

        /** @var CategoryBoostModel $model */
        $model = $this->modelFactory->create();
        $this->resource->load($model, $categoryId, 'category_id');

        return $model->getId() !== null ? $this->toRow($model) : null;
    }

    public function deleteById(int $boostId): void
    {
        $model = $this->modelFactory->create();
        $this->resource->load($model, $boostId);

        if ($model->getId() === null) {
            return;
        }

        try {
            $this->resource->delete($model);
        } catch (LocalizedException $exception) {
            throw new MerchandisingBoostException(__('The category boost could not be deleted.'), $exception);
        }
    }

    private function loadModel(int $boostId): CategoryBoostModel
    {
        $model = $this->modelFactory->create();
        $this->resource->load($model, $boostId);

        if ($model->getId() === null) {
            throw new MerchandisingBoostException(__('No category boost exists with id %1.', $boostId));
        }

        return $model;
    }

    private function toRow(CategoryBoostModel $model): CategoryBoostRow
    {
        return new CategoryBoostRow(
            $model->getId() !== null ? (int) $model->getId() : null,
            (int) $model->getData('category_id'),
            (float) $model->getData('boost_weight'),
            $model->getData('start_date') !== null ? (string) $model->getData('start_date') : null,
            $model->getData('end_date') !== null ? (string) $model->getData('end_date') : null,
            (bool) $model->getData('is_active'),
            $model->getData('created_at') !== null ? (string) $model->getData('created_at') : null,
            $model->getData('updated_at') !== null ? (string) $model->getData('updated_at') : null
        );
    }
}
