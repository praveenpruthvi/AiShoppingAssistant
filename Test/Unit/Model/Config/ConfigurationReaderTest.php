<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\ConfigurationReader;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Config\Path;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurationReader::class)]
final class ConfigurationReaderTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     */
    private function reader(array $values): ConfigurationReader
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(
                static fn (string $path): mixed => $values[$path] ?? null
            );

        return new ConfigurationReader($scopeConfig);
    }

    public function testReadsConfigurationForExplicitStoreScope(): void
    {
        $storeId = 7;
        $values = [
            Path::GENERAL_ENABLED => '1',
            Path::GENERAL_STRICT_STORE_ONLY => '1',
            Path::LLM_PROVIDER => 'openai',
            Path::LLM_MODEL => 'gpt-5.6-terra',
            Path::LLM_BASE_URL => 'https://example.test/v1',
            Path::LLM_TIMEOUT_SECONDS => '20',
            Path::LLM_MAX_OUTPUT_TOKENS => '1200',
            Path::FALLBACK_ENABLED => '1',
            Path::FALLBACK_PROVIDER => 'openai_compatible',
            Path::FALLBACK_MODEL => 'qwen3:14b',
            Path::FALLBACK_BASE_URL => 'http://127.0.0.1:11434/v1',
            Path::FALLBACK_TIMEOUT_SECONDS => '30',
            Path::FALLBACK_FAILURE_THRESHOLD => '3',
            Path::FALLBACK_COOLDOWN_SECONDS => '60',
            Path::EMBEDDING_PROVIDER => 'openai_compatible',
            Path::EMBEDDING_MODEL => 'bge-m3',
            Path::EMBEDDING_BASE_URL => 'http://127.0.0.1:11434/v1',
            Path::EMBEDDING_DIMENSIONS => '1024',
            Path::RETRIEVAL_KEYWORD_CANDIDATES => '50',
            Path::RETRIEVAL_VECTOR_CANDIDATES => '50',
            Path::RETRIEVAL_MERGED_CANDIDATES => '30',
            Path::RETRIEVAL_FINAL_PRODUCTS => '8',
            Path::RETRIEVAL_RERANKER_ENABLED => '0',
            Path::GUARDRAILS_MAX_INPUT_CHARACTERS => '1000',
            Path::GUARDRAILS_MAX_TOOL_CALLS => '4',
            Path::GUARDRAILS_CART_MUTATIONS_ENABLED => '0',
            Path::GUARDRAILS_BLOCK_EXTERNAL_URLS => '1',
            Path::GUARDRAILS_BLOCK_CODE_GENERATION => '1',
            Path::GUARDRAILS_OUT_OF_SCOPE_MESSAGE => 'Please stay on topic.',
        ];

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->any())
            ->method('getValue')
            ->with($this->anything(), ScopeInterface::SCOPE_STORE, (string) $storeId)
            ->willReturnCallback(
                static fn (string $path): mixed => $values[$path] ?? null
            );

        $reader = new ConfigurationReader($scopeConfig);

        $general = $reader->readGeneral($storeId);
        self::assertInstanceOf(GeneralConfigInterface::class, $general);
        self::assertTrue($general->isEnabled());
        self::assertTrue($general->isStrictStoreOnly());

        $llm = $reader->readLlm($storeId);
        self::assertSame('openai', $llm->provider());
        self::assertSame('gpt-5.6-terra', $llm->model());
        self::assertSame('https://example.test/v1', $llm->baseUrl());
        self::assertSame(20, $llm->timeoutSeconds());
        self::assertSame(1200, $llm->maxOutputTokens());

        $fallback = $reader->readFallback($storeId);
        self::assertTrue($fallback->isEnabled());
        self::assertSame('openai_compatible', $fallback->provider());
        self::assertSame('qwen3:14b', $fallback->model());
        self::assertSame(30, $fallback->timeoutSeconds());
        self::assertSame(3, $fallback->failureThreshold());
        self::assertSame(60, $fallback->cooldownSeconds());

        $embedding = $reader->readEmbedding($storeId);
        self::assertSame('openai_compatible', $embedding->provider());
        self::assertSame('bge-m3', $embedding->model());
        self::assertSame(1024, $embedding->dimensions());

        $retrieval = $reader->readRetrieval($storeId);
        self::assertSame(50, $retrieval->keywordCandidates());
        self::assertSame(50, $retrieval->vectorCandidates());
        self::assertSame(30, $retrieval->mergedCandidates());
        self::assertSame(8, $retrieval->finalProducts());
        self::assertFalse($retrieval->isRerankerEnabled());

        $guardrails = $reader->readGuardrails($storeId);
        self::assertSame(1000, $guardrails->maxInputCharacters());
        self::assertSame(4, $guardrails->maxToolCalls());
        self::assertFalse($guardrails->areCartMutationsEnabled());
        self::assertTrue($guardrails->blocksExternalUrls());
        self::assertTrue($guardrails->blocksCodeGeneration());
        self::assertSame('Please stay on topic.', $guardrails->outOfScopeMessage());
    }

    public function testConvertsBooleanStrings(): void
    {
        $reader = $this->reader([
            Path::GENERAL_ENABLED => '1',
            Path::GENERAL_STRICT_STORE_ONLY => '0',
            Path::GUARDRAILS_CART_MUTATIONS_ENABLED => '1',
            Path::GUARDRAILS_BLOCK_EXTERNAL_URLS => '0',
            Path::GUARDRAILS_BLOCK_CODE_GENERATION => '1',
        ]);

        $general = $reader->readGeneral(1);
        self::assertTrue($general->isEnabled());
        self::assertFalse($general->isStrictStoreOnly());

        $guardrails = $reader->readGuardrails(1);
        self::assertTrue($guardrails->areCartMutationsEnabled());
        self::assertFalse($guardrails->blocksExternalUrls());
        self::assertTrue($guardrails->blocksCodeGeneration());
    }

    public function testConvertsIntegerValues(): void
    {
        $reader = $this->reader([
            Path::LLM_PROVIDER => 'openai',
            Path::LLM_MODEL => 'test-model',
            Path::LLM_TIMEOUT_SECONDS => '25',
            Path::LLM_MAX_OUTPUT_TOKENS => '4096',
        ]);

        $llm = $reader->readLlm(1);
        self::assertSame(25, $llm->timeoutSeconds());
        self::assertSame(4096, $llm->maxOutputTokens());
    }

    public function testClampsNumbersAboveUpperBound(): void
    {
        $reader = $this->reader([
            Path::LLM_PROVIDER => 'openai',
            Path::LLM_MODEL => 'test-model',
            Path::LLM_TIMEOUT_SECONDS => '999999',
            Path::RETRIEVAL_FINAL_PRODUCTS => '500',
        ]);

        $llm = $reader->readLlm(1);
        self::assertSame(ConfigurationReader::MAX_TIMEOUT_SECONDS, $llm->timeoutSeconds());

        $retrieval = $reader->readRetrieval(1);
        self::assertSame(ConfigurationReader::MAX_FINAL_PRODUCTS, $retrieval->finalProducts());
    }

    public function testHardSafetyCeilingsAreEnforced(): void
    {
        self::assertSame(8192, ConfigurationReader::MAX_MAX_OUTPUT_TOKENS);
        self::assertSame(10000, ConfigurationReader::MAX_MAX_INPUT_CHARACTERS);
        self::assertSame(10, ConfigurationReader::MAX_MAX_TOOL_CALLS);
        self::assertSame(20, ConfigurationReader::MAX_FINAL_PRODUCTS);
    }

    public function testSafeDefaultsStayWithinHardCeilings(): void
    {
        self::assertLessThanOrEqual(
            ConfigurationReader::MAX_MAX_OUTPUT_TOKENS,
            ConfigurationReader::DEFAULT_MAX_OUTPUT_TOKENS
        );
        self::assertLessThanOrEqual(
            ConfigurationReader::MAX_MAX_INPUT_CHARACTERS,
            ConfigurationReader::DEFAULT_MAX_INPUT_CHARACTERS
        );
        self::assertLessThanOrEqual(
            ConfigurationReader::MAX_MAX_TOOL_CALLS,
            ConfigurationReader::DEFAULT_MAX_TOOL_CALLS
        );
        self::assertLessThanOrEqual(
            ConfigurationReader::MAX_FINAL_PRODUCTS,
            ConfigurationReader::DEFAULT_FINAL_PRODUCTS
        );
    }

    public function testValuesAboveHardCeilingsAreClampedToCeiling(): void
    {
        $reader = $this->reader([
            Path::LLM_PROVIDER => 'openai',
            Path::LLM_MODEL => 'test-model',
            Path::LLM_MAX_OUTPUT_TOKENS => '20000',
            Path::GUARDRAILS_MAX_INPUT_CHARACTERS => '50000',
            Path::GUARDRAILS_MAX_TOOL_CALLS => '500',
            Path::RETRIEVAL_FINAL_PRODUCTS => '100',
        ]);

        $llm = $reader->readLlm(1);
        self::assertSame(ConfigurationReader::MAX_MAX_OUTPUT_TOKENS, $llm->maxOutputTokens());

        $guardrails = $reader->readGuardrails(1);
        self::assertSame(ConfigurationReader::MAX_MAX_INPUT_CHARACTERS, $guardrails->maxInputCharacters());
        self::assertSame(ConfigurationReader::MAX_MAX_TOOL_CALLS, $guardrails->maxToolCalls());

        $retrieval = $reader->readRetrieval(1);
        self::assertSame(ConfigurationReader::MAX_FINAL_PRODUCTS, $retrieval->finalProducts());
    }

    public function testNumbersAtOrBelowLowerBoundAreHandledSafely(): void
    {
        $reader = $this->reader([
            Path::LLM_PROVIDER => 'openai',
            Path::LLM_MODEL => 'test-model',
            Path::LLM_TIMEOUT_SECONDS => '0',
            Path::FALLBACK_FAILURE_THRESHOLD => '-4',
        ]);

        $llm = $reader->readLlm(1);
        self::assertSame(ConfigurationReader::MIN_TIMEOUT_SECONDS, $llm->timeoutSeconds());

        $fallback = $reader->readFallback(1);
        self::assertSame(ConfigurationReader::DEFAULT_FAILURE_THRESHOLD, $fallback->failureThreshold());
    }

    public function testMalformedNumericValuesUseSafeDefault(): void
    {
        $reader = $this->reader([
            Path::LLM_PROVIDER => 'openai',
            Path::LLM_MODEL => 'test-model',
            Path::LLM_TIMEOUT_SECONDS => 'not-a-number',
            Path::GUARDRAILS_MAX_INPUT_CHARACTERS => '',
        ]);

        $llm = $reader->readLlm(1);
        self::assertSame(ConfigurationReader::DEFAULT_TIMEOUT_SECONDS, $llm->timeoutSeconds());

        $guardrails = $reader->readGuardrails(1);
        self::assertSame(ConfigurationReader::DEFAULT_MAX_INPUT_CHARACTERS, $guardrails->maxInputCharacters());
    }

    public function testGuardrailsFailClosedWhenConfigurationIsUnavailable(): void
    {
        $reader = $this->reader([]);

        $guardrails = $reader->readGuardrails(1);
        self::assertInstanceOf(GuardrailConfigInterface::class, $guardrails);
        self::assertSame(ConfigurationReader::DEFAULT_MAX_INPUT_CHARACTERS, $guardrails->maxInputCharacters());
        self::assertSame(ConfigurationReader::DEFAULT_MAX_TOOL_CALLS, $guardrails->maxToolCalls());
        self::assertFalse($guardrails->areCartMutationsEnabled());
        self::assertTrue($guardrails->blocksExternalUrls());
        self::assertTrue($guardrails->blocksCodeGeneration());
        self::assertSame(
            ConfigurationReader::DEFAULT_OUT_OF_SCOPE_MESSAGE,
            $guardrails->outOfScopeMessage()
        );
    }

    public function testModuleFailsClosedToDisabledStateWhenConfigurationIsUnavailable(): void
    {
        $reader = $this->reader([]);

        $general = $reader->readGeneral(1);
        self::assertFalse($general->isEnabled());
        self::assertTrue($general->isStrictStoreOnly());

        $fallback = $reader->readFallback(1);
        self::assertFalse($fallback->isEnabled());

        $retrieval = $reader->readRetrieval(1);
        self::assertFalse($retrieval->isRerankerEnabled());
    }

    public function testEmptyBaseUrlIsAllowed(): void
    {
        $reader = $this->reader([
            Path::LLM_PROVIDER => 'openai',
            Path::LLM_MODEL => 'test-model',
            Path::LLM_BASE_URL => null,
        ]);

        $llm = $reader->readLlm(1);
        self::assertSame('', $llm->baseUrl());
    }

    public function testMissingLlmProviderThrowsSanitizedConfigurationException(): void
    {
        $reader = $this->reader([
            Path::LLM_MODEL => 'test-model',
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('LLM provider is not configured');

        $reader->readLlm(1);
    }

    public function testMissingLlmModelThrowsSanitizedConfigurationException(): void
    {
        $reader = $this->reader([
            Path::LLM_PROVIDER => 'openai',
            Path::LLM_MODEL => '   ',
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('LLM model is not configured');

        $reader->readLlm(1);
    }

    public function testMissingEmbeddingProviderThrowsSanitizedConfigurationException(): void
    {
        $reader = $this->reader([
            Path::EMBEDDING_MODEL => 'bge-m3',
        ]);

        $this->expectException(ConfigurationException::class);

        $reader->readEmbedding(1);
    }

    public function testFallbackProviderIsOptionalWhenFallbackIsDisabled(): void
    {
        $reader = $this->reader([
            Path::FALLBACK_ENABLED => '0',
            Path::FALLBACK_PROVIDER => null,
            Path::FALLBACK_MODEL => null,
        ]);

        $fallback = $reader->readFallback(1);
        self::assertFalse($fallback->isEnabled());
        self::assertSame('', $fallback->provider());
        self::assertSame('', $fallback->model());
    }

    public function testFallbackProviderIsRequiredWhenFallbackIsEnabled(): void
    {
        $reader = $this->reader([
            Path::FALLBACK_ENABLED => '1',
            Path::FALLBACK_PROVIDER => null,
            Path::FALLBACK_MODEL => 'local-model',
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('fallback provider is not configured');

        $reader->readFallback(1);
    }
}