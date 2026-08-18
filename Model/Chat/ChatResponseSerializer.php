<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantAction;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantResponse;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ProductResult;

/**
 * Shapes a ChatPipelineResult into the JSON body Controller\Chat\Send
 * returns — architecture.md's response contract, plus the live-verified
 * price/url/name fields ProductResult already carries (RevalidatedProduct
 * data, never LLM-sourced) so a frontend can render a product card
 * directly rather than needing a second round trip.
 *
 * Both branches (short-circuited and generated) return the same top-level
 * key set so a frontend never has to branch on which fields exist.
 *
 * `awaiting_confirmation` (Task 11) is the one field that isn't part of
 * architecture.md's original contract: it surfaces, for a frontend, a
 * fact ChatEntryPipeline already computed this turn — whether a mutating
 * cart tool's round-trip returned confirmation_required — so a UI can
 * offer a confirm/cancel affordance without the confirmation_token itself
 * (a security-relevant value) ever leaving the backend/LLM conversation
 * context. It makes no decision of its own.
 */
final class ChatResponseSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(ChatPipelineResult $result): array
    {
        if ($result->wasShortCircuited()) {
            $safe = $result->safeResponse();

            return [
                'message' => $safe->message,
                'reason_code' => $safe->reasonCode,
                'products' => [],
                'follow_up_questions' => [],
                'actions' => [],
                'metadata' => null,
                'awaiting_confirmation' => false,
            ];
        }

        $response = $result->response();

        return [
            'message' => $response->message,
            'reason_code' => null,
            ...$this->serializeDisplayPayload($response),
            'metadata' => [
                'provider' => $response->metadata->provider,
                'model' => $response->metadata->model,
                'fallback_used' => $response->metadata->fallbackUsed,
            ],
            'awaiting_confirmation' => $result->isAwaitingConfirmation(),
        ];
    }

    /**
     * The `products`/`follow_up_questions`/`actions` triple, in the exact
     * shape a frontend already knows how to render product cards from —
     * factored out of serialize() so ChatEntryPipeline can persist the
     * identical payload alongside a turn's final message (Task 20), and a
     * later restore (Controller\Chat\History) can hand it back to the
     * widget for a past turn using the same rendering code a live turn
     * already uses, rather than a second, divergent shape.
     *
     * @return array{products: list<array<string, mixed>>, follow_up_questions: list<string>, actions: list<array<string, mixed>>}
     */
    public function serializeDisplayPayload(AssistantResponse $response): array
    {
        return [
            'products' => array_map($this->serializeProduct(...), $response->products),
            'follow_up_questions' => $response->followUpQuestions,
            'actions' => array_map($this->serializeAction(...), $response->actions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProduct(ProductResult $product): array
    {
        return [
            'sku' => $product->product->sku,
            'name' => $product->product->name,
            'price' => $product->product->price,
            'special_price' => $product->product->specialPrice,
            'url' => $product->product->url,
            'image_url' => $product->product->imageUrl,
            'verified_at' => $product->product->verifiedAt,
            'reason' => $product->reason,
            'recommendation_type' => $product->recommendationType,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAction(AssistantAction $action): array
    {
        return [
            'type' => $action->type,
            'skus' => $action->skus,
        ];
    }
}
