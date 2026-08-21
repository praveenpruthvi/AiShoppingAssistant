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
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;
use InvalidArgumentException;
use Magento\Framework\Phrase;

/**
 * Anthropic Claude Messages API adapter (`POST /v1/messages`).
 *
 * Built to spec against Anthropic's published Messages API reference; no
 * live API key was available to this session to exercise a real call —
 * see the accompanying status report for exactly what is and isn't
 * verified. Implements LlmProviderInterface directly rather than
 * extending AbstractChatProvider: Anthropic's wire format differs from
 * OpenAI's chat/completions shape in several load-bearing ways that
 * AbstractChatProvider's shared request/response builders cannot express
 * without conditionals that would defeat the point of sharing them:
 *
 * - Auth is `x-api-key` + a required `anthropic-version` header, never
 *   `Authorization: Bearer`.
 * - The system prompt is a top-level `system` string field, never a
 *   `system`-role entry inside `messages` (Anthropic's `messages` array
 *   only accepts `user`/`assistant` roles) — every `system`-role
 *   ChatMessage this module passes in is extracted and joined here.
 * - `max_tokens` is REQUIRED on every request (OpenAI/xAI treat their
 *   equivalent field as optional-with-a-server-default).
 * - Assistant tool calls and tool results are both represented as
 *   *content blocks* inside a `user`/`assistant` message (`tool_use`/
 *   `tool_result`), not OpenAI's separate `tool_calls` array + dedicated
 *   `tool` role — a `ChatMessage(role: 'tool', ...)` becomes a `user`
 *   message with a `tool_result` block here, a real, load-bearing
 *   protocol difference, not a naming difference.
 * - Tool-call arguments arrive already decoded (`input`, a JSON object),
 *   never a JSON-encoded string the way OpenAI's `function.arguments` is.
 * - Usage field names differ (`input_tokens`/`output_tokens`, plus a real
 *   prompt-caching `cache_read_input_tokens` mapped onto this module's
 *   own `cachedInputTokens` concept).
 *
 * No native `response_format`/JSON-schema-constrained output field exists
 * in Anthropic's stable Messages API the way OpenAI's does — capabilities()
 * reports structuredOutput: false accordingly; this module's existing
 * prompt-based `ResponseContractFormatter` + malformed-response retry
 * (built originally for local-model compliance gaps) is what carries
 * structured-output compliance for this provider, unchanged, since that
 * mechanism already runs unconditionally for every provider.
 */
final class AnthropicProvider implements LlmProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const TEST_CONNECTION_MAX_OUTPUT_TOKENS = 16;

    public function __construct(
        private readonly ChatHttpTransport $transport,
        private readonly HttpUrlPolicy $urlPolicy,
        private readonly HttpStatusMapper $statusMapper
    ) {
    }

    public function identifier(): string
    {
        return ProviderIdentifiers::LLM_ANTHROPIC;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            chatGeneration: true,
            toolCalling: true,
            structuredOutput: false,
            apiKeyOptional: false,
            configurableBaseUrl: false
        );
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $this->assertValidRequest($request);

        $endpoint = $this->resolveEndpoint($request->baseUrl);

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

    private function resolveEndpoint(string $configuredBaseUrl): string
    {
        $defaultBaseUrl = rtrim(self::DEFAULT_BASE_URL, '/');

        if ($configuredBaseUrl === '') {
            return $defaultBaseUrl . '/messages';
        }

        if (strcasecmp(rtrim($configuredBaseUrl, '/'), $defaultBaseUrl) !== 0
            || !$this->urlPolicy->isAllowed($configuredBaseUrl, true)
        ) {
            throw new ProviderConfigurationException(
                new Phrase('A custom API endpoint is not allowed for this chat provider.')
            );
        }

        return $defaultBaseUrl . '/messages';
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(SecretValue $apiKey): array
    {
        return [
            'x-api-key' => $apiKey->reveal(),
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestBody(ChatRequest $request): array
    {
        $systemText = $this->extractSystemText($request->messages);

        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxOutputTokens,
            'messages' => array_values(array_map(
                fn (ChatMessage $message): array => $this->buildMessage($message),
                array_filter($request->messages, static fn (ChatMessage $message): bool => $message->role !== 'system')
            )),
        ];

        if ($systemText !== '') {
            $body['system'] = $systemText;
        }

        if ($request->tools !== []) {
            $body['tools'] = array_map(
                fn (array $tool): array => $this->buildTool($tool),
                $request->tools
            );
            $body['tool_choice'] = ['type' => 'auto'];
        }

        return $body;
    }

    /**
     * Anthropic's Messages API accepts one system STRING, not multiple
     * system-role turns the way OpenAI's messages array does — every
     * system-role ChatMessage this module passes in is joined here,
     * disclosed rather than silently dropping all but the first.
     *
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
     * @return array<string, mixed>
     */
    private function buildMessage(ChatMessage $message): array
    {
        if ($message->role === 'tool') {
            return [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => $message->toolCallId,
                        'content' => $message->content,
                    ],
                ],
            ];
        }

        if ($message->toolCalls === []) {
            return [
                'role' => $message->role,
                'content' => $message->content,
            ];
        }

        $blocks = [];

        if ($message->content !== '') {
            $blocks[] = ['type' => 'text', 'text' => $message->content];
        }

        foreach ($message->toolCalls as $toolCall) {
            $blocks[] = [
                'type' => 'tool_use',
                'id' => $toolCall->id,
                'name' => $toolCall->name,
                'input' => $toolCall->arguments,
            ];
        }

        return [
            'role' => $message->role,
            'content' => $blocks,
        ];
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
            'name' => $name,
            'description' => is_string($tool['description'] ?? null) ? $tool['description'] : '',
            'input_schema' => is_array($tool['parameters'] ?? null)
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

        $contentBlocks = $payload['content'] ?? null;

        if (!is_array($contentBlocks)) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider response is missing content.')
            );
        }

        [$text, $toolCalls] = $this->parseContentBlocks($contentBlocks);

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
     * @param list<mixed> $contentBlocks
     *
     * @return array{0: string, 1: list<ToolCall>}
     */
    private function parseContentBlocks(array $contentBlocks): array
    {
        $text = '';
        $toolCalls = [];

        foreach ($contentBlocks as $block) {
            if (!is_array($block)) {
                throw new ProviderInvalidResponseException(
                    new Phrase('The chat provider response contains an invalid content block.')
                );
            }

            $type = $block['type'] ?? null;

            if ($type === 'text') {
                $blockText = $block['text'] ?? '';
                $text .= is_string($blockText) ? $blockText : '';

                continue;
            }

            if ($type === 'tool_use') {
                $toolCalls[] = $this->parseToolUseBlock($block);
            }
        }

        return [$text, $toolCalls];
    }

    /**
     * @param array<string, mixed> $block
     */
    private function parseToolUseBlock(array $block): ToolCall
    {
        $id = $block['id'] ?? null;
        $name = $block['name'] ?? null;
        $input = $block['input'] ?? null;

        if (!is_string($id) || $id === '' || !is_string($name) || $name === '' || !is_array($input)) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider response contains an invalid tool call.')
            );
        }

        try {
            return new ToolCall($id, $name, $input);
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

        $inputTokens = $this->usageTokenCount($rawUsage['input_tokens'] ?? null) ?? 0;
        $outputTokens = $this->usageTokenCount($rawUsage['output_tokens'] ?? null) ?? 0;
        $cachedTokens = $this->usageTokenCount($rawUsage['cache_read_input_tokens'] ?? null) ?? 0;
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
