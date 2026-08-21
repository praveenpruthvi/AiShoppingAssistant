<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ToolCall;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRefusalException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTransportException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\ChatHttpTransport;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\GeminiProvider;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\HttpStatusMapper;
use Laminas\Http\Response;
use Magento\Framework\HTTP\LaminasClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Built to spec against Google's published Generative Language API
 * reference — no live API key was available to exercise a real call.
 * These tests verify the request/response mapping against realistic
 * example payloads shaped exactly like Google's own documented examples,
 * not a real live call — see the module's status report for this task
 * for the explicit, disclosed scope of what is and isn't live-verified.
 */
#[CoversClass(GeminiProvider::class)]
final class GeminiProviderTest extends TestCase
{
    private const MODEL = 'gemini-2.5-test';

    private ?LaminasClient $client = null;

    public function testIdentifierAndCapabilities(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('ok'));

        self::assertSame('google', $provider->identifier());
        self::assertTrue($provider->capabilities()->supportsChatGeneration());
        self::assertTrue($provider->capabilities()->supportsToolCalling());
        self::assertTrue($provider->capabilities()->supportsStructuredOutput());
        self::assertFalse($provider->capabilities()->isApiKeyOptional());
    }

    public function testSendsTheModelInTheUrlPathAndTheApiKeyAsAHeaderNeverAQueryParam(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('ok'));

        $provider->chat($this->request());

        $request = $this->client->getRequest();

        self::assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/' . self::MODEL . ':generateContent',
            $request->getUriString()
        );
        self::assertSame('gemini-key', $request->getHeaders()->get('x-goog-api-key')->getFieldValue());
        self::assertStringNotContainsString('gemini-key', $request->getUriString());
    }

    public function testMaxOutputTokensLivesInsideGenerationConfigNotTopLevel(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('ok'));

        $provider->chat($this->request(maxOutputTokens: 321));

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertArrayNotHasKey('max_tokens', $body);
        self::assertArrayNotHasKey('maxOutputTokens', $body);
        self::assertSame(321, $body['generationConfig']['maxOutputTokens']);
    }

    public function testSystemRoleMessagesBecomeTheTopLevelSystemInstructionFieldNeverAContentsRole(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('ok'));

        $request = new ChatRequest(
            storeId: 1,
            messages: [
                new ChatMessage('system', 'You are a shopping assistant.'),
                new ChatMessage('user', 'Show me tents.'),
            ],
            model: self::MODEL,
            baseUrl: '',
            apiKey: new SecretValue('gemini-key'),
            timeoutSeconds: 20
        );

        $provider->chat($request);

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame('You are a shopping assistant.', $body['systemInstruction']['parts'][0]['text']);
        self::assertCount(1, $body['contents']);
        self::assertSame('user', $body['contents'][0]['role']);
    }

    public function testAssistantRoleBecomesModelNotAssistant(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('ok'));

        $request = new ChatRequest(
            storeId: 1,
            messages: [
                new ChatMessage('user', 'Hi.'),
                new ChatMessage('assistant', 'Hello, how can I help?'),
                new ChatMessage('user', 'Show me tents.'),
            ],
            model: self::MODEL,
            baseUrl: '',
            apiKey: new SecretValue('gemini-key'),
            timeoutSeconds: 20
        );

        $provider->chat($request);

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame('user', $body['contents'][0]['role']);
        self::assertSame('model', $body['contents'][1]['role']);
        self::assertSame('Hello, how can I help?', $body['contents'][1]['parts'][0]['text']);
        self::assertSame('user', $body['contents'][2]['role']);
    }

    public function testAssistantToolCallsBecomeFunctionCallPartsAndToolResultsAreMatchedBackToTheRealFunctionNameById(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('ok'));

        $request = new ChatRequest(
            storeId: 1,
            messages: [
                new ChatMessage('user', 'What is the price of SKU-1?'),
                new ChatMessage(
                    'assistant',
                    '',
                    null,
                    [new ToolCall('gemini-call-0', 'check_price', ['skus' => ['SKU-1']])]
                ),
                new ChatMessage('tool', '{"prices":[{"sku":"SKU-1","price":9.99}]}', 'gemini-call-0'),
            ],
            model: self::MODEL,
            baseUrl: '',
            apiKey: new SecretValue('gemini-key'),
            timeoutSeconds: 20
        );

        $provider->chat($request);

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame('model', $body['contents'][1]['role']);
        self::assertSame('check_price', $body['contents'][1]['parts'][0]['functionCall']['name']);
        self::assertSame(['skus' => ['SKU-1']], $body['contents'][1]['parts'][0]['functionCall']['args']);

        // The tool result is addressed by the real function NAME (Gemini
        // has no call-id concept at all), resolved from the id-to-name
        // pairing already present on the preceding assistant turn — not
        // by parsing/guessing anything from the id string itself.
        self::assertSame('user', $body['contents'][2]['role']);
        self::assertSame('check_price', $body['contents'][2]['parts'][0]['functionResponse']['name']);
        self::assertSame(
            ['prices' => [['sku' => 'SKU-1', 'price' => 9.99]]],
            $body['contents'][2]['parts'][0]['functionResponse']['response']
        );
    }

    public function testANonJsonToolResultIsWrappedUnderAResultKeySinceGeminiRequiresAnObject(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('ok'));

        $request = new ChatRequest(
            storeId: 1,
            messages: [
                new ChatMessage('user', 'Check stock.'),
                new ChatMessage('assistant', '', null, [new ToolCall('gemini-call-0', 'check_inventory', [])]),
                new ChatMessage('tool', 'in stock', 'gemini-call-0'),
            ],
            model: self::MODEL,
            baseUrl: '',
            apiKey: new SecretValue('gemini-key'),
            timeoutSeconds: 20
        );

        $provider->chat($request);

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame(['result' => 'in stock'], $body['contents'][2]['parts'][0]['functionResponse']['response']);
    }

    public function testAToolResultWithNoMatchingPriorToolCallIsRejectedBeforeSending(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('ok'));
        $this->client->expects(self::never())->method('send');

        $request = new ChatRequest(
            storeId: 1,
            messages: [
                new ChatMessage('tool', '{}', 'nonexistent-call-id'),
            ],
            model: self::MODEL,
            baseUrl: '',
            apiKey: new SecretValue('gemini-key'),
            timeoutSeconds: 20
        );

        $this->expectException(ProviderConfigurationException::class);
        $provider->chat($request);
    }

    public function testToolsAreTranslatedToFunctionDeclarationsShape(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('ok'));

        $provider->chat($this->request(tools: [
            ['name' => 'search_products', 'description' => 'Search the catalog', 'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]],
        ]));

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertCount(1, $body['tools']);
        self::assertSame('search_products', $body['tools'][0]['functionDeclarations'][0]['name']);
        self::assertSame('Search the catalog', $body['tools'][0]['functionDeclarations'][0]['description']);
    }

    public function testResponseSchemaIsForwardedAsRealGenerationConfigFields(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('{}'));

        $provider->chat($this->request(responseSchema: ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']]]));

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame('application/json', $body['generationConfig']['responseMimeType']);
        self::assertSame(['type' => 'object', 'properties' => ['sku' => ['type' => 'string']]], $body['generationConfig']['responseSchema']);
    }

    public function testReturnsTextAndSynthesizedToolCallIdsSinceGeminiGivesNone(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"candidates":[{"content":{"role":"model","parts":[' .
            '{"text":"Here you go."},' .
            '{"functionCall":{"name":"search_products","args":{"q":"phone"}}}' .
            ']},"finishReason":"STOP"}],' .
            '"usageMetadata":{"promptTokenCount":42,"candidatesTokenCount":8,"cachedContentTokenCount":10}}'
        );

        $response = $provider->chat($this->request());

        self::assertSame('Here you go.', $response->text);
        self::assertCount(1, $response->toolCalls);
        self::assertSame('search_products', $response->toolCalls[0]->name);
        self::assertNotSame('', $response->toolCalls[0]->id);
        self::assertSame(['q' => 'phone'], $response->toolCalls[0]->arguments);
        self::assertSame('google', $response->provider);
        self::assertSame(42, $response->usage->inputTokens);
        self::assertSame(8, $response->usage->outputTokens);
        self::assertSame(10, $response->usage->cachedInputTokens);
    }

    public function testMultipleFunctionCallsGetDistinctSynthesizedIds(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"candidates":[{"content":{"role":"model","parts":[' .
            '{"functionCall":{"name":"search_products","args":{}}},' .
            '{"functionCall":{"name":"check_price","args":{}}}' .
            ']},"finishReason":"STOP"}]}'
        );

        $response = $provider->chat($this->request());

        self::assertCount(2, $response->toolCalls);
        self::assertNotSame($response->toolCalls[0]->id, $response->toolCalls[1]->id);
    }

    /**
     * Live-confirmed against a real gemini-3.6-flash response: Gemini's
     * "thinking" model family DOES include a real `id` on `functionCall`,
     * correcting this class's own original built-to-spec assumption that
     * it never does. The real id must be used, not overwritten by a
     * synthesized one — a synthesized id is only the fallback for a
     * response shape that genuinely omits it (still covered by
     * testMultipleFunctionCallsGetDistinctSynthesizedIds above).
     */
    public function testUsesTheRealFunctionCallIdWhenGeminiProvidesOneInsteadOfSynthesizing(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"candidates":[{"content":{"role":"model","parts":[' .
            '{"functionCall":{"name":"search_products","args":{},"id":"call_real123"}}' .
            ']},"finishReason":"STOP"}]}'
        );

        $response = $provider->chat($this->request());

        self::assertSame('call_real123', $response->toolCalls[0]->id);
    }

    /**
     * Live-confirmed against a real gemini-3.6-flash response: a
     * `thoughtSignature` sibling key alongside `functionCall` in the same
     * response part must be captured, since Gemini's real API rejects a
     * later multi-round request that replays this same function call
     * without echoing it back (a real 400 "missing thought_signature"
     * error) — see the matching round-trip test below.
     */
    public function testCapturesTheThoughtSignatureSiblingKeyForLaterRoundTripping(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"candidates":[{"content":{"role":"model","parts":[' .
            '{"functionCall":{"name":"search_products","args":{}},"thoughtSignature":"sig-abc123"}' .
            ']},"finishReason":"STOP"}]}'
        );

        $response = $provider->chat($this->request());

        self::assertSame('sig-abc123', $response->toolCalls[0]->providerMetadata);
    }

    public function testAFunctionCallWithNoThoughtSignatureLeavesProviderMetadataNull(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"candidates":[{"content":{"role":"model","parts":[' .
            '{"functionCall":{"name":"search_products","args":{}}}' .
            ']},"finishReason":"STOP"}]}'
        );

        $response = $provider->chat($this->request());

        self::assertNull($response->toolCalls[0]->providerMetadata);
    }

    /**
     * The write side of the thoughtSignature round trip: a ToolCall
     * carrying a captured providerMetadata must be echoed back as a
     * sibling `thoughtSignature` key on the SAME part when that turn is
     * replayed in a later request — real, live-confirmed requirement for
     * Gemini's "thinking" model family, not merely a nice-to-have.
     */
    public function testEchoesACapturedThoughtSignatureBackAsASiblingKeyWhenReplayingAPriorToolCall(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('ok'));

        $request = new ChatRequest(
            storeId: 1,
            messages: [
                new ChatMessage('user', 'What is the price of SKU-1?'),
                new ChatMessage(
                    'assistant',
                    '',
                    null,
                    [new ToolCall('call_real123', 'check_price', ['skus' => ['SKU-1']], 'sig-abc123')]
                ),
                new ChatMessage('tool', '{"prices":[]}', 'call_real123'),
            ],
            model: self::MODEL,
            baseUrl: '',
            apiKey: new SecretValue('gemini-key'),
            timeoutSeconds: 20
        );

        $provider->chat($request);

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame('sig-abc123', $body['contents'][1]['parts'][0]['thoughtSignature']);
    }

    public function testAToolCallWithNoCapturedProviderMetadataOmitsTheThoughtSignatureKeyEntirely(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('ok'));

        $request = new ChatRequest(
            storeId: 1,
            messages: [
                new ChatMessage('user', 'What is the price of SKU-1?'),
                new ChatMessage('assistant', '', null, [new ToolCall('gemini-call-0', 'check_price', ['skus' => ['SKU-1']])]),
                new ChatMessage('tool', '{"prices":[]}', 'gemini-call-0'),
            ],
            model: self::MODEL,
            baseUrl: '',
            apiKey: new SecretValue('gemini-key'),
            timeoutSeconds: 20
        );

        $provider->chat($request);

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertArrayNotHasKey('thoughtSignature', $body['contents'][1]['parts'][0]);
    }

    /**
     * Live-confirmed against a real 400 response ("Unknown name
     * additionalProperties ... Cannot find field"): Gemini's schema
     * dialect is a restricted subset that rejects `additionalProperties`
     * wherever it appears — every tool in this module sets it at every
     * object level as a deliberate strict-mode convention (see
     * LlmResponseSchema's own docblock) that must survive for every OTHER
     * provider; only the copy sent to Gemini strips it, recursively,
     * since it can appear at any nesting depth.
     */
    public function testStripsAdditionalPropertiesFromToolParameterSchemasRecursively(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('ok'));

        $provider->chat($this->request(tools: [
            [
                'name' => 'add_to_cart',
                'description' => 'Add an item',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => ['sku' => ['type' => 'string']],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ]));

        $body = json_decode($this->client->getRequest()->getContent(), true);
        $parameters = $body['tools'][0]['functionDeclarations'][0]['parameters'];

        self::assertArrayNotHasKey('additionalProperties', $parameters);
        self::assertArrayNotHasKey('additionalProperties', $parameters['properties']['items']['items']);
        // Every keyword Gemini DOES support must survive the strip untouched.
        self::assertSame('string', $parameters['properties']['items']['items']['properties']['sku']['type']);
    }

    public function testStripsAdditionalPropertiesFromTheResponseSchemaRecursively(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('{}'));

        $provider->chat($this->request(responseSchema: [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                ],
            ],
            'additionalProperties' => false,
        ]));

        $body = json_decode($this->client->getRequest()->getContent(), true);
        $schema = $body['generationConfig']['responseSchema'];

        self::assertArrayNotHasKey('additionalProperties', $schema);
        self::assertArrayNotHasKey('additionalProperties', $schema['properties']['items']['items']);
    }

    public function testSafetyFinishReasonMapsToRefusalException(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"candidates":[{"content":{"role":"model","parts":[]},"finishReason":"SAFETY"}]}'
        );

        $this->expectException(ProviderRefusalException::class);
        $provider->chat($this->request());
    }

    /**
     * `finishReason: "MAX_TOKENS"` is a normal, real Gemini outcome (the
     * response was truncated by generationConfig.maxOutputTokens, not an
     * error) — whatever text was generated before truncation must still
     * be returned. This class deliberately only special-cases SAFETY as
     * a real refusal signal; every other finish reason falls through to
     * whatever content actually exists, which this proves is correct
     * for this specific, real, named case.
     */
    public function testMaxTokensFinishReasonStillReturnsTheTruncatedText(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"candidates":[{"content":{"role":"model","parts":[{"text":"This got cut off becau"}]},"finishReason":"MAX_TOKENS"}]}'
        );

        $response = $provider->chat($this->request());

        self::assertSame('This got cut off becau', $response->text);
    }

    /**
     * RECITATION is a real, documented Gemini finish reason (the
     * response was withheld for reproducing training data too closely)
     * — distinct from SAFETY, and this class deliberately does not
     * treat it as a refusal (only the one well-documented SAFETY signal
     * is mapped, per this class's own docblock) since this module
     * cannot confirm RECITATION always means empty content the way
     * SAFETY does. With genuinely empty parts, it falls through to the
     * normal empty-response rejection instead of a fabricated refusal.
     */
    public function testRecitationFinishReasonIsNotTreatedAsARefusal(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"candidates":[{"content":{"role":"model","parts":[]},"finishReason":"RECITATION"}]}'
        );

        $this->expectException(ProviderInvalidResponseException::class);
        $provider->chat($this->request());
    }

    /**
     * Real, documented Gemini behavior: a candidate's parts array can
     * legitimately contain several text parts (e.g. interleaved with
     * functionCall parts) — every one must be concatenated, not just
     * the first.
     */
    public function testMultipleTextPartsAreConcatenated(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"candidates":[{"content":{"role":"model","parts":[{"text":"Part one. "},{"text":"Part two."}]},"finishReason":"STOP"}]}'
        );

        $response = $provider->chat($this->request());

        self::assertSame('Part one. Part two.', $response->text);
    }

    public function testMissingUsageMetadataDefaultsToZeroUsageRatherThanFailing(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"candidates":[{"content":{"role":"model","parts":[{"text":"ok"}]},"finishReason":"STOP"}]}'
        );

        $response = $provider->chat($this->request());

        self::assertSame(0, $response->usage->inputTokens);
        self::assertSame(0, $response->usage->outputTokens);
    }

    /**
     * Gemini's real API can return multiple candidates if
     * generationConfig.candidateCount is explicitly raised above its
     * default of 1 — this module never requests more than one (no
     * candidateCount is ever sent), and this class deliberately only
     * ever reads candidates[0], the one it actually asked for.
     */
    public function testOnlyTheFirstCandidateIsUsedWhenMultipleAreReturned(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"candidates":[' .
            '{"content":{"role":"model","parts":[{"text":"First candidate."}]},"finishReason":"STOP"},' .
            '{"content":{"role":"model","parts":[{"text":"Second candidate."}]},"finishReason":"STOP"}' .
            ']}'
        );

        $response = $provider->chat($this->request());

        self::assertSame('First candidate.', $response->text);
    }

    public function testAFunctionCallMissingANameIsRejected(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"candidates":[{"content":{"role":"model","parts":[{"functionCall":{"args":{}}}]},"finishReason":"STOP"}]}'
        );

        $this->expectException(ProviderInvalidResponseException::class);
        $provider->chat($this->request());
    }

    public function testMissingApiKeyFailsClosedBeforeRequest(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');
        $this->client->expects(self::never())->method('send');

        $this->expectException(ProviderConfigurationException::class);
        $provider->chat($this->request(withApiKey: false));
    }

    public function testCustomBaseUrlIsRejectedFailClosed(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');
        $this->client->expects(self::never())->method('send');

        $this->expectException(ProviderConfigurationException::class);
        $provider->chat($this->request(baseUrl: 'https://evil.example.test'));
    }

    public function testAuthenticationStatusMapsToAuthenticationException(): void
    {
        $provider = $this->provider('HTTP/1.1 401 Unauthorized' . "\r\n\r\n" . '{}');

        $this->expectException(ProviderAuthenticationException::class);
        $provider->chat($this->request());
    }

    /**
     * Task 45: Gemini's real API returns a genuine HTTP 400
     * ("INVALID_ARGUMENT") for an invalid/revoked key, not 401/403 — live-
     * confirmed via a direct curl against the real generateContent
     * endpoint with a deliberately bad key, which returned exactly this
     * body shape. Without assertNotApiKeyFailure(), this 400 would have
     * fallen through to HttpStatusMapper's generic mapping and become a
     * ProviderInvalidResponseException instead — silently defeating the
     * "an invalid key stops the chat" hard-failure safeguard for this
     * provider specifically, since HardFailureClassifier would never see
     * the real cause.
     */
    public function testRealGeminiApiKeyInvalidBodyOnA400MapsToAuthenticationException(): void
    {
        $body = json_encode([
            'error' => [
                'code' => 400,
                'message' => 'API key not valid. Please pass a valid API key.',
                'status' => 'INVALID_ARGUMENT',
                'details' => [
                    ['reason' => 'API_KEY_INVALID'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $provider = $this->provider('HTTP/1.1 400 Bad Request' . "\r\n\r\n" . $body);

        $this->expectException(ProviderAuthenticationException::class);
        $provider->chat($this->request());
    }

    /**
     * A 400 for a genuinely different reason (a malformed request/schema
     * issue, e.g. an unsupported field name) must still map to
     * ProviderInvalidResponseException as before — assertNotApiKeyFailure()
     * only reclassifies the one specific, documented API_KEY_INVALID case,
     * never every 400 wholesale.
     */
    public function testUnrelatedBadRequestStatusStillMapsToInvalidResponseException(): void
    {
        $body = json_encode([
            'error' => [
                'code' => 400,
                'message' => "Unknown name \"badField\": Cannot find field.",
                'status' => 'INVALID_ARGUMENT',
            ],
        ], JSON_THROW_ON_ERROR);

        $provider = $this->provider('HTTP/1.1 400 Bad Request' . "\r\n\r\n" . $body);

        $this->expectException(ProviderInvalidResponseException::class);
        $provider->chat($this->request());
    }

    public function testMissingCandidatesIsRejected(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{"candidates":[]}');

        $this->expectException(ProviderInvalidResponseException::class);
        $provider->chat($this->request());
    }

    public function testTransportFailureIsSanitized(): void
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willThrowException(new \RuntimeException('secret detail'));
        $provider = new GeminiProvider(
            new ChatHttpTransport($this->client, new HttpUrlPolicy()),
            new HttpUrlPolicy(),
            new HttpStatusMapper()
        );

        try {
            $provider->chat($this->request());
            self::fail('Expected ProviderTransportException.');
        } catch (ProviderTransportException $exception) {
            self::assertSame('PROVIDER_TRANSPORT_ERROR', $exception->errorCode());
            self::assertStringNotContainsString('secret detail', $exception->getMessage());
        }
    }

    public function testConnectionSucceedsOnValidResponse(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . $this->textResponse('OK'));

        $result = $provider->testConnection(1, self::MODEL, '', new SecretValue('gemini-key'), 20);

        self::assertTrue($result->successful);
    }

    public function testConnectionFailsClosedOnMissingApiKeyWithoutSending(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');
        $this->client->expects(self::never())->method('send');

        $result = $provider->testConnection(1, self::MODEL, '', SecretValue::empty(), 20);

        self::assertFalse($result->successful);
        self::assertSame('PROVIDER_CONFIGURATION_ERROR', $result->sanitizedErrorCode);
    }

    private function textResponse(string $text): string
    {
        return json_encode([
            'candidates' => [
                ['content' => ['role' => 'model', 'parts' => [['text' => $text]]], 'finishReason' => 'STOP'],
            ],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>|null $responseSchema
     */
    private function request(
        array $tools = [],
        ?array $responseSchema = null,
        int $maxOutputTokens = 1200,
        string $baseUrl = '',
        bool $withApiKey = true
    ): ChatRequest {
        return new ChatRequest(
            storeId: 1,
            messages: [new ChatMessage('user', 'Show waterproof tents.')],
            model: self::MODEL,
            baseUrl: $baseUrl,
            apiKey: $withApiKey ? new SecretValue('gemini-key') : SecretValue::empty(),
            timeoutSeconds: 20,
            tools: $tools,
            responseSchema: $responseSchema,
            maxOutputTokens: $maxOutputTokens
        );
    }

    private function provider(string $rawResponse): GeminiProvider
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willReturn(Response::fromString($rawResponse));

        return new GeminiProvider(
            new ChatHttpTransport($this->client, new HttpUrlPolicy()),
            new HttpUrlPolicy(),
            new HttpStatusMapper()
        );
    }

    private function makeClient(): LaminasClient
    {
        $client = $this->getMockBuilder(LaminasClient::class)
            ->onlyMethods(['send'])
            ->setConstructorArgs([])
            ->getMock();

        $this->client = $client;

        return $client;
    }
}
