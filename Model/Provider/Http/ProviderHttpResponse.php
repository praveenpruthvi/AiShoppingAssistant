<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Http;

/**
 * HTTP boundary value object: an immutable provider HTTP response.
 *
 * Carries only the status code and the raw response body. Never carries
 * headers, credentials, or request data.
 */
final readonly class ProviderHttpResponse
{
    public function __construct(
        private int $statusCode,
        private string $body
    ) {
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function body(): string
    {
        return $this->body;
    }
}