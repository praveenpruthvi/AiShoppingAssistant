<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductAttributePolicyInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\SearchableAttributeInterface;

/**
 * Attribute allowlisting for catalogue normalization.
 *
 * The policy fails closed: an attribute is excluded unless its code is a valid
 * lowercase attribute code AND is not on the internal/secret denylist. Suspicious
 * tokens are matched as substrings so obfuscations like "secret_key_2" or
 * "api.key" are still excluded.
 */
final class ProductAttributePolicy implements ProductAttributePolicyInterface
{
    /**
     * Explicitly excluded attribute codes.
     *
     * @var list<string>
     */
    private const DENIED_CODES = [
        'cost',
        'internal_note',
        'internal_notes',
        'admin_note',
        'admin_notes',
        'admin_instructions',
        'backend_note',
        'api_key',
        'password',
        'passwd',
        'secret_key',
        'client_secret',
        'auth_token',
        'access_token',
        'credential',
        'credentials',
        'private_key',
    ];

    /**
     * Substrings that mark a code as potentially sensitive.
     *
     * @var list<string>
     */
    private const SUSPICIOUS_TOKENS = [
        'secret',
        'apikey',
        'api_key',
        'api_token',
        'password',
        'passwd',
        'private_key',
        'privatekey',
        'auth_token',
        'access_token',
        'credential',
    ];

    private const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public function isAllowed(string $code): bool
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            return false;
        }

        if (in_array($code, self::DENIED_CODES, true)) {
            return false;
        }

        $lower = strtolower($code);
        foreach (self::SUSPICIOUS_TOKENS as $token) {
            if (str_contains($lower, $token)) {
                return false;
            }
        }

        return true;
    }

    public function filter(array $attributes): array
    {
        $allowed = [];

        foreach ($attributes as $code => $attribute) {
            if (!is_string($code) || !$attribute instanceof SearchableAttributeInterface) {
                continue;
            }

            if (!$this->isAllowed($code)) {
                continue;
            }

            if ($attribute->values() === []) {
                continue;
            }

            $allowed[$code] = $attribute;
        }

        ksort($allowed);

        return array_values($allowed);
    }
}
