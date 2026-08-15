<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Fake;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingGenerationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingInputTypeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingVectorInterface;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingResult;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingUsage;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingVector;

/**
 * Deterministic in-memory embedding generator for tests.
 *
 * Every text is embedded into a fixed-dimension vector derived from its
 * characters, so vectors are stable and correlation is trivial to assert.
 */
final class FakeEmbeddingGenerationService implements EmbeddingGenerationServiceInterface
{
    public const DIMENSION = 4;

    public bool $lastInputWasDocument = false;

    /**
     * Override to make the service return vectors of a different dimension.
     */
    public int $vectorDimension = self::DIMENSION;

    /**
     * @var list<array{storeId: int, inputType: EmbeddingInputTypeInterface, texts: list<string>}>
     */
    public array $calls = [];

    /**
     * @var array<int, \Throwable>
     */
    private array $failures = [];

    /**
     * @param list<string> $texts
     */
    public function embed(int $storeId, EmbeddingInputTypeInterface $inputType, array $texts): EmbeddingResultInterface
    {
        if (isset($this->failures[$storeId])) {
            throw $this->failures[$storeId];
        }

        $this->calls[] = ['storeId' => $storeId, 'inputType' => $inputType, 'texts' => $texts];
        $this->lastInputWasDocument = $inputType->isDocument();

        $vectors = [];
        foreach ($texts as $text) {
            $vectors[] = new EmbeddingVector($this->vectorFor($text), $this->vectorDimension);
        }

        return new EmbeddingResult($vectors, array_map('strval', array_keys($texts)), 'fake-model', new EmbeddingUsage(0, 0));
    }

    public function failOn(int $storeId, \Throwable $throwable = null): void
    {
        $this->failures[$storeId] = $throwable ?? new \RuntimeException('fake embedding failure');
    }

    /**
     * @return list<float>
     */
    public function vectorFor(string $text): array
    {
        $vector = [];
        for ($i = 0; $i < $this->vectorDimension; $i++) {
            $vector[] = (float) (($i + 1) * (strlen($text) + 1) + 0.5);
        }

        return $vector;
    }

    public function vector(): EmbeddingVectorInterface
    {
        return new EmbeddingVector($this->vectorFor('sample'), self::DIMENSION);
    }
}