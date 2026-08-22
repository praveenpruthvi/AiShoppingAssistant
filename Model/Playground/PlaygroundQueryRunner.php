<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Playground;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatGenerationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\CommerceScopeClassifierInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\OutputValidatorInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Playground\PlaygroundQueryRunnerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingPipelineInterface;
use Aavirbhava\AiShoppingAssistant\Api\Retrieval\HybridRetrievalServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ProductContextFormatter;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\LlmResponseSchema;
use Aavirbhava\AiShoppingAssistant\Model\Chat\SafeResponse;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ToolCallingChatService;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use Aavirbhava\AiShoppingAssistant\Model\Tool\CommerceToolRegistry;

/**
 * Runs a query through the real, already-DI-wired pipeline stages and
 * captures every intermediate output for the admin Playground — this class
 * adds no new pipeline logic of its own, only orchestration and capture.
 *
 * Deliberately does NOT call ChatEntryPipelineInterface::handle(): that
 * pipeline hard-stops at general.enabled/out-of-scope/etc., which is
 * correct for a real customer but wrong for a diagnostic tool an admin
 * would use specifically to test a store *before* it's enabled, or to see
 * what retrieval/ranking would have found even for a borderline query.
 * Scope classification still runs and is shown as its own panel, but never
 * blocks the downstream retrieval/ranking/revalidation panels from
 * running — those reflect what the pipeline COULD show a customer, not
 * only what it WOULD for this specific query.
 *
 * CART SAFETY: when the LLM step runs, it is given a purpose-built
 * CommerceToolRegistry excluding add_to_cart/remove_from_cart entirely —
 * not merely "offered but never confirmed." A Playground run has no way to
 * mutate a cart even if the model tries to call a mutating tool, because
 * that tool is never in the request's tools array in the first place.
 * cartId is also always null for the same reason ToolContext already
 * treats a null cartId as "no cart available" everywhere else in this
 * module — a second, independent layer of the same protection.
 */
final class PlaygroundQueryRunner implements PlaygroundQueryRunnerInterface
{
    private const EXCLUDED_TOOLS = ['add_to_cart', 'remove_from_cart'];

    public function __construct(
        private readonly StoreScopeProviderInterface $storeScopeProvider,
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly CommerceScopeClassifierInterface $scopeClassifier,
        private readonly HybridRetrievalServiceInterface $retrievalService,
        private readonly RankingPipelineInterface $rankingPipeline,
        private readonly LiveRevalidationServiceInterface $revalidationService,
        private readonly ProductContextFormatter $productContextFormatter,
        private readonly ChatGenerationServiceInterface $chatGenerationService,
        private readonly CommerceToolRegistryInterface $toolRegistry,
        private readonly OutputValidatorInterface $outputValidator
    ) {
    }

    public function run(int $storeId, string $queryText, bool $callLlm): PlaygroundResult
    {
        $this->storeScopeProvider->requireActive($storeId);

        $classification = $this->scopeClassifier->classify($storeId, $queryText);

        $retrieved = $this->retrievalService->retrieve($storeId, $queryText);

        $retrievalConfig = $this->configurationReader->readRetrieval($storeId);
        $rankingCollector = new PlaygroundRankingCollector();
        $searchContext = new SearchContext($storeId, $queryText, $retrievalConfig->isRerankerEnabled());
        $ranked = $this->rankingPipeline->rank($searchContext, $retrieved, $rankingCollector);

        $skus = $this->skus($ranked);
        $availability = $this->revalidationService->checkAvailability($storeId, null, $skus);
        $verified = $this->revalidationService->revalidate($storeId, null, $skus);

        $contextMessage = $this->productContextFormatter->format($storeId, $ranked);

        $llmRounds = [];
        $toolExecutions = [];
        $finalResponse = null;
        $safeResponse = null;
        $llmError = null;
        $provider = null;
        $model = null;
        $inputTokens = null;
        $outputTokens = null;
        $cachedTokens = null;
        $latencyMs = null;

        if ($callLlm) {
            $toolCallCollector = new PlaygroundToolCallCollector();
            $toolCallingService = new ToolCallingChatService(
                $this->chatGenerationService,
                $this->cartSafeToolRegistry(),
                $this->configurationReader
            );

            $messages = $contextMessage !== null
                ? [$contextMessage, new ChatMessage('user', $queryText)]
                : [new ChatMessage('user', $queryText)];

            try {
                $toolResult = $toolCallingService->converse(
                    $storeId,
                    null,
                    null,
                    $messages,
                    LlmResponseSchema::schema(),
                    $toolCallCollector
                );

                $validation = $this->outputValidator->validate($toolResult->response, $verified);

                if ($validation->isValid()) {
                    $finalResponse = $validation->response();
                } else {
                    $safeResponse = new SafeResponse(
                        'The response would have been rejected by the Output Validator.',
                        (string) $validation->reasonCode()
                    );
                }
            } catch (ProviderException $exception) {
                $llmError = $exception->errorCode();
            }

            $llmRounds = $toolCallCollector->rounds;
            $toolExecutions = $toolCallCollector->toolExecutions;

            foreach ($llmRounds as $round) {
                $response = $round['response'];
                $inputTokens = ($inputTokens ?? 0) + $response->usage->inputTokens;
                $outputTokens = ($outputTokens ?? 0) + $response->usage->outputTokens;
                $cachedTokens = ($cachedTokens ?? 0) + $response->usage->cachedInputTokens;
                $latencyMs = ($latencyMs ?? 0) + $response->latencyMilliseconds;
                $provider = $response->provider;
                $model = $response->model;
            }
        }

        return new PlaygroundResult(
            $queryText,
            $storeId,
            $classification->isInScope(),
            $classification->reasonCode(),
            $retrieved,
            $rankingCollector->stages,
            $ranked,
            $retrievalConfig->isRerankerEnabled(),
            $availability,
            $verified,
            $contextMessage?->content,
            $callLlm,
            $llmRounds,
            $toolExecutions,
            $finalResponse,
            $safeResponse,
            $llmError,
            $provider,
            $model,
            $inputTokens,
            $outputTokens,
            $cachedTokens,
            $latencyMs
        );
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

    private function cartSafeToolRegistry(): CommerceToolRegistryInterface
    {
        $safeTools = [];

        foreach ($this->toolRegistry->all() as $name => $tool) {
            if (in_array($name, self::EXCLUDED_TOOLS, true)) {
                continue;
            }

            $safeTools[$name] = $tool;
        }

        return new CommerceToolRegistry($safeTools);
    }
}
