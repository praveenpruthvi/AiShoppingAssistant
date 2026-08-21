<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Dto;

use InvalidArgumentException;

final readonly class ToolCall
{
    /**
     * @param array<string, mixed> $arguments
     * @param string|null $providerMetadata Opaque, provider-specific data that
     *     must be echoed back verbatim on a later turn for that same
     *     provider to keep working correctly — e.g. Gemini's
     *     `thoughtSignature`, required on a replayed `functionCall` part for
     *     its "thinking" model family (confirmed live: omitting it on a
     *     multi-round tool call fails with a real 400 "missing
     *     thought_signature" error). Every other provider leaves this null
     *     and ignores it entirely; never interpret or transform it.
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public ?string $providerMetadata = null
    ) {
        if ($id === '' || $name === '') {
            throw new InvalidArgumentException('Tool-call ID and name must not be empty.');
        }
    }
}
