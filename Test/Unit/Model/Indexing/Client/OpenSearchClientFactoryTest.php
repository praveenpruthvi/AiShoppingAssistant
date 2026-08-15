<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Client;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Client\OpenSearchClientFactory;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchConfigurationInvalidException;
use OpenSearch\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenSearchClientFactory::class)]
final class OpenSearchClientFactoryTest extends TestCase
{
    private OpenSearchClientFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new OpenSearchClientFactory();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function options(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'localhost',
            'port' => 9200,
            'index' => 'magento2',
            'enableAuth' => 0,
            'username' => 'user',
            'password' => 'pass',
            'timeout' => 15,
        ], $overrides);
    }

    public function testBuildsClientForPlainHost(): void
    {
        self::assertInstanceOf(Client::class, $this->factory->create($this->options()));
    }

    public function testBuildsClientForHttpAndHttpsSchemes(): void
    {
        self::assertInstanceOf(Client::class, $this->factory->create($this->options(['hostname' => 'http://search.internal'])));
        self::assertInstanceOf(Client::class, $this->factory->create($this->options(['hostname' => 'https://search.internal'])));
    }

    public function testBuildsClientForBracketedIpv6(): void
    {
        self::assertInstanceOf(Client::class, $this->factory->create($this->options(['hostname' => '[::1]', 'port' => 9200])));
    }

    public function testBuildsClientWithAuthEnabled(): void
    {
        self::assertInstanceOf(Client::class, $this->factory->create($this->options(['enableAuth' => 1])));
    }

    public function testRejectsEmptyHostname(): void
    {
        $this->expectException(OpenSearchConfigurationInvalidException::class);
        $this->factory->create($this->options(['hostname' => '']));
    }

    public function testRejectsEmbeddedCredentials(): void
    {
        $this->expectException(OpenSearchConfigurationInvalidException::class);
        $this->factory->create($this->options(['hostname' => 'https://user:pass@search.internal']));
    }

    public function testRejectsUnsupportedScheme(): void
    {
        $this->expectException(OpenSearchConfigurationInvalidException::class);
        $this->factory->create($this->options(['hostname' => 'ftp://search.internal']));
    }

    public function testRejectsPathInHostname(): void
    {
        $this->expectException(OpenSearchConfigurationInvalidException::class);
        $this->factory->create($this->options(['hostname' => 'https://search.internal/path']));
    }

    public function testRejectsFragmentInHostname(): void
    {
        $this->expectException(OpenSearchConfigurationInvalidException::class);
        $this->factory->create($this->options(['hostname' => 'https://search.internal#frag']));
    }

    public function testRejectsEmbeddedPort(): void
    {
        $this->expectException(OpenSearchConfigurationInvalidException::class);
        $this->factory->create($this->options(['hostname' => 'search.internal:9200']));
    }

    public function testRejectsInvalidPortRange(): void
    {
        $this->expectException(OpenSearchConfigurationInvalidException::class);
        $this->factory->create($this->options(['port' => 0]));
        $this->factory->create($this->options(['port' => 65536]));
    }

    public function testRejectsMalformedIpv6Literal(): void
    {
        $this->expectException(OpenSearchConfigurationInvalidException::class);
        $this->factory->create($this->options(['hostname' => '[::1', 'port' => 9200]));
    }
}