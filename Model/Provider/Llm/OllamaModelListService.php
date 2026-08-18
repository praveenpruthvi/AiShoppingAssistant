<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Api\Provider\OllamaModelListServiceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Magento\Framework\HTTP\LaminasClient;

/**
 * Fetches the list of models actually pulled on a local Ollama server, for
 * the admin "Fetch Ollama Models" button (Model\Adminhtml\System\Config\
 * OllamaModelField). Deliberately separate from ChatHttpTransport/
 * AbstractChatProvider: this calls Ollama's own native GET /api/tags
 * (verified against a real running Ollama instance before writing this —
 * not the OpenAI-compatible chat endpoint those classes speak, and not a
 * capability every OpenAI-compatible server shares, so it stays scoped to
 * Ollama specifically rather than folded into the generic provider), it is
 * a GET, not a POST, and it is diagnostic admin tooling rather than part
 * of the customer-facing runtime pipeline — a failure here is reported
 * back for the admin to read, never thrown into that pipeline's exception
 * taxonomy.
 *
 * Never throws: every failure mode (missing/invalid base URL, unreachable
 * server, non-2xx response, malformed body) is reported through
 * OllamaModelListResult::failure() with a clean, honest message — this
 * fetch action never fabricates success and never leaks a raw exception
 * message or the configured URL.
 */
final class OllamaModelListService implements OllamaModelListServiceInterface
{
    private const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;
    private const DEFAULT_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly LaminasClient $client,
        private readonly HttpUrlPolicy $urlPolicy
    ) {
    }

    public function fetchModels(string $baseUrl, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): OllamaModelListResult
    {
        $tagsUrl = $this->tagsEndpoint($baseUrl);

        if ($tagsUrl === null) {
            return OllamaModelListResult::failure((string) __('The base URL is not configured.'));
        }

        if (!$this->urlPolicy->isAllowed($tagsUrl)) {
            return OllamaModelListResult::failure((string) __('The configured base URL is not allowed.'));
        }

        $this->client->setOptions([
            'maxredirects' => 0,
            'timeout' => max(1, $timeoutSeconds),
            'verifypeer' => true,
            'verifyhost' => 2,
        ]);
        $this->client->setMethod('GET');
        $this->client->setUri($tagsUrl);
        $this->client->setHeaders(['Accept' => 'application/json']);

        try {
            $response = $this->client->send();
        } catch (\Exception) {
            return OllamaModelListResult::failure((string) __('Unable to reach the Ollama server.'));
        }

        $statusCode = (int) $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            return OllamaModelListResult::failure((string) __('The Ollama server returned an unexpected response.'));
        }

        $body = $response->getBody();

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            return OllamaModelListResult::failure((string) __('The Ollama server response is too large.'));
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return OllamaModelListResult::failure((string) __('The Ollama server returned an invalid response.'));
        }

        if (!is_array($payload) || !is_array($payload['models'] ?? null)) {
            return OllamaModelListResult::failure((string) __('The Ollama server returned an unexpected response.'));
        }

        return OllamaModelListResult::success($this->modelNames($payload['models']));
    }

    /**
     * @param array<mixed> $entries
     *
     * @return list<string>
     */
    private function modelNames(array $entries): array
    {
        $names = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = $entry['name'] ?? $entry['model'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Ollama's own native model-listing endpoint (/api/tags) lives at the
     * server root, not under the /v1 prefix the OpenAI-compatible chat
     * endpoint uses — the same base URL configured for llm/base_url or
     * fallback/base_url (e.g. "http://127.0.0.1:11434/v1") needs a
     * trailing /v1 stripped before /api/tags is appended.
     */
    private function tagsEndpoint(string $baseUrl): ?string
    {
        $trimmed = rtrim(trim($baseUrl), '/');

        if ($trimmed === '') {
            return null;
        }

        $trimmed = preg_replace('#/v1$#i', '', $trimmed) ?? $trimmed;
        $trimmed = rtrim($trimmed, '/');

        return $trimmed . '/api/tags';
    }
}
