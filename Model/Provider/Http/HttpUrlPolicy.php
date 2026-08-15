<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Http;

/**
 * Strict policy for provider HTTP URLs.
 *
 * Rejects malformed URLs, embedded credentials, fragments, and unsupported
 * schemes. Cloud providers must use HTTPS; local providers may use HTTP or
 * HTTPS. The policy never emits the inspected URL, so it is safe to call with
 * potentially hostile input.
 */
final class HttpUrlPolicy
{
    public function isAllowed(string $url, bool $httpsOnly = false): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return false;
        }

        $scheme = $parts['scheme'] ?? null;

        if (!is_string($scheme)) {
            return false;
        }

        $scheme = strtolower($scheme);

        if ($scheme !== 'https' && $scheme !== 'http') {
            return false;
        }

        if ($httpsOnly && $scheme !== 'https') {
            return false;
        }

        if (!isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === '') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['fragment'])) {
            return false;
        }

        return true;
    }
}
