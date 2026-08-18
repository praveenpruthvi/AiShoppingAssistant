<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantResponse;
use LogicException;

/**
 * The outcome of one ChatEntryPipeline::handle() call: either a fixed safe
 * response (short-circuited before any provider call, or before an invalid
 * provider response could reach the caller) or the fully validated
 * structured response contract. Two-outcome value object, mirrors
 * ConnectionResult.
 *
 * Carries AssistantResponse, not the raw provider ChatResponse — nothing
 * past OutputValidator is ever allowed to hand back an unvalidated
 * response, which is the entire point of building it.
 */
final readonly class ChatPipelineResult
{
    private function __construct(
        private bool $shortCircuited,
        private ?SafeResponse $safeResponse,
        private ?AssistantResponse $response,
        private bool $awaitingConfirmation = false
    ) {
    }

    public static function shortCircuit(SafeResponse $response): self
    {
        return new self(true, $response, null);
    }

    /**
     * $awaitingConfirmation surfaces a fact ChatEntryPipeline already
     * computed this same turn (whether any tool call in the round-trip
     * returned a confirmation_required status) purely so a frontend can
     * offer a confirm/cancel affordance — it makes no new decision of its
     * own about whether confirmation is required; that remains entirely
     * AddToCartTool/RemoveFromCartTool/CartMutationConfirmationService's
     * job, unchanged.
     */
    public static function generated(AssistantResponse $response, bool $awaitingConfirmation = false): self
    {
        return new self(false, null, $response, $awaitingConfirmation);
    }

    public function wasShortCircuited(): bool
    {
        return $this->shortCircuited;
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this->awaitingConfirmation;
    }

    public function safeResponse(): SafeResponse
    {
        if ($this->safeResponse === null) {
            throw new LogicException('This pipeline result was not short-circuited.');
        }

        return $this->safeResponse;
    }

    public function response(): AssistantResponse
    {
        if ($this->response === null) {
            throw new LogicException('This pipeline result was short-circuited.');
        }

        return $this->response;
    }
}
