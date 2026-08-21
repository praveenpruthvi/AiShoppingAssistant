<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTransportException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\ProviderHttpResponse;
use Laminas\Http\Client\Adapter\AdapterInterface;
use Magento\Framework\HTTP\LaminasClient;
use Magento\Framework\Phrase;

/**
 * Bounded HTTP transport for chat/LLM provider requests.
 *
 * Same transport boundary as ProviderHttpTransport (URL sanity, bounded
 * timeout, no redirects, mandatory TLS verification, JSON headers, bounded
 * response body), but raises the generic Provider* exception hierarchy
 * instead of the embedding-specific one. ProviderHttpTransport hardcodes
 * Embedding*Exception classes, which do not extend the concrete
 * Provider*Exception classes that FallbackEligibilityPolicy checks via
 * instanceof; reusing it here would make transient chat failures silently
 * ineligible for fallback. This class keeps chat failures on the exception
 * hierarchy fallback actually recognizes.
 */
final class ChatHttpTransport
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
            throw new ProviderTransportException(
                new Phrase('The chat provider endpoint is not allowed.')
            );
        }

        $timeout = max(1, (int) ceil($timeoutSeconds));

        $this->client->setOptions([
            'maxredirects' => 0,
            'timeout' => $timeout,
            'verifypeer' => true,
            'verifyhost' => 2,
            // Magento\Framework\HTTP\Adapter\Curl (LaminasClient's own
            // default) passes headers to CURLOPT_HTTPHEADER as a raw
            // associative array instead of "Key: Value" strings, so every
            // header (including Content-Type and any provider auth header)
            // silently fails to reach the server. Confirmed live against
            // Google's real Gemini API: with the default adapter, the JSON
            // body arrives with no Content-Type at all and Google's gateway
            // tries to parse it as a query string ("Cannot bind query
            // parameter"). Ollama/local providers tolerate a missing
            // Content-Type; Google's does not. Laminas's own Curl adapter
            // formats headers correctly and is a safe drop-in replacement.
            'adapter' => \Laminas\Http\Client\Adapter\Curl::class,
        ]);

        $this->client->setMethod('POST');
        $this->client->setUri($url);
        $this->client->setHeaders(array_merge(self::DEFAULT_HEADERS, $headers));
        $this->client->setRawBody($body);

        try {
            $response = $this->client->send();
        } catch (\Exception $cause) {
            if ($this->looksLikeTimeout($cause)) {
                throw new ProviderTimeoutException(
                    new Phrase('The chat provider request timed out.'),
                    $cause
                );
            }

            throw new ProviderTransportException(
                new Phrase('Unable to contact the chat provider.'),
                $cause
            );
        }

        $responseBody = $response->getBody();

        if (strlen($responseBody) > self::MAX_RESPONSE_BYTES) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider response is too large.')
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
