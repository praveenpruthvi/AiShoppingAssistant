<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatEntryPipelineInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\CommerceScopeClassifierInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\ConversationHistoryStoreInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\OutputValidatorInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\ToolCallingChatServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
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
 * provider (primary and fallback) failing outright, both reuse the exact
 * same SafeResponse shortCircuit path as an out-of-scope message — one
 * consistent safe-fallback outcome in the whole pipeline, not several.
 * Product search / the safe-response mechanism therefore keeps working
 * even when every configured LLM provider is down. **A retrieval/ranking
 * failure (Task 12) reuses the identical shortCircuit shape** — the
 * customer always sees the same generic safe message regardless of which
 * backend failed, while the reason code stays distinct
 * (`retrieval_unavailable` vs. `assistant_unavailable`) so logs/metrics
 * can still tell an OpenSearch/embedding-provider outage apart from an
 * LLM-provider outage. Only the sanitized ProductIndexingException/
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
 */
final class ChatEntryPipeline implements ChatEntryPipelineInterface
{
    public const REASON_ASSISTANT_DISABLED = 'assistant_disabled';
    public const REASON_ASSISTANT_UNAVAILABLE = 'assistant_unavailable';
    public const REASON_RETRIEVAL_UNAVAILABLE = 'retrieval_unavailable';

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
        private readonly ResponseContractFormatter $responseContractFormatter,
        private readonly ToolCallingChatServiceInterface $toolCallingChatService,
        private readonly LiveRevalidationServiceInterface $revalidationService,
        private readonly OutputValidatorInterface $outputValidator,
        private readonly ConversationHistoryStoreInterface $conversationHistoryStore,
        private readonly ChatResponseSerializer $responseSerializer,
        private readonly ProductMentionCompletenessChecker $completenessChecker,
        private readonly LoggerInterface $logger
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

        if (!$this->configurationReader->readGeneral($storeId)->isEnabled()) {
            return ChatPipelineResult::shortCircuit(
                new SafeResponse($guardrails->outOfScopeMessage(), self::REASON_ASSISTANT_DISABLED)
            );
        }

        $message = $this->inputValidator->validate($rawMessage, $guardrails);

        $classification = $this->scopeClassifier->classify($storeId, $message);

        if (!$classification->isInScope()) {
            return ChatPipelineResult::shortCircuit(
                new SafeResponse($guardrails->outOfScopeMessage(), (string) $classification->reasonCode())
            );
        }

        try {
            $candidates = $this->productContextResolver->resolve($storeId, $message);
        } catch (ProductIndexingException | ProviderException $exception) {
            $this->logRetrievalFailure($storeId, $exception);

            return ChatPipelineResult::shortCircuit(
                new SafeResponse($guardrails->outOfScopeMessage(), self::REASON_RETRIEVAL_UNAVAILABLE)
            );
        }

        $verifiedProducts = $this->revalidationService->revalidate($storeId, $customerGroupId, $this->skus($candidates));

        $maxConversationMessages = $this->configurationReader->readGeneral($storeId)->maxConversationMessages();
        $priorMessages = $conversationId !== null
            ? $this->conversationHistoryStore->recentMessages($conversationId, $storeId, $maxConversationMessages)
            : [];

        $userMessage = new ChatMessage('user', $message);
        $contextMessage = $this->productContextFormatter->format($candidates);
        $responseContractMessage = $this->responseContractFormatter->format();
        $messages = $contextMessage !== null
            ? [$responseContractMessage, $contextMessage, ...$priorMessages, $userMessage]
            : [$responseContractMessage, ...$priorMessages, $userMessage];

        // The best valid response seen across attempts, kept separately
        // from $validation/$toolResult (which always reflect the *last*
        // attempt) — a completeness retry (see below) can only ever make
        // things better or the same by design, but if the model's retry
        // attempt regresses into something genuinely invalid, the earlier
        // valid-but-incomplete response is still strictly better than the
        // generic fallback, so it's what gets used.
        $bestValidValidation = null;
        $bestValidToolResult = null;

        for ($attempt = 1; $attempt <= self::MAX_STRUCTURED_OUTPUT_ATTEMPTS; $attempt++) {
            $attemptsRemain = $attempt < self::MAX_STRUCTURED_OUTPUT_ATTEMPTS;

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

                if (!$attemptsRemain) {
                    break;
                }

                $messages = [...$messages, new ChatMessage('user', self::EMPTY_RESPONSE_CORRECTION_MESSAGE)];
                continue;
            } catch (ProviderException $exception) {
                $this->logProviderFailure($storeId, $exception, $attempt);
                break;
            }

            $validation = $this->outputValidator->validate(
                $toolResult->response,
                $this->mergeVerifiedProducts($verifiedProducts, $toolResult->verifiedProducts)
            );

            if ($validation->isValid()) {
                $bestValidValidation = $validation;
                $bestValidToolResult = $toolResult;

                $missingProducts = $this->completenessChecker->findMissingProducts(
                    $validation->response()->message,
                    array_map(static fn ($product) => $product->product->sku, $validation->response()->products),
                    $this->mergeVerifiedProducts($verifiedProducts, $toolResult->verifiedProducts)
                );

                if ($missingProducts === [] || !$attemptsRemain) {
                    break;
                }

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

            if ($validation->reasonCode() !== OutputValidator::REASON_MALFORMED_RESPONSE || !$attemptsRemain) {
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
            return ChatPipelineResult::shortCircuit(
                new SafeResponse($guardrails->outOfScopeMessage(), (string) $validation->reasonCode())
            );
        } else {
            return ChatPipelineResult::shortCircuit(
                new SafeResponse($guardrails->outOfScopeMessage(), self::REASON_ASSISTANT_UNAVAILABLE)
            );
        }

        if ($conversationId !== null) {
            $this->conversationHistoryStore->appendTurn(
                $conversationId,
                $storeId,
                [
                    $userMessage,
                    ...$toolResult->toolRoundTripMessages,
                    new ChatMessage('assistant', $validation->response()->message),
                ],
                $maxConversationMessages,
                $this->responseSerializer->serializeDisplayPayload($validation->response())
            );
        }

        return ChatPipelineResult::generated(
            $validation->response(),
            $this->isAwaitingConfirmation($toolResult->toolRoundTripMessages)
        );
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
