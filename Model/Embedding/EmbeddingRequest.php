<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingInputInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingInputTypeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingRequestInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use InvalidArgumentException;

/**
 * Immutable, fully validated, store-scoped embedding batch request.
 *
 * Carries the resolved config snapshot (model, base URL, API key, timeout,
 * expected dimensions) for a single store. The API key is carried as a
 * SecretValue and is never exposed through string conversion or JSON.
 */
final readonly class EmbeddingRequest implements EmbeddingRequestInterface
{
    public const MIN_TIMEOUT_SECONDS = 1;
    public const MAX_TIMEOUT_SECONDS = 300;
    public const MIN_DIMENSIONS = 1;
    public const MAX_DIMENSIONS = 16384;

    /**
     * @param list<EmbeddingInputInterface> $inputs
     */
    public function __construct(
        private int $storeId,
        private EmbeddingInputTypeInterface $inputType,
        private array $inputs,
        private string $model,
        private string $baseUrl,
        private SecretValue $apiKey,
        private int $timeoutSeconds,
        private int $dimensions
    ) {
        if ($storeId < 1) {
            throw new InvalidArgumentException('Embedding requests require an active store id.');
        }

        if ($inputs === []) {
            throw new InvalidArgumentException('Embedding requests require at least one input.');
        }

        foreach ($inputs as $input) {
            if (!$input instanceof EmbeddingInputInterface) {
                throw new InvalidArgumentException('Embedding request inputs must implement EmbeddingInputInterface.');
            }
        }

        if ($model === '') {
            throw new InvalidArgumentException('Embedding request model must not be empty.');
        }

        if ($timeoutSeconds < self::MIN_TIMEOUT_SECONDS || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException('Embedding request timeout is outside the supported range.');
        }

        if ($dimensions < self::MIN_DIMENSIONS || $dimensions > self::MAX_DIMENSIONS) {
            throw new InvalidArgumentException('Embedding request dimensions are outside the supported range.');
        }
    }

    public function storeId(): int
    {
        return $this->storeId;
    }

    public function inputType(): EmbeddingInputTypeInterface
    {
        return $this->inputType;
    }

    public function inputs(): array
    {
        return $this->inputs;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function apiKey(): SecretValue
    {
        return $this->apiKey;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }
}