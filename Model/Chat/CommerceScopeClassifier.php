<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\CommerceScopeClassifierInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;

/**
 * Deterministic, keyword/pattern-based commerce scope classifier.
 *
 * This is a cheap first pass, not a precise intent classifier: it is
 * default-allow (a message reaches the LLM unless it matches a known-bad
 * pattern) rather than default-deny, because a rule-based allowlist of
 * "commerce vocabulary" would either reject legitimate store-specific
 * queries that use words it doesn't know, or accept almost anything that
 * contains a generic word like "buy". Rejecting a real customer is worse
 * than letting a borderline query reach the LLM, which is itself
 * instructed to stay store-only, with the Output Validator (a later task)
 * as the final safety net.
 *
 * Prompt-injection and off-topic-request patterns are always enforced
 * (security/scope invariants, not merchant preferences). Code-generation
 * and external-URL blocking reuse the existing guardrails toggles
 * (block_code_generation, block_external_urls), which were already
 * intended to gate the assistant's behavior around these two request
 * shapes; a future Output Validator task will need the same enforcement
 * on the *response* side, which this classifier does not cover.
 */
final class CommerceScopeClassifier implements CommerceScopeClassifierInterface
{
    public const REASON_PROMPT_INJECTION = 'prompt_injection_attempt';
    public const REASON_CODE_GENERATION = 'code_generation_request';
    public const REASON_EXTERNAL_URL = 'external_url_request';
    public const REASON_OFF_TOPIC = 'off_topic_request';

    /**
     * @var list<string>
     */
    private const PROMPT_INJECTION_PATTERNS = [
        '/\bignore\s+(all\s+|any\s+|the\s+)?(previous|prior|above|earlier)\s+(instructions?|prompts?|rules?|guidelines?)\b/iu',
        '/\bdisregard\s+(your|the|all|any)\s+(instructions?|rules?|guidelines?|system\s+prompt)\b/iu',
        '/\b(reveal|show|print|repeat)\s+(me\s+)?your\s+(system\s+prompt|instructions?|rules?)\b/iu',
        '/\byou\s+are\s+no\s+longer\b/iu',
        '/\bnew\s+instructions?\s*:/iu',
    ];

    /**
     * @var list<string>
     */
    private const CODE_GENERATION_PATTERNS = [
        '/\bwrite\s+(me\s+)?(a\s+|some\s+)?(python|javascript|typescript|java|php|sql|html|css|bash|shell)\s+(code|script|function|program)\b/iu',
        '/\bwrite\s+(me\s+)?(a\s+|some\s+)?(code|script|function|program)\s+(that|to|for)\b/iu',
        '/\b(debug|fix)\s+(this|my)\s+(code|script|function|bug)\b/iu',
        '/```/u',
    ];

    /**
     * @var list<string>
     */
    private const EXTERNAL_URL_PATTERNS = [
        '/\bhttps?:\/\/\S+/iu',
        '/\bwww\.\S+\.\S+/iu',
    ];

    /**
     * @var list<string>
     */
    private const OFF_TOPIC_PATTERNS = [
        '/\bweather\s+(forecast|today|tomorrow|like)\b/iu',
        '/\bwhat.{0,15}\bweather\b/iu',
        '/\bwho\s+is\s+the\s+(president|prime\s+minister)\b/iu',
        '/\bcapital\s+of\s+\w+/iu',
        '/\bwho\s+(invented|discovered)\b/iu',
        '/\b(medical|legal|financial)\s+advice\b/iu',
        '/\btell\s+me\s+a\s+joke\b/iu',
        '/\bwrite\s+(me\s+)?a\s+(poem|story|song|essay)\b/iu',
        '/\bmy\s+homework\b/iu',
        '/\bsolve\s+(this\s+)?(equation|for\s+x)\b/iu',
    ];

    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader
    ) {
    }

    public function classify(int $storeId, string $message): ScopeClassification
    {
        if ($this->matchesAny(self::PROMPT_INJECTION_PATTERNS, $message)) {
            return ScopeClassification::outOfScope(self::REASON_PROMPT_INJECTION);
        }

        $guardrails = $this->configurationReader->readGuardrails($storeId);

        if ($guardrails->blocksCodeGeneration() && $this->matchesAny(self::CODE_GENERATION_PATTERNS, $message)) {
            return ScopeClassification::outOfScope(self::REASON_CODE_GENERATION);
        }

        if ($guardrails->blocksExternalUrls() && $this->matchesAny(self::EXTERNAL_URL_PATTERNS, $message)) {
            return ScopeClassification::outOfScope(self::REASON_EXTERNAL_URL);
        }

        if ($this->matchesAny(self::OFF_TOPIC_PATTERNS, $message)) {
            return ScopeClassification::outOfScope(self::REASON_OFF_TOPIC);
        }

        return ScopeClassification::inScope();
    }

    /**
     * @param list<string> $patterns
     */
    private function matchesAny(array $patterns, string $message): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }
}
