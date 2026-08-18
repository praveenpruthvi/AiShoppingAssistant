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
     */
    private const MAX_STRUCTURED_OUTPUT_ATTEMPTS = 2;

    private const STRUCTURED_OUTPUT_CORRECTION_MESSAGE = <<<'TEXT'
Your previous response was not valid — it must be a single JSON object
only, no markdown, no prose, matching exactly: "message" (string),
"product_skus" (array of {"sku": string, "reason": string}), "follow_up_questions"
(array of strings), "actions" (array of {"type": string, "skus": array of
strings}). Respond again, correctly this time.
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

        for ($attempt = 1; $attempt <= self::MAX_STRUCTURED_OUTPUT_ATTEMPTS; $attempt++) {
            try {
                $toolResult = $this->toolCallingChatService->converse(
                    $storeId,
                    $customerGroupId,
                    $cartId,
                    $messages,
                    LlmResponseSchema::schema()
                );
            } catch (ProviderException) {
                return ChatPipelineResult::shortCircuit(
                    new SafeResponse($guardrails->outOfScopeMessage(), self::REASON_ASSISTANT_UNAVAILABLE)
                );
            }

            $validation = $this->outputValidator->validate(
                $toolResult->response,
                $this->mergeVerifiedProducts($verifiedProducts, $toolResult->verifiedProducts)
            );

            $isRetryableMalformedResponse = !$validation->isValid()
                && $validation->reasonCode() === OutputValidator::REASON_MALFORMED_RESPONSE
                && $attempt < self::MAX_STRUCTURED_OUTPUT_ATTEMPTS;

            if (!$isRetryableMalformedResponse) {
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

        if (!$validation->isValid()) {
            return ChatPipelineResult::shortCircuit(
                new SafeResponse($guardrails->outOfScopeMessage(), (string) $validation->reasonCode())
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
