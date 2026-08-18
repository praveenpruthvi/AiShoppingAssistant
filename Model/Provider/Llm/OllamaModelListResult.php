<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Llm;

/**
 * Outcome of fetching the pulled-model list from a local Ollama server —
 * mirrors ConnectionResult's success/failure value-object shape rather
 * than throwing, since OllamaModelListService's only caller is a diagnostic
 * admin AJAX action that always needs a clean, reportable outcome (success
 * with zero models is not a failure — "no models pulled yet" is an honest,
 * ordinary state, not an error).
 */
final readonly class OllamaModelListResult
{
    private function __construct(
        public bool $successful,
        public array $models,
        public ?string $message
    ) {
    }

    /**
     * @param list<string> $models
     */
    public static function success(array $models): self
    {
        return new self(true, $models, null);
    }

    public static function failure(string $message): self
    {
        return new self(false, [], $message);
    }
}
