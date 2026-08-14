<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityResultInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

final readonly class ProductEligibilityResult implements ProductEligibilityResultInterface
{
    public function __construct(private string $reasonCode)
    {
        if (!in_array($reasonCode, self::validReasonCodes(), true)) {
            throw new CatalogException(__('Unknown product eligibility reason code "%1".', $reasonCode));
        }
    }

    public static function validReasonCodes(): array
    {
        return [
            self::REASON_ELIGIBLE,
            self::REASON_INVALID_IDENTITY,
            self::REASON_STORE_MISMATCH,
            self::REASON_WEBSITE_NOT_ASSIGNED,
            self::REASON_DISABLED,
            self::REASON_NOT_SEARCH_VISIBLE,
        ];
    }

    public function eligible(): bool
    {
        return $this->reasonCode === self::REASON_ELIGIBLE;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
