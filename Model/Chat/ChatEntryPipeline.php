<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatEntryPipelineInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\CommerceScopeClassifierInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\ConversationHistoryStoreInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\OutputValidatorInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\ToolCallingChatServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Promotion\ActivePromotionReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\HardFailureClassifierInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Debug\ChatDebugLogger;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Debug\ChatDebugTrace;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\LlmResponseSchema;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Psr\Log\LoggerInterface;

/**
 * The full runtime request pipeline: Input Validation -> Commerce Scope
 * Classifier -> short-circuit fixed response, or retrieval + ranking ->
 * live revalidation of the ranked candidates -> ToolCallingChatServiceInterface
 * (structured-output product context, prior conversation turns, allowlisted
 * commerce tools, the tool-call round-trip, and — transparently, via
 * FallbackChatGenerationService behind ChatGenerationServiceInterface every
 * round calls — retry/circuit breaker/fallback provider) -> Output
 * Validator against the combined retrieval + tool-verified set ->
 * structured response contract or a fixed safe response.
 *
 * Mirrors the "no assistant query may run without a StoreScopeInterface"
 * invariant used throughout the module: the store is validated before any
 * config is read. The assistant-disabled check runs before input
 * validation and classification so a disabled store never processes
 * customer text at all. An invalid provider response, or every LLM
 * provider (primary and fallback) failing outright, both short-circuit
 * to a SafeResponse rather than propagating — product search / the
 * safe-response mechanism therefore keeps working even when every
 * configured LLM provider is down. **A retrieval/ranking failure
 * (Task 12) reuses the identical shortCircuit shape.** Which message a
 * customer actually sees, and whether the storefront then stops
 * accepting further messages, depends on HardFailureClassifier
 * (Task 45): a transient failure (timeout, dropped connection, one
 * malformed completion) gets `assistant_unavailable`/
 * `retrieval_unavailable` and the guardrails "Assistant Temporarily
 * Unavailable" message — a subsequent message may well succeed. A hard
 * failure (an exhausted quota, an invalid/revoked API key — confirmed to
 * recur identically on every next attempt) instead gets
 * `assistant_down` and the guardrails "Assistant Down" message, and the
 * widget stops accepting further input for the rest of the visit, since
 * a retry cannot help. Only the sanitized ProductIndexingException/
 * ProviderException taxonomies are caught here — anything else (a real
 * programming bug: a TypeError, an unexpected InvalidArgumentException)
 * still propagates uncaught, the same "only catch what's actually
 * eligible/expected" discipline FallbackEligibilityPolicy already
 * established for the LLM path.
 *
 * Conversation memory (Task 8): when $conversationId is given, prior
 * messages are loaded and prepended to this turn's context, and — only
 * once a response has actually passed the Output Validator — this turn's
 * exchange (the user's message, every tool-call/tool-result message the
 * round-trip produced, and the final validated response text) is
 * persisted for the next turn. A short-circuited turn (disabled,
 * out-of-scope, provider failure, or a rejected/fabricated response) is
 * deliberately never persisted — there is nothing trustworthy to remember,
 * and persisting a rejected response would teach a future turn's model to
 * treat its own past fabrication as legitimate history. **The turn's
 * products/follow-up-questions/actions are persisted alongside its final
 * message too (Task 20)**, via ChatResponseSerializer::serializeDisplayPayload()
 * — the identical shape a live turn's response already carries — so a
 * later restore (Controller\Chat\History) can render a past turn's
 * product cards using the same rendering code a live turn uses, not just
 * its message text.
 *
 * **Every call also always logs one compact trace entry to a dedicated
 * debug log file (Task 23)**, via ChatDebugLogger — the whole method body
 * runs inside a try/finally so the trace is recorded no matter which
 * branch returns (disabled, out-of-scope, retrieval failure, provider
 * failure, or a fully generated response), reflecting exactly how far
 * this request actually got. The trace covers: the incoming message, the
 * scope classifier's decision, the retrieval query and every candidate's
 * BM25/vector/rank scores, live revalidation's before/after counts and
 * dropped SKUs (the one real "filter" step this pipeline has), and the
 * final product SKUs actually returned. This is a request-tracing aid
 * separate from system.log, not an error log — it logs every real
 * request, not just failures.
 *
 * **Once a response has passed the Output Validator, it is also
 * deterministically reconciled against any explicit price constraint the
 * customer's own query stated (Task 25)**, via PriceConstraintDetector +
 * PriceConstraintReconciler — a real, debug-log-proven bug where the LLM
 * correctly retrieved every matching candidate yet still silently dropped
 * some of them from its own product_skus selection (an availability
 * filter trace of 8-in/8-out next to only 4 final product SKUs, for a
 * plain "jackets below $60" query). Rather than trusting the model to
 * apply a numeric threshold correctly across the whole candidate list,
 * the constraint is parsed from the query text (simple regex, mirroring
 * OutputValidator's own currency-shaped-number matching) and applied in
 * code against the same live-revalidated prices OutputValidator already
 * validated the response's SKUs against: any qualifying candidate the
 * model missed is added, and any selected product that doesn't actually
 * satisfy the constraint is removed — before persistence and before the
 * response is returned, so both the customer and the debug trace always
 * see the corrected set.
 *
 * **A short, context-dependent follow-up now reliably works (Task 26)**:
 * live-reproduced "the cheaper one"/"medium size" right after a
 * successful product query falling all the way back to the generic
 * message (`fabricated_sku`) — the debug trace showed conversation
 * history genuinely threaded into the LLM call (Task 8 working as
 * designed) but this turn's own retrieval, run on the follow-up text
 * alone, returning candidates completely unrelated to the prior turn
 * (no product-type signal in "the cheaper one"). Whether the model could
 * still answer depended entirely on it independently choosing to call a
 * tool with a SKU it merely remembered from history text — unreliable,
 * confirmed live (worked once, failed once with the same phrasing
 * pattern). `PriorTurnProductCarryOver` now recovers the immediately
 * preceding assistant turn's real product SKUs (only ever from an
 * already-persisted, already-validated turn) and this turn re-
 * revalidates them live before merging them into the verified set every
 * time conversation history exists — regardless of what this turn's own
 * retrieval finds. `ProductContextFormatter`'s prompt was also relaxed:
 * it previously told the model this turn's candidate list was "the
 * complete and only set of products you may mention," actively
 * discouraging it from referencing a real product it already named
 * earlier in the same conversation, even once the code-level merge above
 * made that safe.
 *
 * **A real, silent "zero product cards despite the text naming real
 * products" bug is now fixed (Task 29)**, and — per PriorTurnProductCarryOver's
 * own "skip a product-less turn" rule (Task 26) — this bug had a genuine
 * cascading effect: the next turn lost access to carry-over context it
 * should have had, live-confirmed causing a subsequent fabricated_sku
 * rejection. Root-caused via a temporary, immediately-reverted raw-parse
 * capture (this module's established capture-then-revert technique):
 * ProductMentionCompletenessChecker's own name-matching logic never
 * distinguished an empty product_skus from a partial one — proven
 * directly, a captured 0-of-1 miss was found and corrected via the
 * existing retry exactly like Task 23's partial-miss case, when a spare
 * attempt was actually available. The real cause was the *shared*
 * MAX_STRUCTURED_OUTPUT_ATTEMPTS budget itself: `if ($missingProducts
 * === [] || !$attemptsRemain) { break; }` unconditionally gave up once
 * `$attempt` reached the cap, with no retry sent — including on the
 * *last* attempt, which is exactly the attempt a completeness gap
 * surfaces on whenever an *earlier* attempt was already spent correcting
 * a malformed response or a ProviderInvalidResponseException. A
 * completeness gap that first appears on the final allowed attempt
 * therefore had zero chance of ever being corrected and shipped as-is —
 * not a matching-logic gap, a budget-starvation gap, and one that
 * applies to a partial miss exactly as much as a total one; "total"
 * miss just happened to be the case that got reported first. Fixed by
 * giving completeness one *guaranteed* extra attempt
 * (MAX_TOTAL_ATTEMPTS), reserved specifically for it and never
 * consumable by a malformed/invalid-response retry — so it's still only
 * ever spent in the specific compound case this bug requires (an
 * earlier compliance correction *and* a completeness gap in the same
 * turn), not on every turn's worst case, unlike Task 23's own reverted
 * attempt to fix a related latency concern by raising a *different*
 * budget (guardrails.max_tool_calls) across the board.
 */
final class ChatEntryPipeline implements ChatEntryPipelineInterface
{
    public const REASON_ASSISTANT_DISABLED = 'assistant_disabled';
    public const REASON_ASSISTANT_UNAVAILABLE = 'assistant_unavailable';
    public const REASON_RETRIEVAL_UNAVAILABLE = 'retrieval_unavailable';

    /**
     * A HardFailureClassifier-confirmed hard failure (an exhausted quota,
     * an invalid/revoked API key) reached this pipeline with every retry
     * and fallback already exhausted. Unlike REASON_ASSISTANT_UNAVAILABLE/
     * REASON_RETRIEVAL_UNAVAILABLE, this is the frontend's signal to stop
     * offering the chat for the rest of the visit, not only to show a
     * different message — the underlying problem is confirmed to recur on
     * every subsequent message, not just this one.
     */
    public const REASON_ASSISTANT_DOWN = 'assistant_down';

    /**
     * One initial attempt plus one self-correction retry. Live-verified
     * against this environment's real local/Ollama chat provider that a
     * single corrective round-trip reliably recovers a response the model
     * answered in free-text prose instead of the required JSON — a local
     * model's schema compliance measurably degrades as the conversation
     * grows (product context, tool-call/tool-result messages), something
     * response_format/an explicit system instruction alone did not fully
     * solve. Bounded at 2 total attempts: this is a compliance repair, not
     * a resilience mechanism (FallbackChatGenerationService already owns
     * retrying on provider *availability* failures) — an LLM that won't
     * comply after being shown its own mistake and corrected once is
     * treated the same as any other genuinely malformed response, and the
     * existing safe-fallback path still applies.
     *
     * As of Task 23, this same 2-attempt budget covers three distinct
     * compliance problems, not just malformed JSON: a provider-level
     * ProviderInvalidResponseException (the model returned nothing at all,
     * or something the provider adapter couldn't parse as a completion —
     * live-reproduced happening after the model wastes its tool-call
     * budget on a hallucinated call to a tool named "product_skus", which
     * isn't a real tool, and is then forced to answer with no tools
     * offered), and a valid-but-incomplete response (a real product named
     * in the message text that never made it into product_skus — see
     * ProductMentionCompletenessChecker). Whichever of the two attempts a
     * genuinely valid response first appears on, it's kept even if the
     * *other* attempt was worse — see $bestValidValidation below.
     */
    private const MAX_STRUCTURED_OUTPUT_ATTEMPTS = 2;

    /**
     * One extra attempt reserved specifically for a completeness
     * correction (Task 29) — never consumable by a malformed-response or
     * ProviderInvalidResponseException retry, both of which stay capped
     * at MAX_STRUCTURED_OUTPUT_ATTEMPTS exactly as before. Guarantees
     * completeness always gets its one shot at correction even when an
     * earlier attempt was already spent on an unrelated compliance
     * problem — see the class docblock for the real bug this closes.
     */
    private const MAX_TOTAL_ATTEMPTS = self::MAX_STRUCTURED_OUTPUT_ATTEMPTS + 1;

    private const STRUCTURED_OUTPUT_CORRECTION_MESSAGE = <<<'TEXT'
Your previous response was not valid — it must be a single JSON object
only, no markdown, no prose, matching exactly: "message" (string),
"product_skus" (array of {"sku": string, "reason": string}), "follow_up_questions"
(array of strings), "actions" (array of {"type": string, "skus": array of
strings}). Respond again, correctly this time.
TEXT;

    /**
     * The specific corrective nudge for a ProviderInvalidResponseException
     * (empty or unparseable completion) — distinct from
     * STRUCTURED_OUTPUT_CORRECTION_MESSAGE because live testing traced the
     * root cause to a specific, nameable mistake (calling a nonexistent
     * "product_skus" tool) worth calling out directly, not just repeating
     * the shape requirements the model never even attempted to follow.
     */
    private const EMPTY_RESPONSE_CORRECTION_MESSAGE = <<<'TEXT'
Your previous turn ended without a response. Remember: "product_skus" is a
FIELD inside your final JSON answer, never a tool you can call — do not
call a tool named "product_skus" or anything similar. If you already have
enough information from the tools you called, respond now with the
required JSON object described earlier.
TEXT;

    public function __construct(
        private readonly StoreScopeProviderInterface $storeScopeProvider,
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly ChatInputValidator $inputValidator,
        private readonly CommerceScopeClassifierInterface $scopeClassifier,
        private readonly ProductContextResolver $productContextResolver,
        private readonly ProductContextFormatter $productContextFormatter,
        private readonly ActivePromotionReaderInterface $activePromotionReader,
        private readonly PromotionContextFormatter $promotionContextFormatter,
        private readonly ResponseContractFormatter $responseContractFormatter,
        private readonly ToolCallingChatServiceInterface $toolCallingChatService,
        private readonly LiveRevalidationServiceInterface $revalidationService,
        private readonly OutputValidatorInterface $outputValidator,
        private readonly ConversationHistoryStoreInterface $conversationHistoryStore,
        private readonly ChatResponseSerializer $responseSerializer,
        private readonly ProductMentionCompletenessChecker $completenessChecker,
        private readonly PriceConstraintDetector $priceConstraintDetector,
        private readonly PriceConstraintReconciler $priceConstraintReconciler,
        private readonly PriorTurnProductCarryOver $priorTurnProductCarryOver,
        private readonly ChatDebugLogger $debugLogger,
        private readonly LoggerInterface $logger,
        private readonly HardFailureClassifierInterface $hardFailureClassifier
    ) {
    }

    public function handle(
        int $storeId,
        string $rawMessage,
        ?int $customerGroupId = null,
        ?string $cartId = null,
        ?string $conversationId = null
    ): ChatPipelineResult {
        $this->storeScopeProvider->requireActive($storeId);

        $guardrails = $this->configurationReader->readGuardrails($storeId);

        // Constructed before every branch below (including the very first
        // one) so it is always available to the finally block regardless
        // of how early this request is short-circuited — a disabled store
        // or an out-of-scope message is still a real request the debug
        // log should record, just with the later fields left null because
        // the pipeline genuinely never reached that stage.
        $trace = new ChatDebugTrace($rawMessage);

        try {
            if (!$this->configurationReader->readGeneral($storeId)->isEnabled()) {
                $trace->outcome = self::REASON_ASSISTANT_DISABLED;

                return ChatPipelineResult::shortCircuit(
                    new SafeResponse($guardrails->outOfScopeMessage(), self::REASON_ASSISTANT_DISABLED)
                );
            }

            $message = $this->inputValidator->validate($rawMessage, $guardrails);

            $classification = $this->scopeClassifier->classify($storeId, $message);
            $trace->inScope = $classification->isInScope();
            $trace->scopeReasonCode = $classification->reasonCode();

            if (!$classification->isInScope()) {
                $trace->outcome = 'out_of_scope:' . (string) $classification->reasonCode();

                return ChatPipelineResult::shortCircuit(
                    new SafeResponse($guardrails->outOfScopeMessage(), (string) $classification->reasonCode())
                );
            }

            $trace->retrievalQuery = $message;

            $priceConstraint = $this->priceConstraintDetector->detect($message);
            $trace->priceConstraint = $priceConstraint === null ? null : [
                'max' => $priceConstraint->max,
                'max_inclusive' => $priceConstraint->maxInclusive,
                'min' => $priceConstraint->min,
                'min_inclusive' => $priceConstraint->minInclusive,
            ];

            try {
                $candidates = $this->productContextResolver->resolve($storeId, $message);
            } catch (ProductIndexingException | ProviderException $exception) {
                $this->logRetrievalFailure($storeId, $exception);

                if ($exception instanceof ProviderException && $this->hardFailureClassifier->isHardFailure($exception)) {
                    $trace->outcome = self::REASON_ASSISTANT_DOWN;

                    return ChatPipelineResult::shortCircuit(
                        new SafeResponse($guardrails->assistantDownMessage(), self::REASON_ASSISTANT_DOWN)
                    );
                }

                $trace->outcome = self::REASON_RETRIEVAL_UNAVAILABLE;

                return ChatPipelineResult::shortCircuit(
                    new SafeResponse($guardrails->assistantUnavailableMessage(), self::REASON_RETRIEVAL_UNAVAILABLE)
                );
            }

            $trace->candidates = $this->traceCandidates($candidates);

            $verifiedProducts = $this->revalidationService->revalidate($storeId, $customerGroupId, $this->skus($candidates));

            $this->recordAvailabilityFilter($trace, $candidates, $verifiedProducts);

            $maxConversationMessages = $this->configurationReader->readGeneral($storeId)->maxConversationMessages();
            $priorMessages = $conversationId !== null
                ? $this->conversationHistoryStore->recentMessages($conversationId, $storeId, $maxConversationMessages)
                : [];

            // A short follow-up ("medium size", "the cheaper one") is,
            // on its own, a weak retrieval query with no product-type
            // signal — live-reproduced returning candidates completely
            // unrelated to the immediately preceding turn's real
            // products. Carrying those SKUs forward and re-revalidating
            // them live (never trusting the stored data itself) makes
            // them available to this turn's Output Validator regardless
            // of what this turn's own retrieval finds, closing the gap
            // between "the model happens to recover by calling a tool
            // with a remembered SKU" (unreliable — worked once, failed
            // once in live testing) and a guaranteed, structural fix.
            if ($conversationId !== null) {
                $carriedOverSkus = $this->priorTurnProductCarryOver->skus($conversationId, $storeId, $maxConversationMessages);

                if ($carriedOverSkus !== []) {
                    $carriedOverProducts = $this->revalidationService->revalidate($storeId, $customerGroupId, $carriedOverSkus);
                    $verifiedProducts = $this->mergeVerifiedProducts($verifiedProducts, $carriedOverProducts);
                    $trace->carriedOverSkus = array_map(
                        static fn (RevalidatedProduct $product): string => $product->sku,
                        $carriedOverProducts
                    );
                }
            }

            // Resolved from this turn's already-live-revalidated products
            // (never the search index), so a real catalog-rule discount can
            // be mentioned proactively even when the shopper never asks —
            // see PromotionContextFormatter's own docblock for why this is
            // a separate message rather than a new ProductContextFormatter
            // field. Gated by the same capability toggle as the
            // get_active_promotions tool (isPromotionAwarenessEnabled())
            // — disabling the capability turns off promotion awareness end
            // to end, not merely the tool.
            $catalogDiscounts = $this->configurationReader->readCapabilities($storeId)->isPromotionAwarenessEnabled()
                ? $this->activePromotionReader->catalogRuleDiscounts($storeId, $customerGroupId, $verifiedProducts)
                : [];

            $userMessage = new ChatMessage('user', $message);
            $contextMessage = $this->productContextFormatter->format($candidates);
            $promotionContextMessage = $this->promotionContextFormatter->format($catalogDiscounts);
            $responseContractMessage = $this->responseContractFormatter->format();

            $messages = [$responseContractMessage];
            if ($contextMessage !== null) {
                $messages[] = $contextMessage;
            }
            if ($promotionContextMessage !== null) {
                $messages[] = $promotionContextMessage;
            }
            $messages = [...$messages, ...$priorMessages, $userMessage];

            // The best valid response seen across attempts, kept separately
            // from $validation/$toolResult (which always reflect the *last*
            // attempt) — a completeness retry (see below) can only ever make
            // things better or the same by design, but if the model's retry
            // attempt regresses into something genuinely invalid, the earlier
            // valid-but-incomplete response is still strictly better than the
            // generic fallback, so it's what gets used.
            $bestValidValidation = null;
            $bestValidToolResult = null;
            $completenessRetryUsed = false;
            $terminalProviderException = null;

            for ($attempt = 1; $attempt <= self::MAX_TOTAL_ATTEMPTS; $attempt++) {
                $complianceAttemptsRemain = $attempt < self::MAX_STRUCTURED_OUTPUT_ATTEMPTS;

                try {
                    $toolResult = $this->toolCallingChatService->converse(
                        $storeId,
                        $customerGroupId,
                        $cartId,
                        $messages,
                        LlmResponseSchema::schema()
                    );
                } catch (ProviderInvalidResponseException $exception) {
                    // Distinct from the broader ProviderException catch below:
                    // an invalid/empty completion is a compliance problem (the
                    // provider answered, just not usefully — live-traced to the
                    // model exhausting its tool-call budget after hallucinating
                    // a call to a nonexistent "product_skus" tool, then being
                    // forced to answer with no tools offered), not a genuine
                    // availability failure. It gets the same one-retry-with-a-
                    // nudge treatment as a malformed response, instead of
                    // failing the turn outright on the first occurrence.
                    $this->logProviderFailure($storeId, $exception, $attempt);
                    $terminalProviderException = $exception;

                    if (!$complianceAttemptsRemain) {
                        break;
                    }

                    $messages = [...$messages, new ChatMessage('user', self::EMPTY_RESPONSE_CORRECTION_MESSAGE)];
                    continue;
                } catch (ProviderException $exception) {
                    $this->logProviderFailure($storeId, $exception, $attempt);
                    $terminalProviderException = $exception;
                    break;
                }

                $terminalProviderException = null;

                $validation = $this->outputValidator->validate(
                    $toolResult->response,
                    $this->mergeVerifiedProducts($verifiedProducts, $toolResult->verifiedProducts),
                    [...array_values($catalogDiscounts), ...$toolResult->verifiedProductPromotions],
                    $toolResult->verifiedCartPromotions
                );

                if ($validation->isValid()) {
                    $bestValidValidation = $validation;
                    $bestValidToolResult = $toolResult;

                    $missingProducts = $this->completenessChecker->findMissingProducts(
                        $validation->response()->message,
                        array_map(static fn ($product) => $product->product->sku, $validation->response()->products),
                        $this->mergeVerifiedProducts($verifiedProducts, $toolResult->verifiedProducts)
                    );

                    if ($missingProducts === [] || $completenessRetryUsed || $attempt >= self::MAX_TOTAL_ATTEMPTS) {
                        break;
                    }

                    $completenessRetryUsed = true;

                    $this->logger->notice(
                        'AI shopping assistant: retrying to include products the response text named but omitted from product_skus.',
                        [
                            'store_id' => $storeId,
                            'attempt' => $attempt,
                            'missing_skus' => array_map(static fn ($product) => $product->sku, $missingProducts),
                        ]
                    );

                    $messages = [
                        ...$messages,
                        ...$toolResult->toolRoundTripMessages,
                        new ChatMessage('assistant', $toolResult->response->text),
                        new ChatMessage('user', $this->missingProductsCorrectionMessage($missingProducts)),
                    ];
                    continue;
                }

                if ($validation->reasonCode() !== OutputValidator::REASON_MALFORMED_RESPONSE || !$complianceAttemptsRemain) {
                    break;
                }

                $this->logger->notice('AI shopping assistant: retrying after a malformed structured-output response.', [
                    'store_id' => $storeId,
                    'attempt' => $attempt,
                ]);

                $messages = [
                    ...$messages,
                    ...$toolResult->toolRoundTripMessages,
                    new ChatMessage('assistant', $toolResult->response->text),
                    new ChatMessage('user', self::STRUCTURED_OUTPUT_CORRECTION_MESSAGE),
                ];
            }

            if ($bestValidValidation !== null) {
                $validation = $bestValidValidation;
                $toolResult = $bestValidToolResult;
            } elseif (isset($validation) && !$validation->isValid()) {
                $trace->outcome = 'invalid:' . (string) $validation->reasonCode();

                return ChatPipelineResult::shortCircuit(
                    new SafeResponse($guardrails->outOfScopeMessage(), (string) $validation->reasonCode())
                );
            } elseif ($terminalProviderException !== null && $this->hardFailureClassifier->isHardFailure($terminalProviderException)) {
                $trace->outcome = self::REASON_ASSISTANT_DOWN;

                return ChatPipelineResult::shortCircuit(
                    new SafeResponse($guardrails->assistantDownMessage(), self::REASON_ASSISTANT_DOWN)
                );
            } else {
                $trace->outcome = self::REASON_ASSISTANT_UNAVAILABLE;

                return ChatPipelineResult::shortCircuit(
                    new SafeResponse($guardrails->assistantUnavailableMessage(), self::REASON_ASSISTANT_UNAVAILABLE)
                );
            }

            // Deterministic, code-only correction (Task 25): a real,
            // live-reproduced bug showed retrieval correctly surfacing
            // every matching candidate (the availability_filter trace
            // above proves it) while the model's own product_skus
            // selection still silently dropped some of them, even when
            // an explicit price constraint in the customer's own query
            // made the correct answer fully computable. Reconciling
            // here, once, against the same verified set OutputValidator
            // already checked $validation against, both adds any real
            // qualifying candidate the model missed and removes any
            // selected product that doesn't actually satisfy the
            // constraint — never another model round-trip.
            $reconciliation = $this->priceConstraintReconciler->reconcile(
                $priceConstraint,
                $validation->response(),
                $this->mergeVerifiedProducts($verifiedProducts, $toolResult->verifiedProducts)
            );
            $finalResponse = $reconciliation->response;
            $trace->priceConstraintAddedSkus = $reconciliation->addedSkus;
            $trace->priceConstraintRemovedSkus = $reconciliation->removedSkus;

            if ($conversationId !== null) {
                $this->conversationHistoryStore->appendTurn(
                    $conversationId,
                    $storeId,
                    [
                        $userMessage,
                        ...$toolResult->toolRoundTripMessages,
                        new ChatMessage('assistant', $finalResponse->message),
                    ],
                    $maxConversationMessages,
                    $this->responseSerializer->serializeDisplayPayload($finalResponse)
                );
            }

            $trace->finalProductSkus = array_map(
                static fn ($product) => $product->product->sku,
                $finalResponse->products
            );
            $trace->outcome = 'generated';

            return ChatPipelineResult::generated(
                $finalResponse,
                $this->isAwaitingConfirmation($toolResult->toolRoundTripMessages)
            );
        } finally {
            $this->debugLogger->record($storeId, $conversationId, $trace);
        }
    }

    /**
     * @param list<SearchCandidate> $candidates
     *
     * @return list<array{sku: string, bm25_score: float, vector_score: float, rank_score: float}>
     */
    private function traceCandidates(array $candidates): array
    {
        return array_map(
            static fn (SearchCandidate $candidate): array => [
                'sku' => $candidate->sku,
                'bm25_score' => $candidate->bm25Score,
                'vector_score' => $candidate->vectorScore,
                'rank_score' => $candidate->score,
            ],
            $candidates
        );
    }

    /**
     * The one real "filter" step in this pipeline (Task 23): live
     * revalidation against Magento itself drops any retrieved candidate
     * that turns out disabled/not visible/off-website/out of stock by the
     * time this turn actually runs, even though it looked eligible when
     * the index was last written. Records the before/after counts and
     * exactly which SKUs were dropped, purely for the debug trace — makes
     * no decision of its own.
     *
     * @param list<SearchCandidate> $candidates
     * @param list<RevalidatedProduct> $verifiedProducts
     */
    private function recordAvailabilityFilter(ChatDebugTrace $trace, array $candidates, array $verifiedProducts): void
    {
        $candidateSkus = array_values(array_unique($this->skus($candidates)));
        $verifiedSkus = array_map(static fn (RevalidatedProduct $product): string => $product->sku, $verifiedProducts);

        $trace->availabilityFilterBeforeCount = count($candidateSkus);
        $trace->availabilityFilterAfterCount = count($verifiedSkus);
        $trace->availabilityFilterDroppedSkus = array_values(array_diff($candidateSkus, $verifiedSkus));
    }

    /**
     * The customer only ever sees the generic safe-fallback message — this
     * is the only place the real cause (which backend, which sanitized
     * error code) is recorded, for admin/ops visibility. Mirrors the
     * structured-context logging convention already used elsewhere in this
     * module (e.g. AddToCartTool's failure logging): a short, fixed
     * message plus a context array, never the raw customer message text.
     *
     * @param ProductIndexingException|ProviderException $exception both
     *     declare their own errorCode(); the union catch type above
     *     guarantees one or the other
     */
    private function logRetrievalFailure(int $storeId, ProductIndexingException|ProviderException $exception): void
    {
        $this->logger->error('AI shopping assistant: retrieval/ranking failed, returning a safe fallback response.', [
            'store_id' => $storeId,
            'exception_class' => $exception::class,
            'error_code' => $exception->errorCode(),
            'exception' => $exception->getMessage(),
        ]);
    }

    /**
     * The chat-provider equivalent of logRetrievalFailure() — added in
     * Task 23 after finding this catch block previously discarded the
     * real exception entirely (`catch (ProviderException)`, no variable
     * bound, nothing logged), leaving every real "assistant_unavailable"
     * occurrence with zero diagnostic trail. Same sanitized-context
     * logging convention: error code and exception class, never raw
     * customer message text.
     */
    private function logProviderFailure(int $storeId, ProviderException $exception, int $attempt): void
    {
        $this->logger->error('AI shopping assistant: chat provider call failed.', [
            'store_id' => $storeId,
            'attempt' => $attempt,
            'exception_class' => $exception::class,
            'error_code' => $exception->errorCode(),
            'exception' => $exception->getMessage(),
        ]);
    }

    /**
     * @param list<RevalidatedProduct> $missingProducts
     */
    private function missingProductsCorrectionMessage(array $missingProducts): string
    {
        $lines = array_map(
            static fn (RevalidatedProduct $product): string => '- ' . $product->name . ' (SKU: ' . $product->sku . ')',
            $missingProducts
        );

        return "Your previous response named the following product(s) in its message text but left "
            . "them out of product_skus:\n" . implode("\n", $lines)
            . "\n\nRespond again with the same information, but include every one of these SKUs in "
            . "product_skus this time, each with its own reason.";
    }

    /**
     * Scans this turn's tool round-trip for a confirmation_required status
     * a mutating cart tool (AddToCartTool/RemoveFromCartTool) already
     * returned — a mechanical read of data this same turn already
     * produced, not a new decision about whether confirmation is needed.
     *
     * @param list<ChatMessage> $toolRoundTripMessages
     */
    private function isAwaitingConfirmation(array $toolRoundTripMessages): bool
    {
        foreach ($toolRoundTripMessages as $message) {
            if ($message->role !== 'tool') {
                continue;
            }

            $decoded = json_decode($message->content, true);

            if (is_array($decoded) && ($decoded['status'] ?? null) === 'confirmation_required') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<SearchCandidate> $candidates
     *
     * @return list<string>
     */
    private function skus(array $candidates): array
    {
        return array_map(static fn (SearchCandidate $candidate): string => $candidate->sku, $candidates);
    }

    /**
     * Combines the up-front retrieval-derived verified set with whatever
     * RevalidatedProducts tool calls verified mid-conversation, so the
     * Output Validator accepts a SKU either source surfaced. Deduplicates
     * by SKU; a tool-verified entry wins over a retrieval one for the same
     * SKU since it was checked more recently and more specifically.
     *
     * @param list<RevalidatedProduct> $retrievalVerified
     * @param list<RevalidatedProduct> $toolVerified
     *
     * @return list<RevalidatedProduct>
     */
    private function mergeVerifiedProducts(array $retrievalVerified, array $toolVerified): array
    {
        $bySku = [];

        foreach ($retrievalVerified as $product) {
            $bySku[$product->sku] = $product;
        }

        foreach ($toolVerified as $product) {
            $bySku[$product->sku] = $product;
        }

        return array_values($bySku);
    }
}
