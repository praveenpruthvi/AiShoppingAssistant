<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductNormalizationResultInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

final readonly class ProductNormalizationResult implements ProductNormalizationResultInterface
{
    public function __construct(
        private bool $eligible,
        private string $reasonCode,
        private ?ProductDocumentInterface $document
    ) {
        if (!in_array($reasonCode, ProductEligibilityResult::validReasonCodes(), true)) {
            throw new CatalogException(__('Unknown product eligibility reason code "%1".', $reasonCode));
        }

        $consistentEligibility = $reasonCode === ProductEligibilityResultInterface::REASON_ELIGIBLE;

        if ($eligible !== $consistentEligibility) {
            throw new CatalogException(__('Inconsistent product normalization result.'));
        }

        if ($eligible !== ($document !== null)) {
            throw new CatalogException(__('Inconsistent product normalization result.'));
        }
    }

    public function eligible(): bool
    {
        return $this->eligible;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    public function document(): ?ProductDocumentInterface
    {
        return $this->document;
    }
}
