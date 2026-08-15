<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Client;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchConfigurationInvalidException;
use OpenSearch\Client;
use OpenSearch\ClientBuilder;

/**
 * Validated OpenSearch\Client factory.
 *
 * Connection options come from Magento's catalogue-search configuration and are
 * reused so the assistant never stores duplicate credentials. Every value is
 * validated before a client is created:
 *
 *  - the scheme must be http or https (defaults to http);
 *  - credentials embedded in the hostname (user:pass@) are rejected;
 *  - fragments, paths, and embedded ports are rejected;
 *  - bracketed IPv6 literals are preserved;
 *  - automatic retries are disabled so the writer controls failure handling;
 *  - basic auth is passed through ClientBuilder::setBasicAuthentication and
 *    never appears in a host URI.
 *
 * A rejected option fails closed with a sanitized configuration exception; the
 * invalid value is never echoed.
 */
final class OpenSearchClientFactory implements OpenSearchClientFactoryInterface
{
    public function create(array $options): Client
    {
        return ClientBuilder::fromConfig($this->buildConfig($options), true);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildConfig(array $options): array
    {
        $hostname = is_string($options['hostname'] ?? '') ? trim($options['hostname']) : '';
        $hostname = rtrim($hostname, '/');

        if ($hostname === '') {
            throw new OpenSearchConfigurationInvalidException();
        }

        if (str_contains($hostname, '@')) {
            throw new OpenSearchConfigurationInvalidException();
        }

        $scheme = 'http';
        if (preg_match('#^([a-z][a-z0-9+.-]*)://#i', $hostname, $matches) === 1) {
            $scheme = strtolower($matches[1]);
            if ($scheme !== 'http' && $scheme !== 'https') {
                throw new OpenSearchConfigurationInvalidException();
            }
            $hostname = substr($hostname, strlen($matches[0]));
        }

        if (str_contains($hostname, '#') || str_contains($hostname, '/')) {
            throw new OpenSearchConfigurationInvalidException();
        }

        $host = $hostname;
        if (str_starts_with($host, '[')) {
            if (preg_match('/^\[[0-9a-fA-F:.]+\]$/', $host) !== 1) {
                throw new OpenSearchConfigurationInvalidException();
            }
            $host = substr($host, 1, -1);
        } elseif (str_contains($host, ':')) {
            // An unbracketed colon is an embedded port or malformed host; both
            // are ambiguous next to the dedicated port option, so fail closed.
            throw new OpenSearchConfigurationInvalidException();
        }

        $port = isset($options['port']) ? (int)$options['port'] : 9200;
        if ($port < 1 || $port > 65535) {
            throw new OpenSearchConfigurationInvalidException();
        }

        $config = [
            'hosts' => [sprintf('%s://%s:%d', $scheme, $host, $port)],
            'retries' => 0,
        ];

        if ($this->authEnabled($options)) {
            $config['basicAuthentication'] = [$options['username'], $options['password']];
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function authEnabled(array $options): bool
    {
        if (empty($options['enableAuth']) || (int)$options['enableAuth'] !== 1) {
            return false;
        }

        return isset($options['username'], $options['password'])
            && is_string($options['username'])
            && is_string($options['password']);
    }
}