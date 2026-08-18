<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\ChatEndpointPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatEndpointPolicy::class)]
final class ChatEndpointPolicyTest extends TestCase
{
    public function testCloudProviderUsesOfficialDefaultWhenBaseUrlIsEmpty(): void
    {
        $policy = $this->policy();

        self::assertSame(
            'https://api.openai.com/v1/chat/completions',
            $policy->chatEndpoint(ProviderIdentifiers::LLM_OPENAI, '', 'https://api.openai.com/v1')
        );
    }

    public function testCloudProviderAllowsOfficialDefaultAsOverride(): void
    {
        $policy = $this->policy();

        self::assertSame(
            'https://api.openai.com/v1/chat/completions',
            $policy->chatEndpoint(ProviderIdentifiers::LLM_OPENAI, 'HTTPS://API.OPENAI.COM/V1/', 'https://api.openai.com/v1')
        );
    }

    public function testCloudProviderRejectsDifferentOverrideFailClosed(): void
    {
        $policy = $this->policy();

        $this->expectException(ProviderConfigurationException::class);
        $policy->chatEndpoint(ProviderIdentifiers::LLM_OPENAI, 'https://evil.example.test/v1', 'https://api.openai.com/v1');
    }

    public function testLocalProviderRequiresExplicitBaseUrl(): void
    {
        $policy = $this->policy();

        $this->expectException(ProviderConfigurationException::class);
        $policy->chatEndpoint(ProviderIdentifiers::LLM_OPENAI_COMPATIBLE, '', '');
    }

    public function testLocalProviderAllowsHttpAndHttps(): void
    {
        $policy = $this->policy();

        self::assertSame(
            'http://127.0.0.1:11434/v1/chat/completions',
            $policy->chatEndpoint(ProviderIdentifiers::LLM_OPENAI_COMPATIBLE, 'http://127.0.0.1:11434/v1', '')
        );
        self::assertSame(
            'https://local.example.test/v1/chat/completions',
            $policy->chatEndpoint(ProviderIdentifiers::LLM_OPENAI_COMPATIBLE, 'https://local.example.test/v1', '')
        );
    }

    public function testLocalProviderRejectsCredentialsAndFragments(): void
    {
        $policy = $this->policy();

        $this->expectException(ProviderConfigurationException::class);
        $policy->chatEndpoint(ProviderIdentifiers::LLM_OPENAI_COMPATIBLE, 'https://user:pass@local.example.test/v1', '');
    }

    private function policy(): ChatEndpointPolicy
    {
        return new ChatEndpointPolicy(new HttpUrlPolicy());
    }
}
