<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingRequestInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingInput;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingInputType;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingRequest;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Embedding\ProviderEndpointPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\ProviderHttpTransport;
use Laminas\Http\Response;
use Magento\Framework\HTTP\LaminasClient;
use PHPUnit\Framework\TestCase;

/**
 * Shared harness for embedding adapter tests.
 *
 * Uses a real ProviderHttpTransport over a partially-mocked Laminas client so
 * the full transport boundary (headers, body, status mapping) is exercised
 * without any network access.
 */
abstract class AbstractEmbeddingProviderTestCase extends TestCase
{
    protected const MODEL = 'text-embedding-test';
    protected const DIMENSIONS = 3;

    protected ?LaminasClient $client = null;

    protected function endpointPolicy(): ProviderEndpointPolicy
    {
        return new ProviderEndpointPolicy(new HttpUrlPolicy());
    }

    /**
     * @param list<string> $texts
     */
    protected function request(
        array $texts = ['blue shoe'],
        string $model = self::MODEL,
        int $dimensions = self::DIMENSIONS,
        string $baseUrl = '',
        bool $withApiKey = true,
        string $inputType = 'query'
    ): EmbeddingRequestInterface {
        $inputs = [];
        foreach (array_values($texts) as $index => $text) {
            $inputs[] = new EmbeddingInput($text, (string) $index);
        }

        return new EmbeddingRequest(
            1,
            $inputType === 'document' ? EmbeddingInputType::document() : EmbeddingInputType::query(),
            $inputs,
            $model,
            $baseUrl,
            $withApiKey ? new SecretValue('key-123') : SecretValue::empty(),
            20,
            $dimensions
        );
    }

    protected function transport(string $rawResponse): ProviderHttpTransport
    {
        $this->client = $this->getMockBuilder(LaminasClient::class)
            ->onlyMethods(['send'])
            ->setConstructorArgs([])
            ->getMock();

        $this->client->method('send')->willReturn(Response::fromString($rawResponse));

        return new ProviderHttpTransport($this->client, new HttpUrlPolicy());
    }
}