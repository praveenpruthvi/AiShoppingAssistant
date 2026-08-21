<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRateLimitException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\HttpStatusMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpStatusMapper::class)]
final class HttpStatusMapperTest extends TestCase
{
    public function testA2xxStatusDoesNotThrow(): void
    {
        (new HttpStatusMapper())->assertSuccess(200);
        (new HttpStatusMapper())->assertSuccess(201);
        (new HttpStatusMapper())->assertSuccess(299);

        self::assertTrue(true);
    }

    public function testAuthenticationStatusesMapToAuthenticationException(): void
    {
        foreach ([401, 403] as $status) {
            try {
                (new HttpStatusMapper())->assertSuccess($status);
                self::fail("Expected an exception for status {$status}.");
            } catch (ProviderAuthenticationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRateLimitStatusMapsToRateLimitException(): void
    {
        $this->expectException(ProviderRateLimitException::class);
        (new HttpStatusMapper())->assertSuccess(429);
    }

    public function testTimeoutStatusesMapToTimeoutException(): void
    {
        foreach ([408, 504] as $status) {
            try {
                (new HttpStatusMapper())->assertSuccess($status);
                self::fail("Expected an exception for status {$status}.");
            } catch (ProviderTimeoutException) {
                self::assertTrue(true);
            }
        }
    }

    public function testServerErrorStatusMapsToUnavailableException(): void
    {
        $this->expectException(ProviderUnavailableException::class);
        (new HttpStatusMapper())->assertSuccess(503);
    }

    public function testUnrecognizedClientErrorMapsToInvalidResponseException(): void
    {
        $this->expectException(ProviderInvalidResponseException::class);
        (new HttpStatusMapper())->assertSuccess(418);
    }
}
