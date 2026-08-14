<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

/**
 * Immutable outcome of normalizing a product snapshot.
 */
interface ProductNormalizationResultInterface
{
    /**
     * True when the snapshot was eligible and a document was produced.
     */
    public function eligible(): bool;

    /**
     * Reason code from ProductEligibilityResultInterface.
     *
     * @throws CatalogException
     */
    public function reasonCode(): string;

    /**
     * The produced document, or null when ineligible.
     */
    public function document(): ?ProductDocumentInterface;
}
