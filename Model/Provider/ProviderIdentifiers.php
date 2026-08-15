<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Magento\Framework\Phrase;

/**
 * Centralized identifier syntax and built-in provider identifiers.
 *
 * The runtime allowlist is the DI-backed provider registry, not this class.
 * Third-party identifiers such as `acme_local_llm` are permitted as long as
 * they are registered through Magento DI and are syntactically valid.
 *
 * Identifiers must never be derived from customer input or from arbitrary
 * class names found in Magento configuration, and configuration must never
 * contain a class name.
 */
final class ProviderIdentifiers
{
    public const LLM_OPENAI = 'openai';
    public const LLM_ANTHROPIC = 'anthropic';
    public const LLM_XAI = 'xai';
    public const LLM_OPENAI_COMPATIBLE = 'openai_compatible';

    public const EMBEDDING_OPENAI = 'openai';
    public const EMBEDDING_VOYAGE = 'voyage';
    public const EMBEDDING_LOCAL_OPENAI_COMPATIBLE = 'local_openai_compatible';

    public const IDENTIFIER_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public const MAX_IDENTIFIER_LENGTH = 64;

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
        self::EMBEDDING_LOCAL_OPENAI_COMPATIBLE,
    ];

    /**
     * Built-in LLM identifiers. Not an exhaustive allowlist: any syntactically
     * valid identifier is permitted once registered through DI.
     *
     * @return list<string>
     */
    public static function llmProviderIds(): array
    {
        return self::ALL_LLM;
    }

    /**
     * Built-in embedding identifiers. Not an exhaustive allowlist.
     *
     * @return list<string>
     */
    public static function embeddingProviderIds(): array
    {
        return self::ALL_EMBEDDING;
    }

    public static function isValid(string $identifier): bool
    {
        return preg_match(self::IDENTIFIER_PATTERN, $identifier) === 1;
    }

    /**
     * Throws a sanitized exception for invalid identifiers. The message never
     * echoes the invalid value.
     */
    public static function assertValid(string $identifier): void
    {
        if (!self::isValid($identifier)) {
            throw new ProviderConfigurationException(
                new Phrase('A provider identifier is not valid.')
            );
        }
    }

    private function __construct()
    {
    }
}
