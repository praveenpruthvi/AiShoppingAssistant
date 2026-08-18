<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Dto;

use InvalidArgumentException;

final readonly class ChatMessage
{
    private const ALLOWED_ROLES = ['system', 'user', 'assistant', 'tool'];

    /**
     * @param list<ToolCall> $toolCalls populated only on an assistant
     *     message that is re-sent as conversation history after the model
     *     requested tool calls — content is legitimately empty in that
     *     case (mirrors ChatResponse's own text-or-toolCalls rule)
     */
    public function __construct(
        public string $role,
        public string $content,
        public ?string $toolCallId = null,
        public array $toolCalls = []
    ) {
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported chat-message role: %s', $role));
        }

        foreach ($toolCalls as $toolCall) {
            if (!$toolCall instanceof ToolCall) {
                throw new InvalidArgumentException('Every chat-message tool call must be a ToolCall.');
            }
        }

        if ($content === '' && $toolCalls === []) {
            throw new InvalidArgumentException('Chat-message content must not be empty unless tool calls are present.');
        }

        if ($role === 'tool' && ($toolCallId === null || $toolCallId === '')) {
            throw new InvalidArgumentException('Tool messages require a tool-call identifier.');
        }
    }
}
