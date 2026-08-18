<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;

/**
 * Builds the system ChatMessage that spells out, in plain language, the
 * exact JSON shape LlmResponseSchema/LlmResponseParser require.
 *
 * ChatRequest::responseSchema (AbstractChatProvider) already asks every
 * provider for this shape via the standard OpenAI-style `response_format:
 * {type: json_schema, strict: true}` mechanism — sufficient on its own for
 * OpenAI's real API, which enforces the schema at the sampling level. Local/
 * Ollama-served models do not reliably honor that field once a tool-call
 * round-trip is in the conversation: verified live against this
 * environment's real Ollama instance (qwen3.5) that the identical
 * response_format request produces a well-formed single-field JSON object
 * for a trivial single-turn prompt, but free-form markdown prose — or JSON
 * in a wrapping code fence with an entirely different, invented shape — once
 * a prior assistant tool-call and tool-result message are present, which
 * every real product-search turn always has. Repeating the exact required
 * field names in plain language as an explicit system instruction (proven,
 * via the same live reproduction, to restore compliance) is a standard,
 * provider-agnostic reinforcement — it does not weaken
 * LlmResponseParser/OutputValidator's fail-closed handling of a response
 * that still doesn't comply.
 *
 * Always included, unlike ProductContextFormatter's conditional system
 * message — the response contract applies to every turn regardless of
 * whether any product candidates were found.
 *
 * Also explicitly instructs the model to populate product_skus for any
 * product it names in the message text, not only when making a
 * recommendation — live-reproduced that a purely informational/descriptive
 * answer (e.g. "what are yoga pants made of") named several real products
 * by name in the free-text message but omitted one or more of them from
 * product_skus, so cards didn't render for products the text plainly
 * discussed. This is a prompting fix only, not a validator change:
 * OutputValidator's fabricated_sku fail-closed check is unchanged — a
 * response still cannot claim a product_skus entry that isn't in the
 * live-revalidated set, this instruction only asks the model to use the
 * field more completely when it already has a real product in view.
 *
 * Two more instructions added in Task 23, both traced to a specific real
 * failure caught by live, repeated testing (not theoretical):
 *
 * - "product_skus is a FIELD, never a tool you can call" — captured
 *   real Ollama tool-call traces where the model, instead of answering,
 *   emitted a tool call literally named "product_skus" (confusing the
 *   response schema's field name with one of the real callable tools).
 *   That call always fails as unknown_tool, burns a round out of
 *   guardrails.max_tool_calls, and was observed to sometimes leave the
 *   model producing nothing at all once its round budget ran out —
 *   ChatEntryPipeline now also retries an empty/invalid provider
 *   response once (see EMPTY_RESPONSE_CORRECTION_MESSAGE there), but
 *   preventing the confusion in the first place is cheaper than
 *   recovering from it.
 * - "reason should be a genuine description, not a bare number
 *   restatement" — live-captured `"reason": "price 32 is below 50"` and
 *   similar on a real response: technically true, but not a description
 *   a customer would find useful. Asks for the same kind of real product
 *   fact (material, use case, why it fits the request) the model already
 *   writes correctly most of the time, rather than mechanically restating
 *   the numbers it just compared.
 */
final class ResponseContractFormatter
{
    private const INSTRUCTIONS = <<<'TEXT'
Respond with a single JSON object only — no markdown code fences, no text
before or after the JSON, no other shape. The object must have exactly
these fields: "message" (string, your reply to the customer), "product_skus"
(array of {"sku": string, "reason": string}, only SKUs you were actually
shown), "follow_up_questions" (array of strings), and "actions" (array of
{"type": string, "skus": array of strings}). Every field is required, even
if its value is an empty array.

product_skus is not only for recommendations — it must include every
product your message names, describes, compares, or discusses in any way,
even a purely informational answer (e.g. "what is X made of", "does Y come
in blue"). If your message text mentions a product, that product's SKU
belongs in product_skus with a reason explaining what you said about it;
never mention a product in your message while leaving it out of
product_skus.

"product_skus" is a FIELD inside this JSON object — it is never a tool you
can call. Do not call a tool named "product_skus" or anything similar;
only call one of the tools you were actually offered, then put your final
answer's product references directly in this JSON field.

Each product_skus entry's "reason" must be a genuine, customer-useful
description — what the product is, what it's made of, or why it fits the
request — never a bare restatement of a number comparison like "price 32
is below 50". If you compared a price against a budget, say so naturally
("a great fit under your budget"), but always alongside a real reason to
want the product itself.
TEXT;

    public function format(): ChatMessage
    {
        return new ChatMessage('system', self::INSTRUCTIONS);
    }
}
