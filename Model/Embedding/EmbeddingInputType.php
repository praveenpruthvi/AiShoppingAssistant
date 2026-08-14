<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingInputTypeInterface;
use InvalidArgumentException;

/**
 * Immutable provider-neutral embedding input type.
 *
 * Only the documented document/query values are accepted; arbitrary strings are
 * rejected so that adapter request bodies can never be influenced by input.
 */
final readonly class EmbeddingInputType implements EmbeddingInputTypeInterface
{
    /**
     * @var list<string>
     */
    private const ALLOWED = [
        self::DOCUMENT,
        self::QUERY,
    ];

    private function __construct(
        private string $value
    ) {
    }

    public static function document(): self
    {
        return new self(self::DOCUMENT);
    }

    public static function query(): self
    {
        return new self(self::QUERY);
    }

    public static function fromValue(string $value): self
    {
        if (!in_array($value, self::ALLOWED, true)) {
            throw new InvalidArgumentException('Embedding input type must be document or query.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isDocument(): bool
    {
        return $this->value === self::DOCUMENT;
    }

    public function isQuery(): bool
    {
        return $this->value === self::QUERY;
    }
}
