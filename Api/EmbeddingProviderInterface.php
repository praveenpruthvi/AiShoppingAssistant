<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingRequestInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingResultInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;

/**
 * Embedding provider adapter boundary.
 *
 * Adapters are stateless between requests. Every call receives a fully
 * validated, store-scoped EmbeddingRequest carrying the resolved model, base
 * URL, API key, timeout, and expected dimensions. Implementations must never
 * retain config, secrets, or raw responses, and must never perform network
 * requests during construction.
 */
interface EmbeddingProviderInterface
{
    public function identifier(): string;

    public function embed(EmbeddingRequestInterface $request): EmbeddingResultInterface;

    /**
     * Nominal dimensions when statically known; returns 0 when dimensions are
     * model-dependent and therefore validated per request against config.
     */
    public function dimensions(): int;

    public function fingerprint(): string;

    public function capabilities(): ProviderCapabilities;
}
