<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Merchandising;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\MerchandisingBoostInterface;
use Aavirbhava\AiShoppingAssistant\Api\Merchandising\MerchandisingBoostRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Model\MerchandisingBoost as MerchandisingBoostModel;
use Aavirbhava\AiShoppingAssistant\Model\MerchandisingBoostFactory;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\Exception\MerchandisingBoostException;
use Aavirbhava\AiShoppingAssistant\Model\ResourceModel\MerchandisingBoost as MerchandisingBoostResource;
use Magento\Framework\Exception\LocalizedException;

/**
 * The one save/load/delete path both the product-grid mass-action save
 * flow and the standalone boost grid's inline edit/delete actions go
 * through — see MerchandisingBoostRepositoryInterface for why this is
 * deliberately the single such path.
 *
 * Internally uses Magento's standard AbstractModel/AbstractDb pair (the
 * same one the admin grid's Collection reads), translating to/from the
 * immutable MerchandisingBoostRow DTO at this class's own boundary so no
 * other caller in this module ever needs to know the mutable ORM row
 * exists.
 */
final class MerchandisingBoostRepository implements MerchandisingBoostRepositoryInterface
{
    public function __construct(
        private readonly MerchandisingBoostFactory $modelFactory,
        private readonly MerchandisingBoostResource $resource
    ) {
    }

    public function save(MerchandisingBoostInterface $boost): MerchandisingBoostInterface
    {
        // Re-validates via the DTO's own constructor even though a caller
        // building a MerchandisingBoostRow already passed its checks —
        // belt-and-suspenders against a caller implementing the interface
        // directly rather than going through the DTO, matching this
        // module's existing redundant-validation convention.
        $validated = new MerchandisingBoostRow(
            $boost->boostId(),
            $boost->productId(),
            $boost->boostWeight(),
            $boost->startDate(),
            $boost->endDate(),
            $boost->isActive()
        );

        $model = $validated->boostId() !== null
            ? $this->loadModel($validated->boostId())
            : $this->modelFactory->create();

        $model->setData('product_id', $validated->productId());
        $model->setData('boost_weight', $validated->boostWeight());
        $model->setData('start_date', $validated->startDate());
        $model->setData('end_date', $validated->endDate());
        $model->setData('is_active', $validated->isActive() ? 1 : 0);

        try {
            $this->resource->save($model);
        } catch (LocalizedException $exception) {
            throw new MerchandisingBoostException(__('The merchandising boost could not be saved.'), $exception);
        }

        return $this->toRow($model);
    }

    public function getById(int $boostId): MerchandisingBoostInterface
    {
        return $this->toRow($this->loadModel($boostId));
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
            throw new MerchandisingBoostException(__('The merchandising boost could not be deleted.'), $exception);
        }
    }

    private function loadModel(int $boostId): MerchandisingBoostModel
    {
        $model = $this->modelFactory->create();
        $this->resource->load($model, $boostId);

        if ($model->getId() === null) {
            throw new MerchandisingBoostException(__('No merchandising boost exists with id %1.', $boostId));
        }

        return $model;
    }

    private function toRow(MerchandisingBoostModel $model): MerchandisingBoostRow
    {
        return new MerchandisingBoostRow(
            $model->getId() !== null ? (int) $model->getId() : null,
            (int) $model->getData('product_id'),
            (float) $model->getData('boost_weight'),
            $model->getData('start_date') !== null ? (string) $model->getData('start_date') : null,
            $model->getData('end_date') !== null ? (string) $model->getData('end_date') : null,
            (bool) $model->getData('is_active'),
            $model->getData('created_at') !== null ? (string) $model->getData('created_at') : null,
            $model->getData('updated_at') !== null ? (string) $model->getData('updated_at') : null
        );
    }
}
