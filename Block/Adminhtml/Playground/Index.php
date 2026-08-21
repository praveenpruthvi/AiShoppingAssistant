<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Block\Adminhtml\Playground;

use Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Playground\Index as IndexController;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatResponseSerializer;
use Aavirbhava\AiShoppingAssistant\Model\Chat\OutputValidator;
use Aavirbhava\AiShoppingAssistant\Model\Playground\PlaygroundResult;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;

/**
 * Pure view: reads whatever Controller\Adminhtml\Playground\Index
 * registered for this request (a PlaygroundResult, an error message, or
 * neither on a first GET) and exposes it to the phtml template. Holds no
 * pipeline logic of its own — everything shown was computed by
 * PlaygroundQueryRunner already.
 *
 * getFormKey() is not defined here — Magento\Backend\Block\Template
 * (the parent class) already provides it via its own injected FormKey.
 *
 * Task 33 (visual-only redesign) added the status-badge/collapsible/raw-
 * JSON helper methods below. None of them compute anything new: every
 * value they format was already fully available on PlaygroundResult
 * before this task — see each method's own docblock for exactly which
 * existing field it re-presents.
 */
class Index extends Template
{
    /**
     * Native Magento admin message types this page's badges reuse for
     * their color-coding — success/error/warning/notice each already
     * carry Magento's own icon+semantics (see _messages.less); "notice"
     * is used for "this check was never reached" rather than inventing a
     * fifth, non-native color.
     */
    private const BADGE_TYPES = ['success', 'error', 'warning', 'notice'];

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly ChatResponseSerializer $responseSerializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSubmittedQuery(): string
    {
        return (string) ($this->registry->registry(IndexController::REGISTRY_KEY_QUERY) ?? '');
    }

    public function wasLlmRequested(): bool
    {
        return (bool) $this->registry->registry(IndexController::REGISTRY_KEY_CALL_LLM);
    }

    public function getError(): ?string
    {
        $error = $this->registry->registry(IndexController::REGISTRY_KEY_ERROR);

        return is_string($error) ? $error : null;
    }

    public function getResult(): ?PlaygroundResult
    {
        $result = $this->registry->registry(IndexController::REGISTRY_KEY_RESULT);

        return $result instanceof PlaygroundResult ? $result : null;
    }

    public function getTestConnectionUrl(): string
    {
        return $this->getUrl('aavirbhava_aishoppingassistant/playground/testConnection');
    }

    /**
     * @param list<SearchCandidate> $candidates
     *
     * @return list<SearchCandidate>
     */
    public function getSortedByBm25(array $candidates): array
    {
        $sorted = $candidates;
        usort($sorted, static fn (SearchCandidate $a, SearchCandidate $b): int => $b->bm25Score <=> $a->bm25Score);

        return array_values(array_filter($sorted, static fn (SearchCandidate $c): bool => $c->bm25Score > 0.0));
    }

    /**
     * @param list<SearchCandidate> $candidates
     *
     * @return list<SearchCandidate>
     */
    public function getSortedByVector(array $candidates): array
    {
        $sorted = $candidates;
        usort($sorted, static fn (SearchCandidate $a, SearchCandidate $b): int => $b->vectorScore <=> $a->vectorScore);

        return array_values(array_filter($sorted, static fn (SearchCandidate $c): bool => $c->vectorScore > 0.0));
    }

    /**
     * Builds the candidate table markup directly (reused across the BM25,
     * vector, per-ranking-signal-stage, and final-ranked panels) rather
     * than repeating the same loop four times in the template.
     *
     * $previousScores, when given, adds a "Δ" column showing exactly how
     * much this stage's own signal changed each candidate's running
     * score (score minus its entry in $previousScores, defaulting to 0.0
     * for a candidate not present there — i.e. a fresh score) — generic
     * to every signal, not boost-specific, since every signal's own
     * per-candidate contribution is equally useful to see explicitly
     * rather than only inferred by eye from two separate tables.
     *
     * @param list<SearchCandidate> $candidates
     * @param array<int, float>|null $previousScores entity id => score before this stage
     */
    public function getCandidateTableHtml(array $candidates, string $scoreField, ?array $previousScores = null): string
    {
        if ($candidates === []) {
            return '<p>' . $this->escapeHtml(__('No candidates.')) . '</p>';
        }

        $html = '<table class="data-grid"><thead><tr>'
            . '<th>' . $this->escapeHtml(__('SKU')) . '</th>'
            . '<th>' . $this->escapeHtml(__('Name')) . '</th>'
            . '<th>' . $this->escapeHtml(__('Score')) . '</th>';
        if ($previousScores !== null) {
            $html .= '<th>' . $this->escapeHtml(__('Δ this stage')) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($candidates as $candidate) {
            $score = match ($scoreField) {
                'bm25Score' => $candidate->bm25Score,
                'vectorScore' => $candidate->vectorScore,
                default => $candidate->score,
            };

            $html .= '<tr>'
                . '<td>' . $this->escapeHtml($candidate->sku) . '</td>'
                . '<td>' . $this->escapeHtml($candidate->name) . '</td>'
                . '<td>' . $this->escapeHtml(number_format($score, 4)) . '</td>';

            if ($previousScores !== null) {
                $delta = $score - ($previousScores[$candidate->entityId] ?? 0.0);
                $html .= '<td>' . $this->escapeHtml(($delta >= 0.0 ? '+' : '') . number_format($delta, 4)) . '</td>';
            }

            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * @param list<SearchCandidate> $candidates
     *
     * @return array<int, float> entity id => score
     */
    public function scoresByEntityId(array $candidates): array
    {
        $scores = [];
        foreach ($candidates as $candidate) {
            $scores[$candidate->entityId] = $candidate->score;
        }

        return $scores;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function jsonPretty(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '{}';
    }

    /**
     * The `data-mage-init` attribute value for one collapsible panel,
     * using Magento's own native `mage/collapsible` widget — the exact
     * same declarative pattern the real product-edit page's own
     * collapsible sections use (see
     * Magento\Catalog\Block\Adminhtml\Product\Edit\Tab\ChildTab's
     * template), not a bespoke accordion. $active controls whether the
     * panel starts open or closed.
     */
    public function getCollapsibleInitJson(bool $active): string
    {
        return $this->jsonPretty([
            'collapsible' => [
                'active' => $active,
                'openedState' => '_show',
                'closedState' => '_hide',
                'collapsible' => true,
                'animate' => 200,
            ],
        ]);
    }

    /**
     * Same underlying value already shown as plain "In scope"/"Out of
     * scope" text before this task — just badged.
     *
     * @return array{label: \Magento\Framework\Phrase, type: string}
     */
    public function getScopeBadge(PlaygroundResult $result): array
    {
        return $result->inScope
            ? ['label' => __('In scope'), 'type' => 'success']
            : ['label' => __('Out of scope'), 'type' => 'error'];
    }

    /**
     * Whether the fallback LLM provider (not the primary) produced this
     * turn's response — ChatResponse::usedFallback, computed by
     * FallbackChatGenerationService (Task 5) on every round already, but
     * never surfaced anywhere in Playground until this task. Read off
     * the last completed round so it reflects whatever actually produced
     * this turn's outcome; "Not run" when no round ever completed
     * (LLM not called, or the very first call itself errored).
     *
     * @return array{label: \Magento\Framework\Phrase, type: string}
     */
    public function getFallbackBadge(PlaygroundResult $result): array
    {
        if ($result->llmRounds === []) {
            return ['label' => __('Not run'), 'type' => 'notice'];
        }

        $lastRound = $result->llmRounds[array_key_last($result->llmRounds)];
        $usedFallback = $lastRound['response']->usedFallback;

        return $usedFallback
            ? ['label' => __('Yes'), 'type' => 'warning']
            : ['label' => __('No'), 'type' => 'success'];
    }

    /**
     * One badge per OutputValidator check (fabricated_sku/price/url,
     * malformed_response). OutputValidator::validate() fails CLOSED at
     * the first violation it finds (see its own docblock) — it does not
     * keep checking after that, so for a given turn we only ever
     * genuinely know one of two things: every check passed (a valid
     * AssistantResponse came back), or exactly one specific check failed
     * (SafeResponse::reasonCode). The other three checks in that failure
     * case were never reached at all, and are badged "Not run" rather
     * than a guessed pass — showing them as passed would claim knowledge
     * this class doesn't have.
     *
     * @return list<array{code: string, label: \Magento\Framework\Phrase, type: string}>
     */
    public function getValidationCheckBadges(PlaygroundResult $result): array
    {
        $checks = [
            OutputValidator::REASON_FABRICATED_SKU => __('Fabricated SKU'),
            OutputValidator::REASON_FABRICATED_PRICE => __('Fabricated Price'),
            OutputValidator::REASON_FABRICATED_URL => __('Fabricated URL'),
            OutputValidator::REASON_MALFORMED_RESPONSE => __('Malformed Response'),
        ];

        $badges = [];
        foreach ($checks as $code => $label) {
            $badges[] = ['code' => $code, 'label' => $label, 'type' => $this->validationCheckType($result, $code)];
        }

        return $badges;
    }

    private function validationCheckType(PlaygroundResult $result, string $code): string
    {
        if (!$result->llmWasCalled || $result->llmError !== null) {
            return 'notice';
        }

        if ($result->finalResponse !== null) {
            return 'success';
        }

        if ($result->safeResponse !== null && $result->safeResponse->reasonCode === $code) {
            return 'error';
        }

        return 'notice';
    }

    /**
     * Renders one small inline badge reusing Magento's own
     * message/message-{type} classes for color — the same classes this
     * page already used for the full-width safe-fallback/error alert
     * boxes, just laid out compactly via aavirbhava-playground-badge
     * (see this template's <style> block).
     *
     * @param \Magento\Framework\Phrase|string $label
     */
    public function getBadgeHtml($label, string $type): string
    {
        $safeType = in_array($type, self::BADGE_TYPES, true) ? $type : 'notice';

        return '<span class="aavirbhava-playground-badge message message-' . $safeType . ' ' . $safeType . '">'
            . $this->escapeHtml($label) . '</span>';
    }

    /**
     * The exact same AssistantResponse/SafeResponse object the "Final
     * Response" panel's human-readable view already renders — reusing
     * ChatResponseSerializer::serializeDisplayPayload() (Task 20/Controller
     * Chat\Send's own real serialization code, not a hand-rolled mirror
     * of it) so the products/follow_up_questions/actions shape here is
     * byte-for-byte the same shape a real customer-facing response uses.
     * `awaiting_confirmation` is omitted — that field is computed by
     * ChatEntryPipeline from a full ChatPipelineResult, which Playground
     * never constructs (PlaygroundQueryRunner calls the tool-calling/
     * validation services directly — see its own docblock), so there is
     * no real value to show here rather than a guessed one.
     */
    public function getFinalResponseJson(PlaygroundResult $result): ?string
    {
        if ($result->finalResponse !== null) {
            $response = $result->finalResponse;

            return $this->jsonPretty([
                'message' => $response->message,
                'reason_code' => null,
                ...$this->responseSerializer->serializeDisplayPayload($response),
                'metadata' => [
                    'provider' => $response->metadata->provider,
                    'model' => $response->metadata->model,
                    'fallback_used' => $response->metadata->fallbackUsed,
                ],
            ]);
        }

        if ($result->safeResponse !== null) {
            return $this->jsonPretty([
                'message' => $result->safeResponse->message,
                'reason_code' => $result->safeResponse->reasonCode,
                'products' => [],
                'follow_up_questions' => [],
                'actions' => [],
                'metadata' => null,
            ]);
        }

        return null;
    }
}
