<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingResultInterface;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingDimensionException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingResponseException;
use Magento\Framework\Phrase;

/**
 * Server-side verification of an embedding result before it is returned.
 *
 * Rejects unknown, missing, duplicated, or reordered input identifiers and any
 * vector whose dimension differs from the configured value. This is the final
 * correlation and shape gate between an adapter result and the rest of the
 * pipeline.
 *
 * @param list<string> $expectedIdentifiers
 */
final class EmbeddingResultValidator
{
    public function validate(EmbeddingResultInterface $result, array $expectedIdentifiers, int $expectedDimensions): void
    {
        if ($result->inputIdentifiers() !== $expectedIdentifiers) {
            throw new EmbeddingResponseException(
                new Phrase('The embedding provider returned vectors that do not match the requested inputs.')
            );
        }

        if (count($result->vectors()) !== count($expectedIdentifiers)) {
            throw new EmbeddingResponseException(
                new Phrase('The embedding provider returned an unexpected number of vectors.')
            );
        }

        foreach ($result->vectors() as $vector) {
            if ($vector->dimension() !== $expectedDimensions) {
                throw new EmbeddingDimensionException(
                    new Phrase('The embedding provider returned vectors with an unexpected dimension.')
                );
            }
        }
    }
}