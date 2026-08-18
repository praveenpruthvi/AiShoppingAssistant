<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Block\Adminhtml\Playground;

use Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Playground\Index as IndexController;
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
 */
class Index extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
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
     * @param list<SearchCandidate> $candidates
     */
    public function getCandidateTableHtml(array $candidates, string $scoreField): string
    {
        if ($candidates === []) {
            return '<p>' . $this->escapeHtml(__('No candidates.')) . '</p>';
        }

        $html = '<table class="data-grid"><thead><tr>'
            . '<th>' . $this->escapeHtml(__('SKU')) . '</th>'
            . '<th>' . $this->escapeHtml(__('Name')) . '</th>'
            . '<th>' . $this->escapeHtml(__('Score')) . '</th>'
            . '</tr></thead><tbody>';

        foreach ($candidates as $candidate) {
            $score = match ($scoreField) {
                'bm25Score' => $candidate->bm25Score,
                'vectorScore' => $candidate->vectorScore,
                default => $candidate->score,
            };

            $html .= '<tr>'
                . '<td>' . $this->escapeHtml($candidate->sku) . '</td>'
                . '<td>' . $this->escapeHtml($candidate->name) . '</td>'
                . '<td>' . $this->escapeHtml(number_format($score, 4)) . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function jsonPretty(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '{}';
    }
}
