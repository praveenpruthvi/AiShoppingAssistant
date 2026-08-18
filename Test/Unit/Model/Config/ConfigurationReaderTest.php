<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductAttributePolicyInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\ColorContrast;
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

        // ColorContrast is a pure, dependency-free computation — used
        // directly here rather than mocked, the same way this module's
        // other simple deterministic collaborators (e.g. LlmResponseParser
        // in OutputValidatorTest) are used directly rather than mocked.
        return new ConfigurationReader($scopeConfig, $this->policy(), new ColorContrast());
    }

    private function policy(): ProductAttributePolicyInterface
    {
        $policy = $this->createMock(ProductAttributePolicyInterface::class);
        $policy->method('isAllowed')->willReturn(true);

        return $policy;
    }

    public function testReadsConfigurationForExplicitStoreScope(): void
    {
        $storeId = 7;
        $values = [
            Path::GENERAL_ENABLED => '1',
            Path::GENERAL_STRICT_STORE_ONLY => '1',
            Path::GENERAL_MAX_CONVERSATION_MESSAGES => '60',
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
            Path::GUARDRAILS_REQUIRE_CART_CONFIRMATION => '0',
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

        $reader = new ConfigurationReader($scopeConfig, $this->policy(), new ColorContrast());

        $general = $reader->readGeneral($storeId);
        self::assertInstanceOf(GeneralConfigInterface::class, $general);
        self::assertTrue($general->isEnabled());
        self::assertTrue($general->isStrictStoreOnly());
        self::assertSame(60, $general->maxConversationMessages());

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
        self::assertFalse($guardrails->requiresCartConfirmation());
        self::assertTrue($guardrails->blocksExternalUrls());
        self::assertTrue($guardrails->blocksCodeGeneration());
        self::assertSame('Please stay on topic.', $guardrails->outOfScopeMessage());
    }

    public function testReadsAppearanceConfigurationWhenBothBubbleColorsAreExplicitlySet(): void
    {
        // Manual values always win, even though white-on-#eee is a
        // deliberately borderline pairing here — this reader never
        // second-guesses two explicitly configured colors.
        $reader = $this->reader([
            Path::APPEARANCE_PRIMARY_COLOR => '#112233',
            Path::APPEARANCE_MESSAGE_BUBBLE_COLOR => '#eee',
            Path::APPEARANCE_MESSAGE_TEXT_COLOR => '#222222',
        ]);

        $appearance = $reader->readAppearance(1);
        self::assertSame('#112233', $appearance->primaryColor());
        self::assertSame('#eee', $appearance->messageBubbleColor());
        self::assertSame('#222222', $appearance->messageTextColor());
    }

    public function testPrimaryTextColorIsAlwaysAutoComputedAgainstThePrimaryColor(): void
    {
        // #112233 is a very dark navy — readable text against it is white,
        // never a value any admin field can override (there is no such
        // field).
        $reader = $this->reader([Path::APPEARANCE_PRIMARY_COLOR => '#112233']);

        self::assertSame('#ffffff', $reader->readAppearance(1)->primaryTextColor());
    }

    public function testAppearanceColorsFallBackToThisModulesOriginalDefaultsWhenUnset(): void
    {
        $reader = $this->reader([]);

        $appearance = $reader->readAppearance(1);
        self::assertSame(ConfigurationReader::DEFAULT_PRIMARY_COLOR, $appearance->primaryColor());
        self::assertSame(ConfigurationReader::DEFAULT_MESSAGE_BUBBLE_COLOR, $appearance->messageBubbleColor());
        self::assertSame(ConfigurationReader::DEFAULT_MESSAGE_TEXT_COLOR, $appearance->messageTextColor());
    }

    public function testInvalidAppearanceColorsAreTreatedAsUnsetRatherThanEmittedVerbatim(): void
    {
        $reader = $this->reader([
            Path::APPEARANCE_PRIMARY_COLOR => 'red; } body { display: none',
            Path::APPEARANCE_MESSAGE_BUBBLE_COLOR => 'not-a-color',
            Path::APPEARANCE_MESSAGE_TEXT_COLOR => '#12345',
        ]);

        $appearance = $reader->readAppearance(1);
        self::assertSame(ConfigurationReader::DEFAULT_PRIMARY_COLOR, $appearance->primaryColor());
        self::assertSame(ConfigurationReader::DEFAULT_MESSAGE_BUBBLE_COLOR, $appearance->messageBubbleColor());
        self::assertSame(ConfigurationReader::DEFAULT_MESSAGE_TEXT_COLOR, $appearance->messageTextColor());
    }

    public function testMessageTextColorIsAutoComputedWhenOnlyTheBubbleColorIsSet(): void
    {
        // #1a1a2e is a dark navy — readable text against it is white, not
        // this module's default #222222 (which would be nearly invisible
        // against such a dark background).
        $reader = $this->reader([Path::APPEARANCE_MESSAGE_BUBBLE_COLOR => '#1a1a2e']);

        $appearance = $reader->readAppearance(1);
        self::assertSame('#1a1a2e', $appearance->messageBubbleColor());
        self::assertSame('#ffffff', $appearance->messageTextColor());
    }

    public function testMessageBubbleColorIsAutoComputedWhenOnlyTheTextColorIsSet(): void
    {
        // White text needs a dark background — not this module's default
        // #f2f2f2 (which would be nearly invisible against white text).
        $reader = $this->reader([Path::APPEARANCE_MESSAGE_TEXT_COLOR => '#ffffff']);

        $appearance = $reader->readAppearance(1);
        self::assertSame('#ffffff', $appearance->messageTextColor());
        self::assertSame('#2b2b2f', $appearance->messageBubbleColor());
    }

    public function testReadsCapabilitiesConfiguration(): void
    {
        $reader = $this->reader([
            Path::CAPABILITIES_PRODUCT_DISCOVERY_ENABLED => '1',
            Path::CAPABILITIES_PRODUCT_DETAILS_ENABLED => '0',
            Path::CAPABILITIES_COMPARISON_ENABLED => '1',
            Path::CAPABILITIES_PRICE_CHECKING_ENABLED => '0',
            Path::CAPABILITIES_STOCK_CHECKING_ENABLED => '1',
            Path::CAPABILITIES_POLICY_SEARCH_ENABLED => '0',
        ]);

        $capabilities = $reader->readCapabilities(1);
        self::assertTrue($capabilities->isProductDiscoveryEnabled());
        self::assertFalse($capabilities->isProductDetailsEnabled());
        self::assertTrue($capabilities->isComparisonEnabled());
        self::assertFalse($capabilities->isPriceCheckingEnabled());
        self::assertTrue($capabilities->isStockCheckingEnabled());
        self::assertFalse($capabilities->isPolicySearchEnabled());
    }

    public function testCapabilitiesDefaultToEnabledWhenConfigurationIsUnavailable(): void
    {
        $reader = $this->reader([]);

        $capabilities = $reader->readCapabilities(1);
        self::assertTrue($capabilities->isProductDiscoveryEnabled());
        self::assertTrue($capabilities->isProductDetailsEnabled());
        self::assertTrue($capabilities->isComparisonEnabled());
        self::assertTrue($capabilities->isPriceCheckingEnabled());
        self::assertTrue($capabilities->isStockCheckingEnabled());
        self::assertTrue($capabilities->isPolicySearchEnabled());
    }

    public function testConvertsBooleanStrings(): void
    {
        $reader = $this->reader([
            Path::GENERAL_ENABLED => '1',
            Path::GENERAL_STRICT_STORE_ONLY => '0',
            Path::GUARDRAILS_CART_MUTATIONS_ENABLED => '1',
            Path::GUARDRAILS_REQUIRE_CART_CONFIRMATION => '0',
            Path::GUARDRAILS_BLOCK_EXTERNAL_URLS => '0',
            Path::GUARDRAILS_BLOCK_CODE_GENERATION => '1',
        ]);

        $general = $reader->readGeneral(1);
        self::assertTrue($general->isEnabled());
        self::assertFalse($general->isStrictStoreOnly());

        $guardrails = $reader->readGuardrails(1);
        self::assertTrue($guardrails->areCartMutationsEnabled());
        self::assertFalse($guardrails->requiresCartConfirmation());
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

    public function testMaxConversationMessagesIsClampedToItsBounds(): void
    {
        $reader = $this->reader([Path::GENERAL_MAX_CONVERSATION_MESSAGES => '99999']);
        self::assertSame(
            ConfigurationReader::MAX_MAX_CONVERSATION_MESSAGES,
            $reader->readGeneral(1)->maxConversationMessages()
        );

        $reader = $this->reader([Path::GENERAL_MAX_CONVERSATION_MESSAGES => '0']);
        self::assertSame(
            ConfigurationReader::MIN_MAX_CONVERSATION_MESSAGES,
            $reader->readGeneral(1)->maxConversationMessages()
        );
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
        self::assertTrue($guardrails->requiresCartConfirmation());
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
        self::assertSame(ConfigurationReader::DEFAULT_MAX_CONVERSATION_MESSAGES, $general->maxConversationMessages());

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

    public function testReadsIndexingConfiguration(): void
    {
        $reader = $this->reader([
            Path::INDEXING_BATCH_SIZE => '25',
            Path::INDEXING_SEARCHABLE_ATTRIBUTE_CODES => 'Brand, COLOR , size',
            Path::INDEXING_INCLUDE_SHORT_DESCRIPTION => '1',
            Path::INDEXING_INCLUDE_LONG_DESCRIPTION => '0',
            Path::INDEXING_AGGREGATE_CONFIGURABLE_VARIANTS => '0',
            Path::INDEXING_MAX_ATTRIBUTE_VALUES_PER_PRODUCT => '50',
        ]);

        $indexing = $reader->readIndexing(1);
        self::assertSame(25, $indexing->batchSize());
        self::assertSame(['brand', 'color', 'size'], $indexing->searchableAttributeCodes());
        self::assertTrue($indexing->includeShortDescription());
        self::assertFalse($indexing->includeLongDescription());
        self::assertFalse($indexing->aggregateConfigurableVariants());
        self::assertSame(50, $indexing->maxAttributeValuesPerProduct());
    }

    public function testIndexingUsesSafeDefaultsWhenConfigurationIsUnavailable(): void
    {
        $reader = $this->reader([]);

        $indexing = $reader->readIndexing(1);
        self::assertSame(ConfigurationReader::DEFAULT_BATCH_SIZE, $indexing->batchSize());
        self::assertSame(
            ConfigurationReader::DEFAULT_SEARCHABLE_ATTRIBUTE_CODES,
            $indexing->searchableAttributeCodes()
        );
        self::assertTrue($indexing->includeShortDescription());
        self::assertTrue($indexing->includeLongDescription());
        self::assertFalse($indexing->aggregateConfigurableVariants());
        self::assertSame(ConfigurationReader::DEFAULT_MAX_ATTRIBUTE_VALUES, $indexing->maxAttributeValuesPerProduct());
    }

    public function testIndexingClampsBatchSizeAndAttributeValuesToBounds(): void
    {
        $reader = $this->reader([
            Path::INDEXING_BATCH_SIZE => '99999',
            Path::INDEXING_MAX_ATTRIBUTE_VALUES_PER_PRODUCT => '99999',
        ]);

        $indexing = $reader->readIndexing(1);
        self::assertSame(ConfigurationReader::MAX_BATCH_SIZE, $indexing->batchSize());
        self::assertSame(ConfigurationReader::MAX_MAX_ATTRIBUTE_VALUES, $indexing->maxAttributeValuesPerProduct());
    }

    public function testIndexingClampsValuesBelowLowerBound(): void
    {
        $reader = $this->reader([
            Path::INDEXING_BATCH_SIZE => '1',
            Path::INDEXING_MAX_ATTRIBUTE_VALUES_PER_PRODUCT => '0',
        ]);

        $indexing = $reader->readIndexing(1);
        self::assertSame(ConfigurationReader::MIN_BATCH_SIZE, $indexing->batchSize());
        self::assertSame(ConfigurationReader::MIN_MAX_ATTRIBUTE_VALUES, $indexing->maxAttributeValuesPerProduct());
    }

    public function testIndexingFailsClosedOnMalformedAttributeCode(): void
    {
        $reader = $this->reader([
            Path::INDEXING_SEARCHABLE_ATTRIBUTE_CODES => 'Good-Code, BAD CODE',
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('invalid attribute code');

        $reader->readIndexing(1);
    }

    public function testIndexingExplicitBlankListYieldsNoSearchableAttributes(): void
    {
        $reader = $this->reader([
            Path::INDEXING_SEARCHABLE_ATTRIBUTE_CODES => '',
        ]);

        $indexing = $reader->readIndexing(1);
        self::assertSame([], $indexing->searchableAttributeCodes());
    }

    public function testIndexingRemovesPolicyDeniedAttributeCodes(): void
    {
        $policy = $this->createMock(ProductAttributePolicyInterface::class);
        $policy->method('isAllowed')
            ->willReturnCallback(
                static fn (string $code): bool => $code !== 'cost'
            );

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(
                static fn (string $path): mixed => $path === Path::INDEXING_SEARCHABLE_ATTRIBUTE_CODES
                    ? 'cost,color'
                    : null
            );

        $indexing = (new ConfigurationReader($scopeConfig, $policy, new ColorContrast()))->readIndexing(1);
        self::assertSame(['color'], $indexing->searchableAttributeCodes());
    }

    public function testIndexingRejectsEnabledConfigurableVariantAggregation(): void
    {
        $reader = $this->reader([
            Path::INDEXING_AGGREGATE_CONFIGURABLE_VARIANTS => '1',
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Configurable variant aggregation is not available');

        $reader->readIndexing(1);
    }
}
