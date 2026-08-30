<?php

declare(strict_types=1);

namespace BitExpert\SyliusWishlistConciergePlugin\Service;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\WishlistPlugin\Entity\WishlistInterface;

final readonly class BudgetOptimizer
{
    public function __construct(
        private ChannelContextInterface $channelContext,
    ) {
    }

    /**
     * @return array{chosen:string[], totalCents:int, savedCents:int, explanation:string, totalOriginal:int}
     */
    public function optimize(WishlistInterface $wishlist, int $budgetCents): array
    {
        $channel = $wishlist->getChannel() ?? $this->channelContext->getChannel();
        $items = [];

        foreach ($wishlist->getWishlistProducts() as $wp) {
            $variant = $wp->getVariant();
            if (null === $variant) {
                continue;
            }
            $pricing = $variant->getChannelPricingForChannel($channel);
            if (null === $pricing) {
                continue;
            }
            $unitPrice = $pricing->getPrice() ?? 0;
            $unitOriginal = $pricing->getOriginalPrice() ?? $unitPrice;
            $quantity = $wp->getQuantity();
            // TODO: Use Money Value Object post-contest (Sylius Money)
            $items[] = [
                'variantCode' => $variant->getCode(),
                'price' => $unitPrice * $quantity,
                'original' => $unitOriginal * $quantity,
                'wishlistProduct' => $wp,
            ];
        }

        usort($items, fn(array $a, array $b) => $a['price'] <=> $b['price']);

        $chosen = [];
        $total = 0;
        $totalOriginal = 0;

        foreach ($items as $item) {
            if ($total + $item['price'] <= $budgetCents) {
                $chosen[] = $item['variantCode'];
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

        $explanation = $this->buildExplanation($chosen, $total, $totalOriginal, $budgetCents, count($items));

        return [
            'chosen' => $chosen,
            'totalCents' => $total,
            'savedCents' => max(0, $saved),
            'totalOriginal' => $totalOriginal,
            'explanation' => $explanation,
        ];
    }

    private function buildExplanation(array $chosen, int $total, int $totalOriginal, int $budget, int $totalItems): string
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
        $promoNote = $saved > 0 ? sprintf(' You save $%.2f via catalog promotions.', $saved / 100) : '';

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
