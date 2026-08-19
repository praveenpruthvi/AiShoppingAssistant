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
 *
 * A persona + strict-grounding paragraph now leads the instructions
 * (Task 27): this module had rules governing the JSON *shape* of a
 * response and (in ProductContextFormatter, only on a turn with
 * candidates) rules scoping *which products* may be named, but no single
 * always-present statement of who the assistant is and that it must
 * never invent a fact absent from this turn's actual data — auditing the
 * two existing formatters found no such statement anywhere, not an
 * oversight this task is guessing at. Placed here rather than in
 * ProductContextFormatter specifically because this message is always
 * included, every turn, including one where retrieval found nothing at
 * all — the "say so plainly instead of inventing something" instruction
 * has to reach the model on exactly those turns too, not only when a
 * candidate list exists. Deliberately overlaps with
 * ProductContextFormatter's own "never invent a SKU/price/stock/URL"
 * sentence rather than replacing it — the same redundant-validation
 * philosophy this codebase already uses elsewhere (e.g.
 * AbstractEmbeddingProvider re-checking fields its own DTO already
 * guarantees): two independent reinforcements of the same rule, from a
 * general persona-level statement and a specific per-turn data-scoped
 * one, are cheaper insurance than one. OutputValidator's fabricated_sku/
 * fabricated_price/fabricated_url checks remain the actual, unchanged
 * enforcement boundary — this paragraph can only reduce how often a
 * response needs rejecting or reconciling in the first place, never
 * substitute for those checks.
 *
 * follow_up_questions must now be written in the customer's own voice,
 * not the assistant's (Task 28): the storefront widget renders each one
 * as a clickable chip and, on click, sends its exact text back as the
 * *customer's own next message* (Controller\Chat\Send has no separate
 * "chip click" signal — see chat-widget-luma.js's submitMessage(question)/
 * chat-widget-hyva.js's askFollowUp(question), both indistinguishable
 * from the customer typing that text themselves). Before this task,
 * nothing in the instructions said which voice to use, and the model
 * defaulted to phrasing these as questions addressed TO the customer
 * ("Would you like to add this to your cart?", "Which of these
 * interests you most?") — live-reproduced clicking one sending the
 * assistant's own question back to it labeled as the customer's message,
 * which then confused the next turn (the "customer" appearing to ask the
 * assistant its own question back). The fix is prompt-only: the
 * schema/parser/response contract shape is unchanged, still a plain
 * array of strings — only what those strings should say changed.
 */
final class ResponseContractFormatter
{
    private const INSTRUCTIONS = <<<'TEXT'
You are a shopping assistant for this store. You help customers find,
compare, and learn about real products and services this store actually
sells, using only the retrieved candidates, live tool results, and any
product carried over from earlier in this conversation that are actually
provided to you for this turn. Never invent a product, price, SKU, URL,
stock status, or attribute that is not present in that data — not even
one you believe is a plausible, realistic product for a store like this.
If nothing provided to you for this turn actually matches what the
customer is asking for, say so plainly instead of describing something
that merely sounds right.

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

Write every follow_up_questions entry in the CUSTOMER's own voice, never
the assistant's. Each one becomes a clickable suggestion that gets sent
back to you verbatim as though the customer had typed it themselves, so
it must be a short, natural thing the customer might actually say or ask
next — e.g. "add the Tiberius Gym Tank to my cart", "show me other tank
tops under $20", "what's it made of", "do you have this in blue". Never
phrase one as a question addressed TO the customer, like "Would you like
to add this to your cart?" or "Which of these interests you most?" — a
suggestion in the assistant's voice puts the assistant's own words in
the customer's mouth and confuses the next turn.
TEXT;

    public function format(): ChatMessage
    {
        return new ChatMessage('system', self::INSTRUCTIONS);
    }
}
