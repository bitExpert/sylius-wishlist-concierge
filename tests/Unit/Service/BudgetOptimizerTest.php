<?php

declare(strict_types=1);

namespace BitExpert\SyliusWishlistConciergePlugin\Tests\Unit\Service;

use BitExpert\SyliusWishlistConciergePlugin\Service\BudgetOptimizer;
use BitExpert\SyliusWishlistConciergePlugin\Service\Promotion\EligiblePromotionsProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\WishlistPlugin\Entity\WishlistInterface;
use Sylius\WishlistPlugin\Entity\WishlistProductInterface;

/**
 * Unit tests for {@see BudgetOptimizer} covering edge‑case budget scenarios.
 */
class BudgetOptimizerTest extends TestCase
{
    private BudgetOptimizer $optimizer;

    private $channelMock;

    private $calculatorMock;

    private $promotionsProviderMock;

    protected function setUp(): void
    {
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $this->channelMock = $this->createMock(ChannelInterface::class);
        $channelContext->method('getChannel')->willReturn($this->channelMock);

        $this->calculatorMock = $this->createMock(ProductVariantPricesCalculatorInterface::class);
        $this->promotionsProviderMock = $this->createMock(EligiblePromotionsProvider::class);

        // Promotions provider returns empty list for simplicity
        $this->promotionsProviderMock->method('getActiveForChannel')->willReturn([]);
        $this->promotionsProviderMock->method('summarize')->willReturn([]);

        $this->optimizer = new BudgetOptimizer(
            $channelContext,
            $this->calculatorMock,
            $this->promotionsProviderMock,
        );
    }

    /**
     * Helper to build a mock WishlistProduct with a given variant code and price values.
     */
    private function mockWishlistProduct(string $variantCode, int $priceCents, int $originalCents, int $quantity = 1): WishlistProductInterface
    {
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn($variantCode);

        // Calculator expectations will be set in the test itself
        $product = $this->createMock(WishlistProductInterface::class);
        $product->method('getVariant')->willReturn($variant);
        $product->method('getQuantity')->willReturn($quantity);

        return $product;
    }

    #[Test]
    public function optimizeZeroBudgetReturnsNoItems(): void
    {
        $wishlist = $this->createMock(WishlistInterface::class);
        $wishlist->method('getChannel')->willReturn(null);
        $wishlist->method('getWishlistProducts')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $result = $this->optimizer->optimize($wishlist, 0);
        $this->assertEmpty($result['chosen']);
        $this->assertStringContainsString('No combination fits', $result['explanation']);
        $this->assertSame(0, $result['totalCents']);
        $this->assertSame(0, $result['savedCents']);
    }

    #[Test]
    public function optimizeBudgetSmallerThanCheapestItem(): void
    {
        $wishlist = $this->createMock(WishlistInterface::class);
        $wishlist->method('getChannel')->willReturn(null);

        $wp = $this->mockWishlistProduct('V1', 500, 600);
        // Configure calculator to return these prices
        $this->calculatorMock->method('calculateOriginal')->willReturn(600);
        $this->calculatorMock->method('calculate')->willReturn(500);
        $wishlist->method('getWishlistProducts')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$wp]));

        $result = $this->optimizer->optimize($wishlist, 300); // budget 3.00$ < 5.00$
        $this->assertEmpty($result['chosen']);
        $this->assertStringContainsString('No combination fits', $result['explanation']);
        $this->assertSame(0, $result['totalCents']);
        $this->assertSame(0, $result['savedCents']);
    }
}
