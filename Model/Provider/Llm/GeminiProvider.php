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
 * - Gemini's `functionCall` DOES include a real `id` for the "thinking"
 *   model family (live-confirmed against gemini-3.6-flash, correcting
 *   this class's own original built-to-spec assumption) — used when
 *   present; a synthesized id (`gemini-call-<index>`) is only a fallback
 *   for a response shape that ever genuinely omits it.
 * - Gemini's "thinking" models require a `thoughtSignature` (a sibling
 *   key of `functionCall` in the same response part) to be echoed back
 *   verbatim, as a sibling key in the same request part, on any later
 *   turn that replays that same function call — live-confirmed: omitting
 *   it fails a multi-round tool call with a real 400 "missing
 *   thought_signature" error. Carried on `ToolCall::$providerMetadata`,
 *   a generic, provider-opaque field every other provider ignores.
 * - `max_tokens` lives inside a nested `generationConfig.maxOutputTokens`
 *   field, not a top-level field.
 * - Real, documented structured-output support exists
 *   (`generationConfig.responseMimeType`/`responseSchema`) — unlike
 *   Anthropic, capabilities() reports structuredOutput: true here, and a
 *   provided responseSchema is genuinely forwarded.
 * - Usage field names differ again (`promptTokenCount`/
 *   `candidatesTokenCount`, plus a real `cachedContentTokenCount` mapped
 *   onto this module's own `cachedInputTokens` concept).
 * - An invalid or revoked API key gets a genuine HTTP 400
 *   ("INVALID_ARGUMENT"), not 401/403 — live-confirmed (Task 45) via a
 *   direct `curl` against the real API. assertNotApiKeyFailure()
 *   reclassifies specifically this case (body contains the documented
 *   `API_KEY_INVALID` reason) to ProviderAuthenticationException before
 *   HttpStatusMapper's generic, status-code-only mapping would otherwise
 *   treat it as an ordinary malformed-request 400.
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

        $this->assertNotApiKeyFailure($response->statusCode(), $response->body());
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

    /**
     * Gemini's Generative Language API returns a genuine HTTP 400
     * ("INVALID_ARGUMENT") for an invalid or revoked API key — live-
     * confirmed against the real API (Task 45: `curl` directly against
     * generateContent with a bad key returns
     * `{"error":{"code":400,"status":"INVALID_ARGUMENT",
     * "details":[{"reason":"API_KEY_INVALID", ...}]}}`) — rather than the
     * 401/403 HttpStatusMapper otherwise expects for an authentication
     * failure. Left unhandled, this was silently misclassified as
     * ProviderInvalidResponseException (a retryable compliance problem)
     * instead of ProviderAuthenticationException (a hard,
     * non-retryable failure) — defeating HardFailureClassifier's
     * "an invalid key stops the chat" safeguard entirely for this
     * provider, since it never even saw the real cause. Only a 400 whose
     * body carries this specific, documented Gemini error reason is
     * reclassified; every other 400 (a genuine malformed request/schema
     * issue) is left for HttpStatusMapper's normal handling.
     */
    private function assertNotApiKeyFailure(int $statusCode, string $body): void
    {
        if ($statusCode !== 400 || !str_contains($body, 'API_KEY_INVALID')) {
            return;
        }

        throw new ProviderAuthenticationException(
            new Phrase('The chat provider rejected the request.')
        );
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
            $body['generationConfig']['responseSchema'] = $this->stripUnsupportedSchemaKeywords(
                $request->responseSchema
            );
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
            $part = ['functionCall' => ['name' => $toolCall->name, 'args' => $toolCall->arguments]];

            if ($toolCall->providerMetadata !== null) {
                $part['thoughtSignature'] = $toolCall->providerMetadata;
            }

            $parts[] = $part;
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
                ? $this->stripUnsupportedSchemaKeywords($tool['parameters'])
                : ['type' => 'object', 'properties' => new \stdClass()],
        ];
    }

    /**
     * Gemini's schema dialect is a restricted subset of OpenAPI 3.0/JSON
     * Schema — confirmed live against a real 400 response ("Unknown name
     * additionalProperties ... Cannot find field") for both tool parameter
     * schemas and the structured-output response schema. Every tool in
     * this module (and the shared LlmResponseSchema) sets
     * `additionalProperties: false` at every object level as a genuine,
     * deliberate strict-mode convention other providers (OpenAI, in
     * particular) rely on — that convention is correct and must not
     * change; this only strips the one keyword Gemini's own API rejects
     * from the COPY sent to Gemini, recursively, since it can appear at
     * any nesting depth this module's schemas use.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function stripUnsupportedSchemaKeywords(array $schema): array
    {
        unset($schema['additionalProperties']);

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->stripUnsupportedSchemaKeywords($value);
            }
        }

        return $schema;
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
                $thoughtSignature = $part['thoughtSignature'] ?? null;
                $toolCalls[] = $this->parseFunctionCall(
                    $part['functionCall'],
                    $callIndex,
                    is_string($thoughtSignature) ? $thoughtSignature : null
                );
                $callIndex++;
            }
        }

        return [$text, $toolCalls];
    }

    /**
     * @param mixed $functionCall
     */
    private function parseFunctionCall(mixed $functionCall, int $callIndex, ?string $thoughtSignature): ToolCall
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

        // Live-confirmed against a real response: Gemini's "thinking" model
        // family (gemini-3.6-flash) DOES include a real `id` on
        // `functionCall` — this module's earlier build-to-spec assumption
        // that Gemini never provides one was wrong (no live key existed to
        // check at the time). Prefer the real id when present; fall back to
        // a synthesized one only if it's ever genuinely absent, rather than
        // assuming every Gemini model/response shape always includes it.
        $rawId = $functionCall['id'] ?? null;
        $id = is_string($rawId) && $rawId !== '' ? $rawId : 'gemini-call-' . $callIndex;

        try {
            return new ToolCall($id, $name, $args, $thoughtSignature);
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
