<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Playground;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantResponse;
use Aavirbhava\AiShoppingAssistant\Model\Chat\SafeResponse;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\AvailabilityStatus;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;

/**
 * Everything the admin Playground's panels need for one query, assembled
 * entirely from real, already-DI-wired services (PlaygroundQueryRunner) —
 * this class holds no logic of its own, only the intermediate outputs each
 * pipeline stage already naturally produces (or, for ranking/tool-calling,
 * captures via the Task 9 debug-collector seams).
 *
 * Unlike every other DTO in this module, this one does not validate its
 * own invariants at construction — it is assembled in exactly one place
 * (PlaygroundQueryRunner), by trusted internal code, never deserialized
 * from external input, and consumed only by the admin Block/template. The
 * constructor-validation discipline the rest of this module follows exists
 * to protect boundaries this class never crosses.
 *
 * @param list<\Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate> $retrievedCandidates
 *     the merged BM25+vector candidate list exactly as HybridRetrievalService
 *     returned it — bm25Score/vectorScore on each candidate are the real,
 *     per-query raw scores, which is also what the "BM25 results" and
 *     "vector results" panels are built from (no separate retrieval calls)
 * @param list<array{signal: string, candidates: list<\Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate>}> $rankingStages
 *     the candidate list's state after each ranking signal ran, in order
 * @param list<\Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate> $rankedCandidates
 *     the final, ranked, capped candidate list RankingPipeline returned
 * @param list<AvailabilityStatus> $revalidationOutcomes one entry per
 *     ranked candidate's SKU — found/inStock, exactly what
 *     LiveRevalidationServiceInterface::checkAvailability() reports; this
 *     is the most granular live-validation reason this module's services
 *     expose (see the status report's Honesty notes)
 * @param list<RevalidatedProduct> $verifiedProducts the subset that fully
 *     passed revalidate() — what the LLM's product context is built from
 * @param list<array{round: int, response: \Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse}> $llmRounds
 *     every raw LLM call this turn made, in order, only populated when
 *     $llmWasCalled is true
 * @param list<array{call: \Aavirbhava\AiShoppingAssistant\Model\Dto\ToolCall, result: \Aavirbhava\AiShoppingAssistant\Model\Tool\ToolResult}> $toolExecutions
 *     every tool call the model made and its real result, only populated
 *     when $llmWasCalled is true
 */
final class PlaygroundResult
{
    public function __construct(
        public readonly string $queryText,
        public readonly int $storeId,
        public readonly bool $inScope,
        public readonly ?string $scopeReasonCode,
        public readonly array $retrievedCandidates,
        public readonly array $rankingStages,
        public readonly array $rankedCandidates,
        public readonly bool $rerankerConfigured,
        public readonly array $revalidationOutcomes,
        public readonly array $verifiedProducts,
        public readonly ?string $productContextText,
        public readonly bool $llmWasCalled,
        public readonly array $llmRounds,
        public readonly array $toolExecutions,
        public readonly ?AssistantResponse $finalResponse,
        public readonly ?SafeResponse $safeResponse,
        public readonly ?string $llmError,
        public readonly ?string $llmProvider,
        public readonly ?string $llmModel,
        public readonly ?int $totalInputTokens,
        public readonly ?int $totalOutputTokens,
        public readonly ?int $totalCachedTokens,
        public readonly ?int $totalLatencyMilliseconds
    ) {
    }
}
