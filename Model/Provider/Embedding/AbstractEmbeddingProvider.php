<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingInputInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingRequestInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingUsageInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingVectorInterface;
use Aavirbhava\AiShoppingAssistant\Api\EmbeddingProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingResult;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingUsage;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingVector;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingDimensionException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingInputException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingRateLimitException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\ProviderHttpTransport;
use Magento\Framework\Phrase;

/**
 * Shared embedding adapter pipeline.
 *
 * Subclasses define the provider endpoint, request body, headers, and whether
 * an API key is mandatory. This base class owns the HTTP call, status mapping,
 * response decoding, index/order correlation, vector and usage validation, and
 * result construction. Adapters are stateless between requests and never retain
 * config, secrets, or raw responses.
 */
abstract class AbstractEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly ProviderHttpTransport $transport,
        private readonly ProviderEndpointPolicy $endpointPolicy
    ) {
    }

    public function embed(EmbeddingRequestInterface $request): EmbeddingResultInterface
    {
        $this->assertValidRequest($request);

        $endpoint = $this->endpointPolicy->embeddingsEndpoint(
            $this->identifier(),
            $request->baseUrl(),
            $this->defaultBaseUrl()
        );

        $response = $this->transport->post(
            $endpoint,
            $this->buildHeaders($request),
            $this->encodeRequestBody($this->buildRequestBody($request)),
            (float) $request->timeoutSeconds()
        );

        $this->assertSuccessStatus($response->statusCode());

        return $this->parseResponse($response->body(), $request);
    }

    abstract protected function defaultBaseUrl(): string;

    abstract protected function apiKeyRequired(): bool;

    /**
     * @return array<string, string>
     */
    abstract protected function buildHeaders(EmbeddingRequestInterface $request): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function buildRequestBody(EmbeddingRequestInterface $request): array;

    private function assertValidRequest(EmbeddingRequestInterface $request): void
    {
        if ($this->apiKeyRequired() && $request->apiKey()->isEmpty()) {
            throw new EmbeddingConfigurationException(
                new Phrase('The API key is not configured for the embedding provider.')
            );
        }

        if ($request->model() === '') {
            throw new EmbeddingConfigurationException(
                new Phrase('The embedding model is not configured.')
            );
        }

        if ($request->dimensions() < 1) {
            throw new EmbeddingConfigurationException(
                new Phrase('The embedding dimensions are not configured.')
            );
        }

        if ($request->inputs() === []) {
            throw new EmbeddingInputException(
                new Phrase('The embedding request contains no text to embed.')
            );
        }
    }

    private function assertSuccessStatus(int $statusCode): void
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        if (in_array($statusCode, [401, 403], true)) {
            throw new EmbeddingAuthenticationException(
                new Phrase('The embedding provider rejected the request.')
            );
        }

        if ($statusCode === 429) {
            throw new EmbeddingRateLimitException(
                new Phrase('The embedding provider is temporarily limiting requests.')
            );
        }

        if (in_array($statusCode, [408, 504], true)) {
            throw new EmbeddingTimeoutException(
                new Phrase('The embedding provider request timed out.')
            );
        }

        if ($statusCode >= 500) {
            throw new EmbeddingUnavailableException(
                new Phrase('The embedding provider is temporarily unavailable.')
            );
        }

        throw new EmbeddingResponseException(
            new Phrase('The embedding provider returned an unexpected response.')
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function encodeRequestBody(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $cause) {
            throw new EmbeddingInputException(
                new Phrase('The embedding request could not be prepared.'),
                $cause
            );
        }
    }

    private function parseResponse(string $responseBody, EmbeddingRequestInterface $request): EmbeddingResultInterface
    {
        try {
            $payload = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $cause) {
            throw new EmbeddingResponseException(
                new Phrase('The embedding provider returned an invalid response.'),
                $cause
            );
        }

        if (!is_array($payload)) {
            throw new EmbeddingResponseException(
                new Phrase('The embedding provider returned an invalid response.')
            );
        }

        $vectors = $this->parseVectors($payload, $request);

        $inputIdentifiers = array_map(
            static fn (EmbeddingInputInterface $input): string => $input->identifier(),
            $request->inputs()
        );

        return new EmbeddingResult(
            $vectors,
            $inputIdentifiers,
            $this->resolveModel($payload, $request),
            $this->parseUsage($payload)
        );
    }

    /**
     * Correlates returned vectors back to inputs by index.
     *
     * The response must contain exactly one distinct index for every input
     * position (0..n-1). Missing, duplicate, unknown, or malformed indexes are
     * rejected; a complete distinct permutation is safely restored to input
     * order before any vector is accepted.
     *
     * @param array<mixed> $payload
     *
     * @return list<EmbeddingVectorInterface>
     */
    private function parseVectors(array $payload, EmbeddingRequestInterface $request): array
    {
        $data = $payload['data'] ?? null;

        if (!is_array($data)) {
            throw new EmbeddingResponseException(
                new Phrase('The embedding provider response is missing vectors.')
            );
        }

        $inputCount = count($request->inputs());

        $byIndex = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                throw new EmbeddingResponseException(
                    new Phrase('The embedding provider response is invalid.')
                );
            }

            $index = $item['index'] ?? null;

            if (!is_int($index) && !(is_string($index) && preg_match('/^\d+$/', $index) === 1)) {
                throw new EmbeddingResponseException(
                    new Phrase('The embedding provider response is invalid.')
                );
            }

            $index = (int) $index;

            if ($index < 0 || $index >= $inputCount) {
                throw new EmbeddingResponseException(
                    new Phrase('The embedding provider response is invalid.')
                );
            }

            if (array_key_exists($index, $byIndex)) {
                throw new EmbeddingResponseException(
                    new Phrase('The embedding provider response contains duplicate vectors.')
                );
            }

            $embedding = $item['embedding'] ?? null;

            if (!is_array($embedding)) {
                throw new EmbeddingResponseException(
                    new Phrase('The embedding provider response is missing a vector.')
                );
            }

            $byIndex[$index] = $embedding;
        }

        if (count($byIndex) !== $inputCount) {
            throw new EmbeddingResponseException(
                new Phrase('The embedding provider response is missing vectors.')
            );
        }

        ksort($byIndex);

        $vectors = [];
        foreach ($byIndex as $embedding) {
            $vectors[] = $this->buildVector($embedding, $request->dimensions());
        }

        return $vectors;
    }

    /**
     * @param array<mixed> $embedding
     */
    private function buildVector(array $embedding, int $expectedDimensions): EmbeddingVectorInterface
    {
        if (count($embedding) !== $expectedDimensions) {
            throw new EmbeddingDimensionException(
                new Phrase('The embedding provider returned vectors with an unexpected dimension.')
            );
        }

        foreach ($embedding as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new EmbeddingResponseException(
                    new Phrase('The embedding provider returned invalid vectors.')
                );
            }
        }

        return new EmbeddingVector(
            array_map('floatval', array_values($embedding)),
            $expectedDimensions
        );
    }

    /**
     * @param array<mixed> $payload
     */
    private function resolveModel(array $payload, EmbeddingRequestInterface $request): string
    {
        $model = $payload['model'] ?? null;

        if (is_string($model) && $model !== '') {
            return $model;
        }

        return $request->model();
    }

    /**
     * @param array<mixed> $payload
     */
    private function parseUsage(array $payload): EmbeddingUsageInterface
    {
        $usage = $payload['usage'] ?? [];

        if (!is_array($usage)) {
            return new EmbeddingUsage(0, 0);
        }

        $promptTokens = $this->usageTokenCount($usage['prompt_tokens'] ?? null);
        $totalTokens = $this->usageTokenCount($usage['total_tokens'] ?? null);

        $inputTokens = $promptTokens ?? $totalTokens ?? 0;
        $resolvedTotal = $totalTokens ?? $inputTokens;

        return new EmbeddingUsage($inputTokens, $resolvedTotal);
    }

    private function usageTokenCount(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}