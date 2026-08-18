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
