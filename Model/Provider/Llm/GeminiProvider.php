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
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRefusalException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;
use InvalidArgumentException;
use Magento\Framework\Phrase;

/**
 * Google Gemini `generateContent` API adapter
 * (`POST /v1beta/models/{model}:generateContent`).
 *
 * Built to spec against Google's published Generative Language API
 * reference; no live API key was available to this session to exercise a
 * real call — see the accompanying status report for exactly what is and
 * isn't verified. Implements LlmProviderInterface directly, not
 * AbstractChatProvider — Gemini's wire format differs from OpenAI's in
 * ways at least as load-bearing as Anthropic's:
 *
 * - The model is part of the URL PATH, not the request body.
 * - Turn roles are `user`/`model` (never `assistant`), and content is an
 *   array of `parts` (`{text: ...}` / `{functionCall: ...}` /
 *   `{functionResponse: ...}`), never a plain string field.
 * - The system prompt is a top-level `systemInstruction` field, exactly
 *   like Anthropic's `system` — never a role inside the turn array.
 * - A tool RESULT (`ChatMessage(role: 'tool', ...)`) is addressed by
 *   FUNCTION NAME, not by an opaque call id the way OpenAI/Anthropic key
 *   theirs — Gemini's `functionResponse.name` has no id concept at all.
 *   This adapter resolves the real name from this request's own message
 *   history (every assistant/`model` turn's real ToolCall::name, matched
 *   by ToolCall::id) rather than attempting to parse it back out of
 *   anything — the id/name pairing already exists in the conversation
 *   this module built; nothing needs to be invented or guessed.
 * - Gemini's own response never gives a tool call an id at all, so this
 *   adapter synthesizes one purely for this module's own internal
 *   ToolCall/tool_call_id round-tripping (never sent back to Gemini,
 *   never parsed back out of anything — see the request-side note above
 *   for why the round trip still works correctly).
 * - `max_tokens` lives inside a nested `generationConfig.maxOutputTokens`
 *   field, not a top-level field.
 * - Real, documented structured-output support exists
 *   (`generationConfig.responseMimeType`/`responseSchema`) — unlike
 *   Anthropic, capabilities() reports structuredOutput: true here, and a
 *   provided responseSchema is genuinely forwarded.
 * - Usage field names differ again (`promptTokenCount`/
 *   `candidatesTokenCount`, plus a real `cachedContentTokenCount` mapped
 *   onto this module's own `cachedInputTokens` concept).
 */
final class GeminiProvider implements LlmProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';
    private const TEST_CONNECTION_MAX_OUTPUT_TOKENS = 16;

    public function __construct(
        private readonly ChatHttpTransport $transport,
        private readonly HttpUrlPolicy $urlPolicy,
        private readonly HttpStatusMapper $statusMapper
    ) {
    }

    public function identifier(): string
    {
        return ProviderIdentifiers::LLM_GOOGLE;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            chatGeneration: true,
            toolCalling: true,
            structuredOutput: true,
            apiKeyOptional: false,
            configurableBaseUrl: false
        );
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $this->assertValidRequest($request);

        $endpoint = $this->resolveEndpoint($request->baseUrl, $request->model);

        $startedAt = microtime(true);

        $response = $this->transport->post(
            $endpoint,
            $this->buildHeaders($request->apiKey),
            $this->encodeRequestBody($this->buildRequestBody($request)),
            (float) $request->timeoutSeconds
        );

        $latencyMilliseconds = (int) round((microtime(true) - $startedAt) * 1000);

        $this->statusMapper->assertSuccess($response->statusCode());

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

    private function assertValidRequest(ChatRequest $request): void
    {
        if ($request->apiKey->isEmpty()) {
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

    private function resolveEndpoint(string $configuredBaseUrl, string $model): string
    {
        $defaultBaseUrl = rtrim(self::DEFAULT_BASE_URL, '/');

        if ($configuredBaseUrl !== ''
            && (strcasecmp(rtrim($configuredBaseUrl, '/'), $defaultBaseUrl) !== 0
                || !$this->urlPolicy->isAllowed($configuredBaseUrl, true))
        ) {
            throw new ProviderConfigurationException(
                new Phrase('A custom API endpoint is not allowed for this chat provider.')
            );
        }

        return $defaultBaseUrl . '/models/' . rawurlencode($model) . ':generateContent';
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(SecretValue $apiKey): array
    {
        return [
            'x-goog-api-key' => $apiKey->reveal(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestBody(ChatRequest $request): array
    {
        $systemText = $this->extractSystemText($request->messages);
        $toolNameById = $this->buildToolNameById($request->messages);

        $body = [
            'contents' => array_values(array_map(
                fn (ChatMessage $message): array => $this->buildContent($message, $toolNameById),
                array_filter($request->messages, static fn (ChatMessage $message): bool => $message->role !== 'system')
            )),
            'generationConfig' => [
                'maxOutputTokens' => $request->maxOutputTokens,
            ],
        ];

        if ($systemText !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $systemText]]];
        }

        if ($request->tools !== []) {
            $body['tools'] = [
                ['functionDeclarations' => array_map(
                    fn (array $tool): array => $this->buildFunctionDeclaration($tool),
                    $request->tools
                )],
            ];
        }

        if ($request->responseSchema !== null) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseSchema'] = $request->responseSchema;
        }

        return $body;
    }

    /**
     * @param list<ChatMessage> $messages
     */
    private function extractSystemText(array $messages): string
    {
        $systemTexts = [];

        foreach ($messages as $message) {
            if ($message->role === 'system') {
                $systemTexts[] = $message->content;
            }
        }

        return implode("\n\n", $systemTexts);
    }

    /**
     * Gemini addresses a tool result by function NAME, not by the opaque
     * call id every other part of this module keys on — this builds the
     * id-to-name lookup from the real ToolCall objects already present on
     * every assistant turn in this same request's own history, rather
     * than inventing or parsing anything.
     *
     * @param list<ChatMessage> $messages
     *
     * @return array<string, string>
     */
    private function buildToolNameById(array $messages): array
    {
        $names = [];

        foreach ($messages as $message) {
            foreach ($message->toolCalls as $toolCall) {
                $names[$toolCall->id] = $toolCall->name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, string> $toolNameById
     *
     * @return array<string, mixed>
     */
    private function buildContent(ChatMessage $message, array $toolNameById): array
    {
        if ($message->role === 'tool') {
            $name = $toolNameById[$message->toolCallId] ?? null;

            if ($name === null) {
                throw new ProviderConfigurationException(
                    new Phrase('A tool result could not be matched to a prior tool call.')
                );
            }

            return [
                'role' => 'user',
                'parts' => [
                    ['functionResponse' => ['name' => $name, 'response' => $this->decodeToolResult($message->content)]],
                ],
            ];
        }

        $role = $message->role === 'assistant' ? 'model' : 'user';
        $parts = [];

        if ($message->content !== '') {
            $parts[] = ['text' => $message->content];
        }

        foreach ($message->toolCalls as $toolCall) {
            $parts[] = ['functionCall' => ['name' => $toolCall->name, 'args' => $toolCall->arguments]];
        }

        return ['role' => $role, 'parts' => $parts];
    }

    /**
     * Gemini requires `functionResponse.response` to be an object. This
     * module's own tool results are always a JSON-encoded string (the
     * same shape every other provider's `tool`-role message content
     * already carries) — decoded here when it is genuinely a JSON object,
     * or wrapped under a `result` key when it isn't (a bare JSON scalar
     * or non-JSON text), so a real object always reaches Gemini either
     * way.
     *
     * @return array<string, mixed>
     */
    private function decodeToolResult(string $content): array
    {
        $decoded = json_decode($content, true);

        if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return ['result' => $content];
    }

    /**
     * @param array<string, mixed> $tool
     *
     * @return array<string, mixed>
     */
    private function buildFunctionDeclaration(array $tool): array
    {
        $name = $tool['name'] ?? null;

        if (!is_string($name) || $name === '') {
            throw new ProviderConfigurationException(
                new Phrase('A chat tool definition is missing a valid name.')
            );
        }

        return [
            'name' => $name,
            'description' => is_string($tool['description'] ?? null) ? $tool['description'] : '',
            'parameters' => is_array($tool['parameters'] ?? null)
                ? $tool['parameters']
                : ['type' => 'object', 'properties' => new \stdClass()],
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

        $candidates = $payload['candidates'] ?? null;

        if (!is_array($candidates) || !isset($candidates[0]) || !is_array($candidates[0])) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider response is missing a completion.')
            );
        }

        $candidate = $candidates[0];

        // The one well-documented content-refusal signal Gemini exposes —
        // other finish reasons (MAX_TOKENS, RECITATION, ...) are left to
        // fall through to the normal empty-content rejection below rather
        // than guessing at a mapping this module can't confirm is real.
        if (($candidate['finishReason'] ?? null) === 'SAFETY') {
            throw new ProviderRefusalException(
                new Phrase('The chat provider refused to generate a response.')
            );
        }

        $parts = $candidate['content']['parts'] ?? null;

        if (!is_array($parts)) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider response is missing content.')
            );
        }

        [$text, $toolCalls] = $this->parseParts($parts);

        try {
            return new ChatResponse(
                $text,
                $toolCalls,
                $this->parseUsage($payload['usageMetadata'] ?? []),
                $this->identifier(),
                $requestedModel,
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
     * @param list<mixed> $parts
     *
     * @return array{0: string, 1: list<ToolCall>}
     */
    private function parseParts(array $parts): array
    {
        $text = '';
        $toolCalls = [];
        $callIndex = 0;

        foreach ($parts as $part) {
            if (!is_array($part)) {
                throw new ProviderInvalidResponseException(
                    new Phrase('The chat provider response contains an invalid content part.')
                );
            }

            if (isset($part['text'])) {
                $partText = $part['text'];
                $text .= is_string($partText) ? $partText : '';

                continue;
            }

            if (isset($part['functionCall'])) {
                $toolCalls[] = $this->parseFunctionCall($part['functionCall'], $callIndex);
                $callIndex++;
            }
        }

        return [$text, $toolCalls];
    }

    /**
     * @param mixed $functionCall
     */
    private function parseFunctionCall(mixed $functionCall, int $callIndex): ToolCall
    {
        if (!is_array($functionCall)) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider response contains an invalid tool call.')
            );
        }

        $name = $functionCall['name'] ?? null;
        $args = $functionCall['args'] ?? [];

        if (!is_string($name) || $name === '' || !is_array($args)) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider response contains an invalid tool call.')
            );
        }

        try {
            // Gemini gives function calls no id at all — synthesized
            // purely for this module's own internal round-tripping, never
            // sent back to Gemini (see this class's own docblock).
            return new ToolCall('gemini-call-' . $callIndex, $name, $args);
        } catch (InvalidArgumentException $cause) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider response contains an invalid tool call.'),
                $cause
            );
        }
    }

    /**
     * @param mixed $rawUsage
     */
    private function parseUsage(mixed $rawUsage): TokenUsage
    {
        if (!is_array($rawUsage)) {
            return new TokenUsage(0, 0);
        }

        $inputTokens = $this->usageTokenCount($rawUsage['promptTokenCount'] ?? null) ?? 0;
        $outputTokens = $this->usageTokenCount($rawUsage['candidatesTokenCount'] ?? null) ?? 0;
        $cachedTokens = $this->usageTokenCount($rawUsage['cachedContentTokenCount'] ?? null) ?? 0;
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
