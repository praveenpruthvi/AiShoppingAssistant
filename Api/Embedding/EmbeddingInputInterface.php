<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Embedding;

/**
 * A single normalized text with a deterministic identifier.
 *
 * The identifier is assigned by the caller before a batch is embedded so that
 * returned vectors can be correlated back to inputs without trusting model
 * output ordering. It must be stable for a given input position.
 */
interface EmbeddingInputInterface
{
    public function text(): string;

    public function identifier(): string;
}
