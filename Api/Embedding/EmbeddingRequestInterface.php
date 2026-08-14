<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;

/**
 * A fully validated, store-scoped embedding batch ready to send to a provider.
 *
 * The request carries the store context, the input type, the resolved text
 * batch with deterministic identifiers, and the config snapshot (model, base
 * URL, API key, timeout, expected dimensions) resolved for that store. Adapters
 * treat this object as immutable and never retain it between requests.
 */
interface EmbeddingRequestInterface
{
    public function storeId(): int;

    public function inputType(): EmbeddingInputTypeInterface;

    /**
     * @return list<EmbeddingInputInterface>
     */
    public function inputs(): array;

    public function model(): string;

    public function baseUrl(): string;

    public function apiKey(): SecretValue;

    public function timeoutSeconds(): int;

    public function dimensions(): int;
}
