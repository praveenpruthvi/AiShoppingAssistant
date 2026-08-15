<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Embedding\Exception;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingDimensionException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingInputException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingRateLimitException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingTransportException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;

final class EmbeddingExceptionTest extends TestCase
{
    /**
     * @return list<array{class-string<ProviderException>, string}>
     */
    public static function exceptions(): array
    {
        return [
            [EmbeddingConfigurationException::class, 'embedding_configuration_invalid'],
            [EmbeddingInputException::class, 'embedding_input_invalid'],
            [EmbeddingAuthenticationException::class, 'embedding_authentication_failed'],
            [EmbeddingRateLimitException::class, 'embedding_rate_limited'],
            [EmbeddingUnavailableException::class, 'embedding_provider_unavailable'],
            [EmbeddingTimeoutException::class, 'embedding_timeout'],
            [EmbeddingResponseException::class, 'embedding_response_invalid'],
            [EmbeddingDimensionException::class, 'embedding_dimension_mismatch'],
            [EmbeddingTransportException::class, 'embedding_transport_failed'],
        ];
    }

    /**
     * @dataProvider exceptions
     *
     * @param class-string<ProviderException> $class
     */
    public function testErrorCodesAreStableAndMessagesAreSanitized(string $class, string $code): void
    {
        $exception = new $class(new Phrase('A generic embedding failure occurred.'));

        self::assertInstanceOf(ProviderException::class, $exception);
        self::assertSame($code, $exception->errorCode());
        self::assertSame('A generic embedding failure occurred.', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    /**
     * @dataProvider exceptions
     *
     * @param class-string<ProviderException> $class
     */
    public function testCausesArePreserved(string $class, string $code): void
    {
        $cause = new \RuntimeException('inner');

        $exception = new $class(new Phrase('A generic embedding failure occurred.'), $cause);

        self::assertSame($code, $exception->errorCode());
        self::assertSame('inner', $exception->getPrevious()?->getMessage());
    }
}
