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

namespace BitExpert\SyliusWishlistConciergePlugin\Service\Promotion;

use Sylius\Bundle\PromotionBundle\Provider\EligibleCatalogPromotionsProviderInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Promotion\Model\CatalogPromotionInterface;

class EligiblePromotionsProvider
{
    public function __construct(
        private EligibleCatalogPromotionsProviderInterface $eligibleCatalogPromotionsProvider,
        private ChannelContextInterface $channelContext,
    ) {
    }

    /**
     * Returns the catalog promotions that are currently eligible (enabled and within their
     * active date range) for the given channel.
     *
     * @return CatalogPromotionInterface[]
     */
    public function getActiveForChannel(ChannelInterface $channel): array
    {
        $eligible = $this->eligibleCatalogPromotionsProvider->provide();

        return array_values(array_filter(
            $eligible,
            static fn (CatalogPromotionInterface $promotion): bool => self::appliesToChannel($promotion, $channel),
        ));
    }

    /**
     * @return CatalogPromotionInterface[]
     */
    public function getActiveForCurrentChannel(): array
    {
        return $this->getActiveForChannel($this->channelContext->getChannel());
    }

    /**
     * Builds a slim, serialisable summary of the given promotions for WebMCP/UI responses.
     *
     * @param CatalogPromotionInterface[] $promotions
     *
     * @return array<int, array{code:string, name:string}>
     */
    public function summarize(array $promotions): array
    {
        return array_map(
            static fn (CatalogPromotionInterface $promotion): array => [
                'code' => (string) $promotion->getCode(),
                'name' => (string) $promotion->getName(),
            ],
            $promotions,
        );
    }

    private static function appliesToChannel(CatalogPromotionInterface $promotion, ChannelInterface $channel): bool
    {
        $channelCode = $channel->getCode();
        foreach ($promotion->getChannels() as $promotionChannel) {
            if ($promotionChannel->getCode() === $channelCode) {
                return true;
            }
        }

        return false;
    }
}
