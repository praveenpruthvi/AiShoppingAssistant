<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider;

/**
 * Centralized, allowlisted provider identifiers.
 *
 * Services must never scatter raw provider strings, and identifiers must never
 * be derived from customer input or from arbitrary class names found in
 * Magento configuration.
 */
final class ProviderIdentifiers
{
    public const LLM_OPENAI = 'openai';
    public const LLM_ANTHROPIC = 'anthropic';
    public const LLM_XAI = 'xai';
    public const LLM_OPENAI_COMPATIBLE = 'openai_compatible';

    public const EMBEDDING_OPENAI = 'openai';
    public const EMBEDDING_VOYAGE = 'voyage';
    public const EMBEDDING_OPENAI_COMPATIBLE = 'openai_compatible';

    /**
     * @var list<string>
     */
    private const ALL_LLM = [
        self::LLM_OPENAI,
        self::LLM_ANTHROPIC,
        self::LLM_XAI,
        self::LLM_OPENAI_COMPATIBLE,
    ];

    /**
     * @var list<string>
     */
    private const ALL_EMBEDDING = [
        self::EMBEDDING_OPENAI,
        self::EMBEDDING_VOYAGE,
        self::EMBEDDING_OPENAI_COMPATIBLE,
    ];

    /**
     * @return list<string>
     */
    public static function llmProviderIds(): array
    {
        return self::ALL_LLM;
    }

    /**
     * @return list<string>
     */
    public static function embeddingProviderIds(): array
    {
        return self::ALL_EMBEDDING;
    }

    public static function isKnownLlm(string $identifier): bool
    {
        return in_array($identifier, self::ALL_LLM, true);
    }

    public static function isKnownEmbedding(string $identifier): bool
    {
        return in_array($identifier, self::ALL_EMBEDDING, true);
    }

    private function __construct()
    {
    }
}