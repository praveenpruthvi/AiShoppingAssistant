<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Provider;

use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;

interface LlmProviderRegistryInterface
{
    public function has(string $identifier): bool;

    public function get(string $identifier): LlmProviderInterface;

    /**
     * @return array<string, LlmProviderInterface>
     */
    public function all(): array;

    public function capabilities(string $identifier): ProviderCapabilities;
}