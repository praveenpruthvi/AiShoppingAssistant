<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Tool\CartMutationConfirmationService;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * This is the server-side enforcement behind the cart-mutation confirmation
 * gate, so its guarantees are tested directly and explicitly: a token
 * cannot be redeemed twice, cannot be redeemed with a mismatched proposal,
 * and — the specific defense against a model completing an "auto-
 * confirmed" mutation within one automated tool-call round-trip — cannot be
 * redeemed in the same turn that created it.
 */
#[CoversClass(CartMutationConfirmationService::class)]
final class CartMutationConfirmationServiceTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private array $store = [];

    private function service(): CartMutationConfirmationService
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            fn (string $id) => $this->store[$id] ?? false
        );
        $cache->method('save')->willReturnCallback(
            function (string $data, string $id) {
                $this->store[$id] = $data;

                return true;
            }
        );
        $cache->method('remove')->willReturnCallback(
            function (string $id) {
                unset($this->store[$id]);

                return true;
            }
        );

        return new CartMutationConfirmationService($cache);
    }

    public function testRedeemsAMatchingTokenFromADifferentTurn(): void
    {
        $service = $this->service();
        $proposal = ['action' => 'add_to_cart', 'cart_id' => 'cart-1', 'sku' => 'SKU-1', 'qty' => 2];

        $token = $service->createToken('turn-1', $proposal);

        self::assertTrue($service->redeem($token, 'turn-2', $proposal));
    }

    public function testRefusesRedemptionInTheSameTurnThatCreatedTheToken(): void
    {
        $service = $this->service();
        $proposal = ['action' => 'add_to_cart', 'cart_id' => 'cart-1', 'sku' => 'SKU-1', 'qty' => 2];

        $token = $service->createToken('turn-1', $proposal);

        self::assertFalse($service->redeem($token, 'turn-1', $proposal));
    }

    public function testRefusesRedemptionOfAConsumedToken(): void
    {
        $service = $this->service();
        $proposal = ['action' => 'add_to_cart', 'cart_id' => 'cart-1', 'sku' => 'SKU-1', 'qty' => 2];

        $token = $service->createToken('turn-1', $proposal);

        self::assertTrue($service->redeem($token, 'turn-2', $proposal));
        self::assertFalse($service->redeem($token, 'turn-2', $proposal));
    }

    public function testRefusesRedemptionWhenTheProposalDoesNotMatch(): void
    {
        $service = $this->service();
        $original = ['action' => 'add_to_cart', 'cart_id' => 'cart-1', 'sku' => 'SKU-1', 'qty' => 2];
        $different = ['action' => 'add_to_cart', 'cart_id' => 'cart-1', 'sku' => 'SKU-1', 'qty' => 5];

        $token = $service->createToken('turn-1', $original);

        self::assertFalse($service->redeem($token, 'turn-2', $different));
    }

    public function testRefusesRedemptionOfAnUnknownToken(): void
    {
        $service = $this->service();

        self::assertFalse($service->redeem('never-issued', 'turn-2', ['action' => 'add_to_cart']));
    }

    public function testAMismatchedRedemptionAttemptStillConsumesTheToken(): void
    {
        // Burning the token on any redemption attempt (matched or not)
        // prevents brute-forcing the correct proposal shape against a
        // still-live token.
        $service = $this->service();
        $original = ['action' => 'add_to_cart', 'cart_id' => 'cart-1', 'sku' => 'SKU-1', 'qty' => 2];
        $different = ['action' => 'add_to_cart', 'cart_id' => 'cart-1', 'sku' => 'SKU-1', 'qty' => 5];

        $token = $service->createToken('turn-1', $original);

        self::assertFalse($service->redeem($token, 'turn-2', $different));
        self::assertFalse($service->redeem($token, 'turn-2', $original));
    }

    public function testTokensForDifferentProposalsAreIndependent(): void
    {
        $service = $this->service();
        $proposalA = ['action' => 'add_to_cart', 'cart_id' => 'cart-1', 'sku' => 'SKU-1', 'qty' => 2];
        $proposalB = ['action' => 'remove_from_cart', 'cart_id' => 'cart-1', 'sku' => 'SKU-2'];

        $tokenA = $service->createToken('turn-1', $proposalA);
        $tokenB = $service->createToken('turn-1', $proposalB);

        self::assertNotSame($tokenA, $tokenB);
        self::assertTrue($service->redeem($tokenA, 'turn-2', $proposalA));
        self::assertTrue($service->redeem($tokenB, 'turn-2', $proposalB));
    }
}
