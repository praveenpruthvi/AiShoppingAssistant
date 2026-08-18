<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolNotFoundException;

/**
 * Registry of commerce tools contributed through Magento DI.
 *
 * The registry IS the runtime allowlist, mirroring
 * Api\Provider\LlmProviderRegistryInterface exactly: only tools
 * contributed by installed Magento modules through DI are resolvable,
 * and an unregistered name always fails closed with a sanitized
 * ToolNotFoundException rather than being resolved dynamically.
 */
interface CommerceToolRegistryInterface
{
    public function has(string $name): bool;

    /**
     * @throws ToolNotFoundException when the name is not registered
     */
    public function get(string $name): CommerceToolInterface;

    /**
     * @return array<string, CommerceToolInterface>
     */
    public function all(): array;
}
