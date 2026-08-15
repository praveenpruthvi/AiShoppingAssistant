<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Exception;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderNotFoundException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderPolicyViolationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRateLimitException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRefusalException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTransportException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderUnavailableException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;

class ProviderExceptionTest extends TestCase
{
    /**
     * @var list<class-string<ProviderException>>
     */
    private const EXCEPTION_CLASSES = [
        ProviderConfigurationException::class,
        ProviderAuthenticationException::class,
        ProviderRateLimitException::class,
        ProviderTimeoutException::class,
        ProviderTransportException::class,
        ProviderUnavailableException::class,
        ProviderInvalidResponseException::class,
        ProviderRefusalException::class,
        ProviderPolicyViolationException::class,
        ProviderNotFoundException::class,
    ];

    public function testEveryProviderExceptionExtendsTheBaseClass(): void
    {
        foreach (self::EXCEPTION_CLASSES as $class) {
            self::assertTrue(
                is_subclass_of($class, ProviderException::class),
                $class . ' must extend ProviderException.'
            );
        }
    }

    public function testEveryProviderExceptionExposesAStableErrorCode(): void
    {
        foreach (self::EXCEPTION_CLASSES as $class) {
            $exception = new $class(new Phrase('A sanitized message.'));
            $errorCode = $exception->errorCode();

            self::assertIsString($errorCode);
            self::assertNotSame('', $errorCode);
            self::assertSame($errorCode, $class::ERROR_CODE);
        }
    }

    public function testErrorCodesAreUnique(): void
    {
        $codes = [];

        foreach (self::EXCEPTION_CLASSES as $class) {
            $code = $class::ERROR_CODE;
            self::assertArrayNotHasKey($code, $codes, 'Duplicate error code ' . $code);
            $codes[$code] = true;
        }
    }

    public function testMessagesAreGenericAndDoNotExposeCauses(): void
    {
        $secret = 'sk-prod-very-secret-token-value';

        foreach (self::EXCEPTION_CLASSES as $class) {
            $exception = new $class(
                new Phrase('The provider could not complete the request.'),
                new \RuntimeException('Cause leaked: ' . $secret)
            );

            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringContainsString('provider', strtolower($exception->getMessage()));
        }
    }

    public function testBaseClassConstructorAcceptsNullCause(): void
    {
        $exception = new ProviderTimeoutException(new Phrase('Timeout.'));

        self::assertInstanceOf(ProviderException::class, $exception);
        self::assertNull($exception->getPrevious());
    }
}
