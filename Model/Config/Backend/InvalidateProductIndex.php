<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;

/**
 * Invalidates the assistant product indexer after a content-affecting indexing
 * setting is saved successfully.
 *
 * Only content-affecting values trigger invalidation (searchable attribute
 * codes, description flags, variant aggregation, attribute value budget). The
 * indexing batch size never invalidates the index. No indexing or embedding
 * work ever happens during the config save itself. Encrypted API-key backend
 * models are untouched.
 *
 * Not final: Magento generates an interceptor for every class extending
 * Magento\Framework\App\Config\Value (platform cache invalidation plugins),
 * which requires a non-final class.
 */
class InvalidateProductIndex extends Value
{
    public const INDEXER_ID = 'ai_product_rag';

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly IndexerRegistry $indexerRegistry,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function afterSave()
    {
        $result = parent::afterSave();

        if ($this->isValueChanged()) {
            $this->invalidateIndex();
        }

        return $result;
    }

    private function invalidateIndex(): void
    {
        try {
            $this->indexerRegistry->get(self::INDEXER_ID)->invalidate();
        } catch (\Throwable $throwable) {
            throw new ConfigurationException(
                new Phrase('The AI shopping assistant index could not be invalidated. Recheck the module installation.'),
                $throwable instanceof \Exception ? $throwable : null
            );
        }
    }
}
