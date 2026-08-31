<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Service;

use BitExpert\SyliusWishlistConciergePlugin\Service\BudgetOptimizer;
use BitExpert\SyliusWishlistConciergePlugin\Service\Promotion\EligiblePromotionsProvider;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\WishlistPlugin\Entity\WishlistInterface;
use Sylius\WishlistPlugin\Entity\WishlistProductInterface;

/**
 * @covers \BitExpert\SyliusWishlistConciergePlugin\Service\BudgetOptimizer
 */
final class BudgetOptimizerTest extends TestCase
{
    private function createOptimizer(
        ChannelContextInterface $channelContext,
        ProductVariantPricesCalculatorInterface $calculator,
        EligiblePromotionsProvider $promotionsProvider,
    ): BudgetOptimizer {
        return new BudgetOptimizer($channelContext, $calculator, $promotionsProvider);
    }

    /**
     * @param array<string, int> $prices   variantCode => price
     * @param array<string, int> $original variantCode => original price
     */
    private function createCalculator(array $prices, array $original): ProductVariantPricesCalculatorInterface
    {
        $calculator = $this->createMock(ProductVariantPricesCalculatorInterface::class);
        $calculator->method('calculate')->willReturnCallback(
            fn ($variant, $context) => $prices[$variant->getCode()] ?? 0
        );
        $calculator->method('calculateOriginal')->willReturnCallback(
            fn ($variant, $context) => $original[$variant->getCode()] ?? $prices[$variant->getCode()] ?? 0
        );

        return $calculator;
    }

    private function createPromotionsProvider(bool $include = true): EligiblePromotionsProvider
    {
        $provider = $this->createMock(EligiblePromotionsProvider::class);
        $provider->method('getActiveForChannel')->willReturn($include ? ['PROMO'] : []);
        $provider->method('summarize')->willReturnCallback(
            fn (array $promotions) => array_map(
                static fn ($p) => ['code' => (string) $p, 'name' => (string) $p],
                $promotions,
            )
        );

        return $provider;
    }

    private function createWishlist(array $variants): WishlistInterface
    {
        $channel = $this->createMock(ChannelInterface::class);
        $wishlist = $this->createMock(WishlistInterface::class);
        $wishlist->method('getChannel')->willReturn($channel);

        $wps = [];
        foreach ($variants as $code => $quantity) {
            $variant = $this->createVariant($code);
            $wp = $this->createMock(WishlistProductInterface::class);
            $wp->method('getVariant')->willReturn($variant);
            $wp->method('getQuantity')->willReturn($quantity);
            $wps[] = $wp;
        }
        $wishlist->method('getWishlistProducts')->willReturn(new ArrayCollection($wps));

        return $wishlist;
    }

    private function createVariant(string $code)
    {
        $variant = $this->createMock(\Sylius\Component\Core\Model\ProductVariantInterface::class);
        $variant->method('getCode')->willReturn($code);

        return $variant;
    }

    private function channelContext(): ChannelContextInterface
    {
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($this->createMock(ChannelInterface::class));

        return $channelContext;
    }

    public function testOptimizeSelectsCheapestWithinBudget(): void
    {
        $calculator = $this->createCalculator(['cheap-variant' => 1703, 'expensive-variant' => 7589], []);
        $provider = $this->createPromotionsProvider();
        $optimizer = $this->createOptimizer($this->channelContext(), $calculator, $provider);

        $wishlist = $this->createWishlist(['cheap-variant' => 1, 'expensive-variant' => 1]);

        $result = $optimizer->optimize($wishlist, 8000);

        self::assertSame(['cheap-variant'], $result['chosen']);
        self::assertSame(1703, $result['totalCents']);
        self::assertStringContainsString('1 of 2', $result['explanation']);
        self::assertArrayHasKey('promotionsApplied', $result);
    }

    public function testOptimizeReturnsEmptyWhenBudgetTooLow(): void
    {
        $calculator = $this->createCalculator(['any-variant' => 5000], []);
        $provider = $this->createPromotionsProvider();
        $optimizer = $this->createOptimizer($this->channelContext(), $calculator, $provider);

        $wishlist = $this->createWishlist(['any-variant' => 1]);

        $result = $optimizer->optimize($wishlist, 1000);

        self::assertSame([], $result['chosen']);
        self::assertStringContainsString('No combination fits', $result['explanation']);
    }

    public function testOptimizeHandlesQuantity(): void
    {
        $calculator = $this->createCalculator(['qty-variant' => 1000], ['qty-variant' => 1200]);
        $provider = $this->createPromotionsProvider();
        $optimizer = $this->createOptimizer($this->channelContext(), $calculator, $provider);

        $wishlist = $this->createWishlist(['qty-variant' => 3]); // 3000 total

        $result = $optimizer->optimize($wishlist, 3500);

        self::assertSame(['qty-variant'], $result['chosen']);
        self::assertSame(3000, $result['totalCents']);
        self::assertSame(600, $result['savedCents']); // 3600 - 3000
    }

    public function testOptimizeExcludesPromotionsUsesOriginalPrices(): void
    {
        // Product is discounted 1200 -> 1000 by a catalog promotion.
        $calculator = $this->createCalculator(['qty-variant' => 1000], ['qty-variant' => 1200]);
        $provider = $this->createPromotionsProvider();
        $optimizer = $this->createOptimizer($this->channelContext(), $calculator, $provider);

        $wishlist = $this->createWishlist(['qty-variant' => 1]);

        // With promotions excluded the effective price is the original 1200, so no savings.
        $result = $optimizer->optimize($wishlist, 2000, false);

        self::assertSame(['qty-variant'], $result['chosen']);
        self::assertSame(1200, $result['totalCents']);
        self::assertSame(0, $result['savedCents']);
        self::assertTrue($result['promotionsIgnored']);
        self::assertStringContainsString('excluded', $result['explanation']);
    }

    public function testOptimizePromotionLetsDiscountedItemFitBudget(): void
    {
        // Item costs 1200 originally, 1000 with a promotion. Budget 1000 is only enough with the discount.
        $calculator = $this->createCalculator(['promo-variant' => 1000], ['promo-variant' => 1200]);
        $provider = $this->createPromotionsProvider();
        $optimizer = $this->createOptimizer($this->channelContext(), $calculator, $provider);

        $wishlist = $this->createWishlist(['promo-variant' => 1]);

        $result = $optimizer->optimize($wishlist, 1000);

        self::assertSame(['promo-variant'], $result['chosen']);
        self::assertSame(1000, $result['totalCents']);
        self::assertSame(200, $result['savedCents']);
        self::assertStringContainsString('promotion', $result['explanation']);
    }
}
