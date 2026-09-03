<?php

/*
 * This file is part of the Sylius Wishlist Concierge package.
 *
 * (c) bitExpert AG
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace BitExpert\SyliusWishlistConciergePlugin\Service;

use BitExpert\SyliusWishlistConciergePlugin\Service\Promotion\EligiblePromotionsProvider;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Exception\MissingChannelConfigurationException;
use Sylius\WishlistPlugin\Entity\WishlistInterface;

final readonly class BudgetOptimizer
{
    public function __construct(
        private ChannelContextInterface $channelContext,
        private ProductVariantPricesCalculatorInterface $productVariantPricesCalculator,
        private EligiblePromotionsProvider $eligiblePromotionsProvider,
    ) {
    }

    /**
     * @return array{
     *     chosen:array<string|null>,
     *     totalCents:int,
     *     savedCents:int,
     *     explanation:string,
     *     totalOriginal:int,
     *     promotionsApplied:array<int, array{code:string, name:string}>,
     *     promotionsIgnored:bool
     * }
     */
    public function optimize(WishlistInterface $wishlist, int $budgetCents, bool $includePromotions = true): array
    {
        $channel = $wishlist->getChannel() ?? $this->channelContext->getChannel();
        $items = [];

        foreach ($wishlist->getWishlistProducts() as $wp) {
            $variant = $wp->getVariant();
            if (null === $variant) {
                continue;
            }

            try {
                $unitOriginal = $this->productVariantPricesCalculator->calculateOriginal($variant, ['channel' => $channel]);
            } catch (MissingChannelConfigurationException) {
                continue;
            }
            $quantity = $wp->getQuantity();
            // TODO: Use Money Value Object post-contest (Sylius Money)
            if ($includePromotions) {
                try {
                    $unitPrice = $this->productVariantPricesCalculator->calculate($variant, ['channel' => $channel]);
                } catch (MissingChannelConfigurationException) {
                    $unitPrice = $unitOriginal;
                }
            } else {
                // Without promotions the effective price is the pre-discount original price.
                $unitPrice = $unitOriginal;
            }
            $items[] = [
                'variantCode' => $variant->getCode(),
                'price' => $unitPrice * $quantity,
                'original' => $unitOriginal * $quantity,
                'wishlistProduct' => $wp,
            ];
        }

        usort($items, fn (array $a, array $b) => $a['price'] <=> $b['price']);

        /**
         * @var array<string> $chosen
         */
        $chosen = [];
        $total = 0;
        $totalOriginal = 0;

        foreach ($items as $item) {
            if ($total + $item['price'] <= $budgetCents) {
                $chosen[] = (string) $item['variantCode'];
                $total += $item['price'];
                $totalOriginal += $item['original'];
            }
        }

        if ([] === $chosen && [] !== $items) {
            $cheapest = $items[0];
            if ($cheapest['price'] <= $budgetCents) {
                $chosen[] = $cheapest['variantCode'];
                $total = $cheapest['price'];
                $totalOriginal = $cheapest['original'];
            }
        }

        $saved = $totalOriginal - $total;

        $promotions = $includePromotions
            ? $this->eligiblePromotionsProvider->getActiveForChannel($channel)
            : [];

        $explanation = $this->buildExplanation(
            $chosen,
            $total,
            $totalOriginal,
            $budgetCents,
            count($items),
            $includePromotions,
            count($promotions),
        );

        return [
            'chosen' => $chosen,
            'totalCents' => $total,
            'savedCents' => max(0, $saved),
            'totalOriginal' => $totalOriginal,
            'explanation' => $explanation,
            'promotionsApplied' => $this->eligiblePromotionsProvider->summarize($promotions),
            'promotionsIgnored' => !$includePromotions,
        ];
    }

    /**
     * @param string[]|null[] $chosen
     */
    private function buildExplanation(array $chosen, int $total, int $totalOriginal, int $budget, int $totalItems, bool $includePromotions, int $activePromotions): string
    {
        if ([] === $chosen) {
            return sprintf(
                'No combination fits $%.2f budget. Cheapest wishlist item exceeds budget or wishlist is empty (%d items). Try removing items or increasing budget.',
                $budget / 100,
                $totalItems,
            );
        }

        $remaining = $budget - $total;
        $saved = $totalOriginal - $total;
        $promoNote = '';

        if ($includePromotions && $saved > 0) {
            $promoNote = sprintf(' You save $%.2f via %d active catalog promotion(s).', $saved / 100, $activePromotions);
        } elseif (!$includePromotions) {
            $promoNote = ' Catalog promotions excluded.';
        } elseif ($activePromotions > 0) {
            $promoNote = sprintf(' %d active catalog promotion(s) eligible for this channel.', $activePromotions);
        }

        if ($remaining < 100) {
            return sprintf(
                'Perfect fit: %d of %d items selected, total $%.2f (budget $%.2f, $%.2f left).%s Move to cart now.',
                count($chosen),
                $totalItems,
                $total / 100,
                $budget / 100,
                $remaining / 100,
                $promoNote,
            );
        }

        return sprintf(
            'Selected %d of %d items, total $%.2f (budget $%.2f, $%.2f remaining).%s',
            count($chosen),
            $totalItems,
            $total / 100,
            $budget / 100,
            $remaining / 100,
            $promoNote,
        );
    }
}
