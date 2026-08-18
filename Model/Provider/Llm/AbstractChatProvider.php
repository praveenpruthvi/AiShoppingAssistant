<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ConnectionResult;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ToolCall;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRateLimitException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRefusalException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderUnavailableException;
use InvalidArgumentException;
use Magento\Framework\Phrase;

/**
 * Shared chat-adapter pipeline: request validation, endpoint resolution,
 * the HTTP call, status mapping, response decoding, tool-call/usage
 * parsing, and testConnection() — everything that is genuinely identical
 * across every provider speaking the OpenAI chat/completions wire format
 * (OpenAiProvider, OpenAiCompatibleProvider). Mirrors
 * Model\Provider\Embedding\AbstractEmbeddingProvider's own split on the
 * embedding side exactly: subclasses define only the endpoint, headers,
 * and request-body shape.
 *
 * Task 1 explicitly deferred this extraction ("only one adapter exists —
 * premature"); Task 13 performs it now that a second, genuinely
 * differently-configured adapter exists. buildRequestBody() stays
 * concrete/shared here rather than abstract, because messages/tools/
 * response_format all use the identical OpenAI wire shape on both
 * providers (confirmed for Ollama's /v1/chat/completions endpoint before
 * writing this) — maxOutputTokensField() is the one confirmed, real
 * difference (OpenAI's current API accepts `max_completion_tokens`;
 * Ollama's OpenAI-compatible layer documents and exercises the older
 * `max_tokens` field only, per Ollama's own docs example and an open,
 * unresolved upstream issue tracking `max_completion_tokens` support).
 */
abstract class AbstractChatProvider implements LlmProviderInterface
{
    private const TEST_CONNECTION_MAX_OUTPUT_TOKENS = 16;

    public function __construct(
        private readonly ChatHttpTransport $transport,
        private readonly ChatEndpointPolicy $endpointPolicy
    ) {
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $this->assertValidRequest($request);

        $endpoint = $this->endpointPolicy->chatEndpoint(
            $this->identifier(),
            $request->baseUrl,
            $this->defaultBaseUrl()
        );

        $startedAt = microtime(true);

        $response = $this->transport->post(
            $endpoint,
            $this->buildHeaders($request->apiKey),
            $this->encodeRequestBody($this->buildRequestBody($request)),
            (float) $request->timeoutSeconds
        );

        $latencyMilliseconds = (int) round((microtime(true) - $startedAt) * 1000);

        $this->assertSuccessStatus($response->statusCode());

        return $this->parseResponse($response->body(), $request->model, $latencyMilliseconds);
    }

    public function testConnection(
        int $storeId,
        string $model,
        string $baseUrl,
        SecretValue $apiKey,
        int $timeoutSeconds
    ): ConnectionResult {
        try {
            $this->chat(new ChatRequest(
                storeId: $storeId,
                messages: [new ChatMessage('user', 'Reply with OK.')],
                model: $model,
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                timeoutSeconds: $timeoutSeconds,
                maxOutputTokens: self::TEST_CONNECTION_MAX_OUTPUT_TOKENS
            ));

            return ConnectionResult::success();
        } catch (ProviderException $exception) {
            return ConnectionResult::failure($exception->getMessage(), $exception->errorCode());
        }
    }

    protected abstract function defaultBaseUrl(): string;

    protected abstract function apiKeyRequired(): bool;

    /**
     * @return array<string, string>
     */
    protected abstract function buildHeaders(SecretValue $apiKey): array;

    /**
     * The field name used to bound output length — the one confirmed wire
     * difference between providers speaking this same OpenAI format (see
     * class docblock).
     */
    protected abstract function maxOutputTokensField(): string;

    private function assertValidRequest(ChatRequest $request): void
    {
        if ($this->apiKeyRequired() && $request->apiKey->isEmpty()) {
            throw new ProviderConfigurationException(
                new Phrase('The API key is not configured for the chat provider.')
            );
        }

        if ($request->model === '') {
            throw new ProviderConfigurationException(
                new Phrase('The chat model is not configured.')
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestBody(ChatRequest $request): array
    {
        $body = [
            'model' => $request->model,
            'messages' => array_map(
                static fn (ChatMessage $message): array => self::buildMessage($message),
                $request->messages
            ),
            $this->maxOutputTokensField() => $request->maxOutputTokens,
        ];

        if ($request->tools !== []) {
            $body['tools'] = array_map(
                fn (array $tool): array => $this->buildTool($tool),
                $request->tools
            );
            $body['tool_choice'] = 'auto';
        }

        if ($request->responseSchema !== null) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'response',
                    'schema' => $request->responseSchema,
                    'strict' => true,
                ],
            ];
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildMessage(ChatMessage $message): array
    {
        $entry = [
            'role' => $message->role,
            'content' => $message->content,
        ];

        if ($message->toolCallId !== null) {
            $entry['tool_call_id'] = $message->toolCallId;
        }

        if ($message->toolCalls !== []) {
            $entry['tool_calls'] = array_map(
                static fn (ToolCall $toolCall): array => [
                    'id' => $toolCall->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $toolCall->name,
                        'arguments' => self::encodeToolCallArguments($toolCall),
                    ],
                ],
                $message->toolCalls
            );
        }

        return $entry;
    }

    private static function encodeToolCallArguments(ToolCall $toolCall): string
    {
        try {
            return json_encode($toolCall->arguments, JSON_THROW_ON_ERROR);
        } catch (\JsonException $cause) {
            throw new ProviderConfigurationException(
                new Phrase('The chat request could not be prepared.'),
                $cause
            );
        }
    }

    /**
     * @param array<string, mixed> $tool
     *
     * @return array<string, mixed>
     */
    private function buildTool(array $tool): array
    {
        $name = $tool['name'] ?? null;

        if (!is_string($name) || $name === '') {
            throw new ProviderConfigurationException(
                new Phrase('A chat tool definition is missing a valid name.')
            );
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => is_string($tool['description'] ?? null) ? $tool['description'] : '',
                'parameters' => is_array($tool['parameters'] ?? null)
                    ? $tool['parameters']
                    : ['type' => 'object', 'properties' => new \stdClass()],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function encodeRequestBody(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $cause) {
            throw new ProviderConfigurationException(
                new Phrase('The chat request could not be prepared.'),
                $cause
            );
        }
    }

    private function assertSuccessStatus(int $statusCode): void
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        if (in_array($statusCode, [401, 403], true)) {
            throw new ProviderAuthenticationException(
                new Phrase('The chat provider rejected the request.')
            );
        }

        if ($statusCode === 429) {
            throw new ProviderRateLimitException(
                new Phrase('The chat provider is temporarily limiting requests.')
            );
        }

        if (in_array($statusCode, [408, 504], true)) {
            throw new ProviderTimeoutException(
                new Phrase('The chat provider request timed out.')
            );
        }

        if ($statusCode >= 500) {
            throw new ProviderUnavailableException(
                new Phrase('The chat provider is temporarily unavailable.')
            );
        }

        throw new ProviderInvalidResponseException(
            new Phrase('The chat provider returned an unexpected response.')
        );
    }

    private function parseResponse(string $responseBody, string $requestedModel, int $latencyMilliseconds): ChatResponse
    {
        try {
            $payload = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $cause) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider returned an invalid response.'),
                $cause
            );
        }

        if (!is_array($payload)) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider returned an invalid response.')
            );
        }

        $choices = $payload['choices'] ?? null;

        if (!is_array($choices) || !isset($choices[0]) || !is_array($choices[0])) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider response is missing a completion.')
            );
        }

        $message = $choices[0]['message'] ?? null;

        if (!is_array($message)) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider response is missing a message.')
            );
        }

        $refusal = $message['refusal'] ?? null;

        if (is_string($refusal) && $refusal !== '') {
            throw new ProviderRefusalException(
                new Phrase('The chat provider refused to generate a response.')
            );
        }

        $text = $message['content'] ?? '';
        $text = is_string($text) ? $text : '';

        $toolCalls = $this->parseToolCalls($message['tool_calls'] ?? []);

        if ($text === '' && $toolCalls === []) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider returned an empty response.')
            );
        }

        $resolvedModel = $payload['model'] ?? null;
        $resolvedModel = is_string($resolvedModel) && $resolvedModel !== '' ? $resolvedModel : $requestedModel;

        try {
            return new ChatResponse(
                $text,
                $toolCalls,
                $this->parseUsage($payload['usage'] ?? []),
                $this->identifier(),
                $resolvedModel,
                $latencyMilliseconds
            );
        } catch (InvalidArgumentException $cause) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider returned an invalid response.'),
                $cause
            );
        }
    }

    /**
     * @param mixed $rawToolCalls
     *
     * @return list<ToolCall>
     */
    private function parseToolCalls(mixed $rawToolCalls): array
    {
        if ($rawToolCalls === null) {
            return [];
        }

        if (!is_array($rawToolCalls)) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider response contains invalid tool calls.')
            );
        }

        $toolCalls = [];

        foreach ($rawToolCalls as $rawToolCall) {
            if (!is_array($rawToolCall)) {
                throw new ProviderInvalidResponseException(
                    new Phrase('The chat provider response contains an invalid tool call.')
                );
            }

            $id = $rawToolCall['id'] ?? null;
            $function = $rawToolCall['function'] ?? null;

            if (!is_string($id) || $id === '' || !is_array($function)) {
                throw new ProviderInvalidResponseException(
                    new Phrase('The chat provider response contains an invalid tool call.')
                );
            }

            $name = $function['name'] ?? null;
            $rawArguments = $function['arguments'] ?? null;

            if (!is_string($name) || $name === '' || !is_string($rawArguments)) {
                throw new ProviderInvalidResponseException(
                    new Phrase('The chat provider response contains an invalid tool call.')
                );
            }

            try {
                $arguments = json_decode($rawArguments, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $cause) {
                throw new ProviderInvalidResponseException(
                    new Phrase('The chat provider response contains invalid tool-call arguments.'),
                    $cause
                );
            }

            if (!is_array($arguments)) {
                throw new ProviderInvalidResponseException(
                    new Phrase('The chat provider response contains invalid tool-call arguments.')
                );
            }

            try {
                $toolCalls[] = new ToolCall($id, $name, $arguments);
            } catch (InvalidArgumentException $cause) {
                throw new ProviderInvalidResponseException(
                    new Phrase('The chat provider response contains an invalid tool call.'),
                    $cause
                );
            }
        }

        return $toolCalls;
    }

    /**
     * @param mixed $rawUsage
     */
    private function parseUsage(mixed $rawUsage): TokenUsage
    {
        if (!is_array($rawUsage)) {
            return new TokenUsage(0, 0);
        }

        $inputTokens = $this->usageTokenCount($rawUsage['prompt_tokens'] ?? null) ?? 0;
        $outputTokens = $this->usageTokenCount($rawUsage['completion_tokens'] ?? null) ?? 0;

        $details = $rawUsage['prompt_tokens_details'] ?? null;
        $cachedTokens = is_array($details) ? $this->usageTokenCount($details['cached_tokens'] ?? null) ?? 0 : 0;
        $cachedTokens = min($cachedTokens, $inputTokens);

        try {
            return new TokenUsage($inputTokens, $outputTokens, $cachedTokens);
        } catch (InvalidArgumentException $cause) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider returned invalid usage data.'),
                $cause
            );
        }
    }

    private function usageTokenCount(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
