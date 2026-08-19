<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Response;

/**
 * The JSON schema passed as ChatRequest::responseSchema so the LLM is asked
 * for structured output instead of free text. Strict-mode compatible for
 * OpenAI (every object lists every property in "required" and sets
 * additionalProperties: false) — see OpenAiProvider::buildRequestBody(),
 * which wraps this into response_format:{type:json_schema,...,strict:true}.
 *
 * The LLM is asked for a SKU and a short reason per product it wants to
 * recommend, never a price, URL, or stock claim — those never appear in
 * this schema, so the model has no field to fabricate them into. Output
 * Validator still checks the free-text `message` for a leaked URL as
 * defense in depth.
 *
 * `follow_up_questions` carries a `description` (Task 28) — the only
 * property in this schema that does, added specifically because a real
 * OpenAI-compatible provider's structured-output mode does read and
 * follow JSON Schema `description` text as model guidance, giving this
 * one instruction a second, provider-native reinforcement alongside
 * ResponseContractFormatter's plain-language paragraph (the two
 * formatters agree deliberately; neither replaces the other, matching
 * this module's existing redundant-reinforcement style). Not added to
 * every other property here — this schema otherwise stays exactly as
 * minimal as it always has, and only this one field had a live-
 * reproduced voice bug worth the extra guidance.
 */
final class LlmResponseSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
                'product_skus' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sku' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['sku', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
                'follow_up_questions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Suggested next messages written in the CUSTOMER\'s own voice '
                        . '(e.g. "add the Tiberius Gym Tank to my cart"), never a question addressed '
                        . 'to the customer (e.g. "Would you like to add this to your cart?") — each '
                        . 'one is sent back verbatim as the customer\'s own next message when clicked.',
                ],
                'actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'skus' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['type', 'skus'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['message', 'product_skus', 'follow_up_questions', 'actions'],
            'additionalProperties' => false,
        ];
    }
}
