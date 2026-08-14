<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingInputException;
use Magento\Framework\Phrase;

/**
 * Validates a raw text batch before it is embedded.
 *
 * Normalizes each text (trim), rejects empty-after-trim and oversized inputs
 * (never silently truncates), enforces batch-level bounds, and rejects invalid
 * UTF-8. Assigns deterministic positional identifiers ("0", "1", ...) so results
 * can be correlated back to inputs.
 */
final class EmbeddingInputValidator
{
    public const MAX_TEXTS_PER_REQUEST = 100;
    public const MAX_TEXT_BYTES = 8000;
    public const MAX_TOTAL_BYTES = 200000;

    /**
     * @param list<string> $texts
     *
     * @return list<EmbeddingInput>
     */
    public function validate(array $texts): array
    {
        $count = count($texts);

        if ($count < 1) {
            throw new EmbeddingInputException(
                new Phrase('Provide at least one text to embed.')
            );
        }

        if ($count > self::MAX_TEXTS_PER_REQUEST) {
            throw new EmbeddingInputException(
                new Phrase('The number of texts to embed exceeds the supported limit.')
            );
        }

        $inputs = [];
        $totalBytes = 0;

        foreach ($texts as $index => $text) {
            if (!is_string($text)) {
                throw new EmbeddingInputException(
                    new Phrase('One of the texts to embed is not valid.')
                );
            }

            $trimmed = trim($text);

            if ($trimmed === '') {
                throw new EmbeddingInputException(
                    new Phrase('One of the texts to embed is empty.')
                );
            }

            if (preg_match('//u', $trimmed) !== 1) {
                throw new EmbeddingInputException(
                    new Phrase('One of the texts to embed is not valid UTF-8.')
                );
            }

            if (strlen($trimmed) > self::MAX_TEXT_BYTES) {
                throw new EmbeddingInputException(
                    new Phrase('One of the texts to embed is too long.')
                );
            }

            $totalBytes += strlen($trimmed);

            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                throw new EmbeddingInputException(
                    new Phrase('The combined texts to embed are too long.')
                );
            }

            $inputs[] = new EmbeddingInput($trimmed, (string) $index);
        }

        return $inputs;
    }
}