<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Http;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpUrlPolicy::class)]
final class HttpUrlPolicyTest extends TestCase
{
    public function testHttpsUrlIsAllowed(): void
    {
        $policy = new HttpUrlPolicy();

        self::assertTrue($policy->isAllowed('https://api.openai.com/v1'));
        self::assertTrue($policy->isAllowed('https://api.openai.com/v1', httpsOnly: true));
    }

    public function testHttpUrlIsAllowedWhenHttpsIsNotRequired(): void
    {
        $policy = new HttpUrlPolicy();

        self::assertTrue($policy->isAllowed('http://127.0.0.1:11434/v1'));
    }

    public function testHttpUrlIsRejectedWhenHttpsIsRequired(): void
    {
        $policy = new HttpUrlPolicy();

        self::assertFalse($policy->isAllowed('http://127.0.0.1:11434/v1', httpsOnly: true));
    }

    public function testUnsupportedSchemeIsRejected(): void
    {
        $policy = new HttpUrlPolicy();

        self::assertFalse($policy->isAllowed('ftp://example.test/v1'));
        self::assertFalse($policy->isAllowed('file:///etc/passwd'));
        self::assertFalse($policy->isAllowed('javascript:alert(1)'));
    }

    public function testUrlWithoutHostIsRejected(): void
    {
        $policy = new HttpUrlPolicy();

        self::assertFalse($policy->isAllowed('https://'));
        self::assertFalse($policy->isAllowed('https:///v1'));
    }

    public function testEmbeddedCredentialsAreRejected(): void
    {
        $policy = new HttpUrlPolicy();

        self::assertFalse($policy->isAllowed('https://user:pass@api.openai.com/v1'));
    }

    public function testFragmentIsRejected(): void
    {
        $policy = new HttpUrlPolicy();

        self::assertFalse($policy->isAllowed('https://api.openai.com/v1#fragment'));
    }

    public function testMalformedUrlIsRejected(): void
    {
        $policy = new HttpUrlPolicy();

        self::assertFalse($policy->isAllowed('not-a-url'));
        self::assertFalse($policy->isAllowed(''));
    }
}
