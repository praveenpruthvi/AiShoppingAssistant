<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingInputInterface;
use InvalidArgumentException;

/**
 * Immutable normalized text with a deterministic identifier.
 *
 * The text must be non-empty after trimming. The identifier is assigned by the
 * caller and never derived from customer input or model output.
 */
final readonly class EmbeddingInput implements EmbeddingInputInterface
{
    public function __construct(
        private string $text,
        private string $identifier
    ) {
        if (trim($text) === '') {
            throw new InvalidArgumentException('Embedding input text must not be empty.');
        }

        if ($identifier === '') {
            throw new InvalidArgumentException('Embedding input identifier must not be empty.');
        }
    }

    public function text(): string
    {
        return $this->text;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }
}