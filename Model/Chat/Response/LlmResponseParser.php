<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Response;

/**
 * Decodes ChatResponse::text (expected to be a JSON string matching
 * LlmResponseSchema, since the request asked for structured output) into a
 * ParsedLlmOutput. Returns null on any malformed shape — the LLM not
 * honoring the requested schema is an expected, ordinary possible outcome
 * here, not an exceptional one; OutputValidator treats a null parse as one
 * of its "invalid" triggers.
 *
 * Tolerates one common, non-malicious formatting artifact before giving up:
 * the whole payload wrapped in a markdown code fence (```json ... ``` or
 * ``` ... ```), observed live from a local/Ollama-served model even when
 * explicitly instructed to return raw JSON. This only strips a wrapping
 * fence around what's already presumed to be the entire response — it does
 * not attempt to locate/extract JSON from surrounding prose, which would
 * risk silently accepting a response that didn't actually comply.
 */
final class LlmResponseParser
{
    public function parse(string $rawText): ?ParsedLlmOutput
    {
        try {
            $decoded = json_decode($this->stripCodeFence($rawText), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $message = $decoded['message'] ?? null;
        if (!is_string($message) || $message === '') {
            return null;
        }

        $productSkus = $this->parseProductSkus($decoded['product_skus'] ?? null);
        if ($productSkus === null) {
            return null;
        }

        $followUpQuestions = $this->parseStringList($decoded['follow_up_questions'] ?? null);
        if ($followUpQuestions === null) {
            return null;
        }

        $actions = $this->parseActions($decoded['actions'] ?? null);
        if ($actions === null) {
            return null;
        }

        return new ParsedLlmOutput($message, $productSkus, $followUpQuestions, $actions);
    }

    private function stripCodeFence(string $rawText): string
    {
        $trimmed = trim($rawText);

        if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return $rawText;
    }

    /**
     * @return list<array{sku: string, reason: string}>|null
     */
    private function parseProductSkus(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $parsed = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                return null;
            }

            $sku = $entry['sku'] ?? null;
            $reason = $entry['reason'] ?? null;

            if (!is_string($sku) || $sku === '' || !is_string($reason) || $reason === '') {
                return null;
            }

            $parsed[] = ['sku' => $sku, 'reason' => $reason];
        }

        return $parsed;
    }

    /**
     * @return list<array{type: string, skus: list<string>}>|null
     */
    private function parseActions(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $parsed = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                return null;
            }

            $type = $entry['type'] ?? null;
            if (!is_string($type) || $type === '') {
                return null;
            }

            $skus = $this->parseStringList($entry['skus'] ?? null, allowEmptyStrings: false);
            if ($skus === null) {
                return null;
            }

            $parsed[] = ['type' => $type, 'skus' => $skus];
        }

        return $parsed;
    }

    /**
     * @return list<string>|null
     */
    private function parseStringList(mixed $raw, bool $allowEmptyStrings = true): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $parsed = [];
        foreach ($raw as $value) {
            if (!is_string($value) || (!$allowEmptyStrings && $value === '')) {
                return null;
            }

            $parsed[] = $value;
        }

        return $parsed;
    }
}
