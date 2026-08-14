<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\SearchableAttributeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

final readonly class SearchableAttribute implements SearchableAttributeInterface
{
    public const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param list<string> $values
     */
    public function __construct(
        private string $code,
        private string $label,
        private array $values
    ) {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new CatalogException(__('Invalid attribute code "%1".', $code));
        }

        if ($label === '') {
            throw new CatalogException(__('Attribute label must not be empty.'));
        }

        if ($values === []) {
            throw new CatalogException(__('Attribute "%1" must have at least one value.', $code));
        }

        foreach ($values as $value) {
            if ($value === '') {
                throw new CatalogException(__('Attribute "%1" values must not be empty.', $code));
            }
        }
    }

    public function code(): string
    {
        return $this->code;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function values(): array
    {
        return $this->values;
    }
}
