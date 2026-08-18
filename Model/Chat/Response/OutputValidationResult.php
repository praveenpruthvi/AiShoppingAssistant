<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Response;

use LogicException;

/**
 * OutputValidator's decision for one ChatResponse: either the fully built
 * structured contract, or a reason code explaining why it was rejected.
 * Two-outcome value object, mirrors ScopeClassification/ConnectionResult.
 */
final readonly class OutputValidationResult
{
    private function __construct(
        private bool $valid,
        private ?AssistantResponse $response,
        private ?string $reasonCode
    ) {
    }

    public static function valid(AssistantResponse $response): self
    {
        return new self(true, $response, null);
    }

    public static function invalid(string $reasonCode): self
    {
        return new self(false, null, $reasonCode);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function response(): AssistantResponse
    {
        if ($this->response === null) {
            throw new LogicException('This validation result was not valid.');
        }

        return $this->response;
    }

    public function reasonCode(): ?string
    {
        return $this->reasonCode;
    }
}
