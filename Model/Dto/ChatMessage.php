<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Dto;

use InvalidArgumentException;

final readonly class ChatMessage
{
    private const ALLOWED_ROLES = ['system', 'user', 'assistant', 'tool'];

    public function __construct(
        public string $role,
        public string $content,
        public ?string $toolCallId = null
    ) {
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported chat-message role: %s', $role));
        }

        if ($content === '') {
            throw new InvalidArgumentException('Chat-message content must not be empty.');
        }

        if ($role === 'tool' && ($toolCallId === null || $toolCallId === '')) {
            throw new InvalidArgumentException('Tool messages require a tool-call identifier.');
        }
    }
}
