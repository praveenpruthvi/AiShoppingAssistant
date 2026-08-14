<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderNotFoundException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderPolicyViolationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRateLimitException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRefusalException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTransportException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\FallbackEligibilityPolicy;
use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;

class FallbackEligibilityPolicyTest extends TestCase
{
    public function testTransientAvailabilityFailuresAreEligible(): void
    {
        $policy = new FallbackEligibilityPolicy();

        self::assertTrue($policy->isEligible(new ProviderTimeoutException(new Phrase('Timeout.'))));
        self::assertTrue($policy->isEligible(new ProviderRateLimitException(new Phrase('Rate limited.'))));
        self::assertTrue($policy->isEligible(new ProviderTransportException(new Phrase('Transport.'))));
        self::assertTrue($policy->isEligible(new ProviderUnavailableException(new Phrase('Unavailable.'))));
    }

    public function testSafetyAndValidationFailuresAreNeverEligible(): void
    {
        $policy = new FallbackEligibilityPolicy();

        self::assertFalse($policy->isEligible(new ProviderConfigurationException(new Phrase('Config.'))));
        self::assertFalse($policy->isEligible(new ProviderAuthenticationException(new Phrase('Auth.'))));
        self::assertFalse($policy->isEligible(new ProviderInvalidResponseException(new Phrase('Invalid.'))));
        self::assertFalse($policy->isEligible(new ProviderRefusalException(new Phrase('Refused.'))));
        self::assertFalse($policy->isEligible(new ProviderPolicyViolationException(new Phrase('Policy.'))));
        self::assertFalse($policy->isEligible(new ProviderNotFoundException(new Phrase('Missing.'))));
    }

    public function testUnknownExceptionsFailClosed(): void
    {
        $policy = new FallbackEligibilityPolicy();

        self::assertFalse($policy->isEligible(new \RuntimeException('Boom')));
        self::assertFalse($policy->isEligible(new \Error('Boom')));
        self::assertFalse($policy->isEligible(new \Exception('Boom')));
    }
}