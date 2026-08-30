<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Service;

use BitExpert\SyliusWishlistConciergePlugin\Service\BudgetOptimizer;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\WishlistPlugin\Entity\WishlistInterface;
use Sylius\WishlistPlugin\Entity\WishlistProductInterface;

/**
 * @covers \BitExpert\SyliusWishlistConciergePlugin\Service\BudgetOptimizer
 */
final class BudgetOptimizerTest extends TestCase
{
    public function testOptimizeSelectsCheapestWithinBudget(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($channel);

        $optimizer = new BudgetOptimizer($channelContext);

        $wishlist = $this->createMock(WishlistInterface::class);
        $wishlist->method('getChannel')->willReturn($channel);

        // Two variants: cheap 1703, expensive 7589
        $cheapPricing = $this->createMock(ChannelPricingInterface::class);
        $cheapPricing->method('getPrice')->willReturn(1703);
        $cheapPricing->method('getOriginalPrice')->willReturn(1703);
        $cheapVariant = $this->createMock(ProductVariantInterface::class);
        $cheapVariant->method('getCode')->willReturn('cheap-variant');
        $cheapVariant->method('getChannelPricingForChannel')->with($channel)->willReturn($cheapPricing);

        $expensivePricing = $this->createMock(ChannelPricingInterface::class);
        $expensivePricing->method('getPrice')->willReturn(7589);
        $expensivePricing->method('getOriginalPrice')->willReturn(7589);
        $expensiveVariant = $this->createMock(ProductVariantInterface::class);
        $expensiveVariant->method('getCode')->willReturn('expensive-variant');
        $expensiveVariant->method('getChannelPricingForChannel')->with($channel)->willReturn($expensivePricing);

        $wp1 = $this->createMock(WishlistProductInterface::class);
        $wp1->method('getVariant')->willReturn($cheapVariant);
        $wp1->method('getQuantity')->willReturn(1);

        $wp2 = $this->createMock(WishlistProductInterface::class);
        $wp2->method('getVariant')->willReturn($expensiveVariant);
        $wp2->method('getQuantity')->willReturn(1);

        $wishlist->method('getWishlistProducts')->willReturn(new ArrayCollection([$wp1, $wp2]));

        $result = $optimizer->optimize($wishlist, 8000);

        self::assertSame(['cheap-variant'], $result['chosen']);
        self::assertSame(1703, $result['totalCents']);
        self::assertStringContainsString('1 of 2', $result['explanation']);
    }

    public function testOptimizeReturnsEmptyWhenBudgetTooLow(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($channel);

        $optimizer = new BudgetOptimizer($channelContext);

        $wishlist = $this->createMock(WishlistInterface::class);
        $wishlist->method('getChannel')->willReturn($channel);

        $pricing = $this->createMock(ChannelPricingInterface::class);
        $pricing->method('getPrice')->willReturn(5000);
        $pricing->method('getOriginalPrice')->willReturn(5000);
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('any-variant');
        $variant->method('getChannelPricingForChannel')->willReturn($pricing);

        $wp = $this->createMock(WishlistProductInterface::class);
        $wp->method('getVariant')->willReturn($variant);
        $wp->method('getQuantity')->willReturn(1);

        $wishlist->method('getWishlistProducts')->willReturn(new ArrayCollection([$wp]));

        $result = $optimizer->optimize($wishlist, 1000);

        self::assertSame([], $result['chosen']);
        self::assertStringContainsString('No combination fits', $result['explanation']);
    }

    public function testOptimizeHandlesQuantity(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($channel);

        $optimizer = new BudgetOptimizer($channelContext);

        $wishlist = $this->createMock(WishlistInterface::class);
        $wishlist->method('getChannel')->willReturn($channel);

        $pricing = $this->createMock(ChannelPricingInterface::class);
        $pricing->method('getPrice')->willReturn(1000);
        $pricing->method('getOriginalPrice')->willReturn(1200);
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('qty-variant');
        $variant->method('getChannelPricingForChannel')->willReturn($pricing);

        $wp = $this->createMock(WishlistProductInterface::class);
        $wp->method('getVariant')->willReturn($variant);
        $wp->method('getQuantity')->willReturn(3); // 3000 total

        $wishlist->method('getWishlistProducts')->willReturn(new ArrayCollection([$wp]));

        $result = $optimizer->optimize($wishlist, 3500);

        self::assertSame(['qty-variant'], $result['chosen']);
        self::assertSame(3000, $result['totalCents']);
        self::assertSame(600, $result['savedCents']); // 3600 - 3000
    }
}
