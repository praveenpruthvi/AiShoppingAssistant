<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Dto;

use InvalidArgumentException;

final readonly class ToolCall
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments
    ) {
        if ($id === '' || $name === '') {
            throw new InvalidArgumentException('Tool-call ID and name must not be empty.');
        }
    }
}
