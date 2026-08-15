<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Provider;

use Aavirbhava\AiShoppingAssistant\Api\EmbeddingProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;

interface EmbeddingProviderRegistryInterface
{
    public function has(string $identifier): bool;

    public function get(string $identifier): EmbeddingProviderInterface;

    /**
     * @return array<string, EmbeddingProviderInterface>
     */
    public function all(): array;

    public function capabilities(string $identifier): ProviderCapabilities;
}
