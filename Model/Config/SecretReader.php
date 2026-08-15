<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\SecretReaderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Phrase;
use Magento\Store\Model\ScopeInterface;

final class SecretReader implements SecretReaderInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function getPrimaryLlmApiKey(int $storeId): SecretValue
    {
        return $this->read(Path::LLM_API_KEY, $storeId, 'primary LLM');
    }

    public function getFallbackLlmApiKey(int $storeId): SecretValue
    {
        return $this->read(Path::FALLBACK_API_KEY, $storeId, 'fallback LLM');
    }

    public function getEmbeddingApiKey(int $storeId): SecretValue
    {
        return $this->read(Path::EMBEDDING_API_KEY, $storeId, 'embedding');
    }

    private function read(string $path, int $storeId, string $label): SecretValue
    {
        $stored = (string) $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            (string) $storeId
        );

        if ($stored === '') {
            return SecretValue::empty();
        }

        try {
            $decrypted = $this->encryptor->decrypt($stored);
        } catch (\Throwable $cause) {
            throw new ConfigurationException(
                new Phrase(
                    'Unable to decrypt the %1 API key for store %2. Re-save the configuration.',
                    [$label, (string) $storeId]
                ),
                $cause instanceof \Exception ? $cause : null
            );
        }

        if ($decrypted === '' || $decrypted === null) {
            throw new ConfigurationException(
                new Phrase(
                    'The stored %1 API key for store %2 cannot be decrypted. Re-save the configuration.',
                    [$label, (string) $storeId]
                )
            );
        }

        return new SecretValue((string) $decrypted);
    }
}
