<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Client;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Client\OpenSearchClientFactory;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Client\OpenSearchClientBuilderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchConfigurationInvalidException;
use OpenSearch\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenSearchClientFactory::class)]
final class OpenSearchClientFactoryTest extends TestCase
{
    private OpenSearchClientFactory $factory;

    private Client $client;

    /**
     * @var list<array<string, mixed>>
     */
    private array $capturedConfigs = [];

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $builder = $this->createMock(OpenSearchClientBuilderInterface::class);
        $builder->method('fromConfig')->willReturnCallback(
            function (array $config): Client {
                $this->capturedConfigs[] = $config;
                return $this->client;
            }
        );
        $this->factory = new OpenSearchClientFactory($builder);
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
        self::assertSame($this->client, $this->factory->create($this->options()));
        self::assertSame(['http://localhost:9200'], $this->capturedConfigs[0]['hosts']);
        self::assertSame(0, $this->capturedConfigs[0]['retries']);
    }

    public function testBuildsClientForHttpAndHttpsSchemes(): void
    {
        self::assertSame($this->client, $this->factory->create($this->options(['hostname' => 'http://search.internal'])));
        self::assertSame($this->client, $this->factory->create($this->options(['hostname' => 'https://search.internal'])));
        self::assertSame(['http://search.internal:9200'], $this->capturedConfigs[0]['hosts']);
        self::assertSame(['https://search.internal:9200'], $this->capturedConfigs[1]['hosts']);
    }

    public function testBracketedIpv6IsNormalizedWithBracketsPreserved(): void
    {
        self::assertSame($this->client, $this->factory->create($this->options(['hostname' => '[::1]', 'port' => 9200])));
        self::assertSame(['http://[::1]:9200'], $this->capturedConfigs[0]['hosts']);
    }

    public function testBuildsClientWithAuthEnabled(): void
    {
        self::assertSame($this->client, $this->factory->create($this->options(['enableAuth' => 1])));
        self::assertSame(['user', 'pass'], $this->capturedConfigs[0]['basicAuthentication']);
        self::assertSame(['http://localhost:9200'], $this->capturedConfigs[0]['hosts']);
    }

    public function testCredentialsAreAbsentFromHostUrl(): void
    {
        $this->factory->create($this->options([
            'enableAuth' => 1,
            'username' => 'secret-user',
            'password' => 'secret-pass',
        ]));

        self::assertSame(['http://localhost:9200'], $this->capturedConfigs[0]['hosts']);
        self::assertStringNotContainsString('secret-user', $this->capturedConfigs[0]['hosts'][0]);
        self::assertStringNotContainsString('secret-pass', $this->capturedConfigs[0]['hosts'][0]);
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

    public function testRejectsQueryStringInHostname(): void
    {
        $this->expectException(OpenSearchConfigurationInvalidException::class);
        $this->factory->create($this->options(['hostname' => 'https://search.internal?debug=1']));
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

    public function testAuthEnabledRejectsMissingUsername(): void
    {
        $this->expectException(OpenSearchConfigurationInvalidException::class);
        $this->factory->create($this->options(['enableAuth' => 1, 'username' => '']));
    }

    public function testAuthEnabledRejectsMissingPassword(): void
    {
        $this->expectException(OpenSearchConfigurationInvalidException::class);
        $this->factory->create($this->options(['enableAuth' => 1, 'password' => '']));
    }

    public function testRejectsMalformedIpv6Literal(): void
    {
        $this->expectException(OpenSearchConfigurationInvalidException::class);
        $this->factory->create($this->options(['hostname' => '[::1', 'port' => 9200]));
    }
}
