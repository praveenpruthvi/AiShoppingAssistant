<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Config;

use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Config\Path;
use Aavirbhava\AiShoppingAssistant\Model\Config\SecretReader;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretReader::class)]
final class SecretReaderTest extends TestCase
{
    /**
     * @param array<string, string> $values
     */
    private function reader(array $values, EncryptorInterface $encryptor): SecretReader
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(
                static fn (string $path): mixed => $values[$path] ?? null
            );

        return new SecretReader($scopeConfig, $encryptor);
    }

    private function encryptor(array $decryptedByCipher): EncryptorInterface
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')
            ->willReturnCallback(
                static fn (string $cipher): string => $decryptedByCipher[$cipher] ?? ''
            );

        return $encryptor;
    }

    public function testDecryptsPrimaryLlmApiKey(): void
    {
        $reader = $this->reader(
            [Path::LLM_API_KEY => 'cipher-primary'],
            $this->encryptor(['cipher-primary' => 'sk-primary-secret'])
        );

        $secret = $reader->getPrimaryLlmApiKey(1);
        self::assertFalse($secret->isEmpty());
        self::assertSame('sk-primary-secret', $secret->reveal());
    }

    public function testDecryptsFallbackLlmApiKey(): void
    {
        $reader = $this->reader(
            [Path::FALLBACK_API_KEY => 'cipher-fallback'],
            $this->encryptor(['cipher-fallback' => 'sk-fallback-secret'])
        );

        $secret = $reader->getFallbackLlmApiKey(1);
        self::assertSame('sk-fallback-secret', $secret->reveal());
    }

    public function testDecryptsEmbeddingApiKey(): void
    {
        $reader = $this->reader(
            [Path::EMBEDDING_API_KEY => 'cipher-embedding'],
            $this->encryptor(['cipher-embedding' => 'sk-embedding-secret'])
        );

        $secret = $reader->getEmbeddingApiKey(1);
        self::assertSame('sk-embedding-secret', $secret->reveal());
    }

    public function testEmptyApiKeyIsAllowedForLocalProviders(): void
    {
        $reader = $this->reader([], $this->encryptor([]));

        $secret = $reader->getPrimaryLlmApiKey(1);
        self::assertTrue($secret->isEmpty());
        self::assertSame('', $secret->reveal());
    }

    public function testEmptyStoredValueReturnsEmptySecretWithoutDecrypting(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($this->never())->method('decrypt');

        $reader = $this->reader([Path::EMBEDDING_API_KEY => ''], $encryptor);

        $secret = $reader->getEmbeddingApiKey(1);
        self::assertTrue($secret->isEmpty());
    }

    public function testReadsSecretsForExplicitStoreScope(): void
    {
        $storeId = 9;
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('getValue')
            ->with(Path::LLM_API_KEY, ScopeInterface::SCOPE_STORE, (string) $storeId)
            ->willReturn('cipher');

        $encryptor = $this->encryptor(['cipher' => 'sk-scoped']);
        $reader = new SecretReader($scopeConfig, $encryptor);

        self::assertSame('sk-scoped', $reader->getPrimaryLlmApiKey($storeId)->reveal());
    }

    public function testDecryptionFailureThrowsSanitizedExceptionWithoutSecret(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')
            ->willThrowException(new \RuntimeException('decrypt failed sk-super-secret'));

        $reader = $this->reader([Path::LLM_API_KEY => 'cipher-with-secret'], $encryptor);

        try {
            $reader->getPrimaryLlmApiKey(1);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $exception) {
            $message = $exception->getMessage();
            self::assertStringNotContainsString('sk-super-secret', $message);
            self::assertStringNotContainsString('cipher-with-secret', $message);
            self::assertStringContainsString('store 1', $message);
        }
    }

    public function testNonEmptyCipherDecryptingToEmptyIsTreatedAsConfigurationError(): void
    {
        $reader = $this->reader(
            [Path::LLM_API_KEY => 'cipher-broken'],
            $this->encryptor(['cipher-broken' => ''])
        );

        $this->expectException(ConfigurationException::class);

        $reader->getPrimaryLlmApiKey(1);
    }
}