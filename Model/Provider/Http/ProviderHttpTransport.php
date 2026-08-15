<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Http;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingTransportException;
use Laminas\Http\Client\Adapter\AdapterInterface;
use Magento\Framework\HTTP\LaminasClient;
use Magento\Framework\Phrase;

/**
 * Bounded HTTP transport for provider requests.
 *
 * Enforces the transport boundary in one place: URL sanity (no credentials,
 * fragments, or unsupported schemes), a bounded timeout, no redirect following,
 * mandatory TLS verification that can never be disabled, JSON content headers,
 * and a bounded response body. The shared Laminas client is reset for every
 * call and used only within a single request lifecycle.
 *
 * Every failure surfaces as a sanitized embedding exception; raw URLs, headers,
 * bodies, and credentials are never placed in messages.
 */
final class ProviderHttpTransport
{
    public const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;

    private const DEFAULT_HEADERS = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    public function __construct(
        private readonly LaminasClient $client,
        private readonly HttpUrlPolicy $urlPolicy
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, array $headers, string $body, float $timeoutSeconds): ProviderHttpResponse
    {
        if (!$this->urlPolicy->isAllowed($url)) {
            throw new EmbeddingTransportException(
                new Phrase('The embedding provider endpoint is not allowed.')
            );
        }

        $timeout = max(1, (int) ceil($timeoutSeconds));

        $this->client->setOptions([
            'maxredirects' => 0,
            'timeout' => $timeout,
            'verifypeer' => true,
            'verifyhost' => 2,
        ]);

        $this->client->setMethod('POST');
        $this->client->setUri($url);
        $this->client->setHeaders(array_merge(self::DEFAULT_HEADERS, $headers));
        $this->client->setRawBody($body);

        try {
            $response = $this->client->send();
        } catch (\Exception $cause) {
            if ($this->looksLikeTimeout($cause)) {
                throw new EmbeddingTimeoutException(
                    new Phrase('The embedding provider request timed out.'),
                    $cause
                );
            }

            throw new EmbeddingTransportException(
                new Phrase('Unable to contact the embedding provider.'),
                $cause
            );
        }

        $responseBody = $response->getBody();

        if (strlen($responseBody) > self::MAX_RESPONSE_BYTES) {
            throw new EmbeddingResponseException(
                new Phrase('The embedding provider response is too large.')
            );
        }

        return new ProviderHttpResponse(
            (int) $response->getStatusCode(),
            $responseBody
        );
    }

    private function looksLikeTimeout(\Exception $cause): bool
    {
        $message = strtolower($cause->getMessage());

        if (str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curle_operation_timedout')
        ) {
            return true;
        }

        try {
            $adapter = $this->client->getAdapter();
        } catch (\Throwable) {
            return false;
        }

        if ($adapter instanceof AdapterInterface
            && method_exists($adapter, 'getErrno')
            && $adapter->getErrno() === CURLE_OPERATION_TIMEDOUT
        ) {
            return true;
        }

        return false;
    }
}
