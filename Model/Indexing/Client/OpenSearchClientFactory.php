<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Client;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchConfigurationInvalidException;
use OpenSearch\Client;

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
    public function __construct(
        private readonly OpenSearchClientBuilderInterface $clientBuilder
    ) {
    }

    public function create(array $options): Client
    {
        return $this->clientBuilder->fromConfig($this->buildConfig($options));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function buildConfig(array $options): array
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

        if (str_contains($hostname, '#') || str_contains($hostname, '/') || str_contains($hostname, '?')) {
            throw new OpenSearchConfigurationInvalidException();
        }

        $host = $hostname;
        $normalizedHost = $host;
        if (str_starts_with($host, '[')) {
            if (preg_match('/^\[[0-9a-fA-F:.]+\]$/', $host) !== 1) {
                throw new OpenSearchConfigurationInvalidException();
            }
            $normalizedHost = $host;
        } elseif (str_contains($host, ':')) {
            // An unbracketed colon is an embedded port or malformed host; both
            // are ambiguous next to the dedicated port option, so fail closed.
            throw new OpenSearchConfigurationInvalidException();
        } else {
            $normalizedHost = $host;
        }

        $port = isset($options['port']) ? (int)$options['port'] : 9200;
        if ($port < 1 || $port > 65535) {
            throw new OpenSearchConfigurationInvalidException();
        }

        $config = [
            'hosts' => [sprintf('%s://%s:%d', $scheme, $normalizedHost, $port)],
            'retries' => 0,
        ];

        if ($this->authEnabled($options)) {
            $username = is_string($options['username'] ?? null) ? trim($options['username']) : '';
            $password = is_string($options['password'] ?? null) ? (string)$options['password'] : '';
            if ($username === '' || trim($password) === '') {
                throw new OpenSearchConfigurationInvalidException();
            }
            $config['basicAuthentication'] = [$username, $password];
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

        return true;
    }
}
