<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Service\Promotion;

use BitExpert\SyliusWishlistConciergePlugin\Service\Promotion\EligiblePromotionsProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PromotionBundle\Provider\EligibleCatalogPromotionsProviderInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\CatalogPromotion;
use Sylius\Component\Core\Model\CatalogPromotionInterface;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * @covers \BitExpert\SyliusWishlistConciergePlugin\Service\Promotion\EligiblePromotionsProvider
 */
final class EligiblePromotionsProviderTest extends TestCase
{
    /**
     * @param string[] $channelCodes
     */
    private function createPromotion(string $code, string $name, array $channelCodes = []): CatalogPromotionInterface
    {
        $promotion = new CatalogPromotion();
        $promotion->setCurrentLocale('en_US');
        $promotion->setCode($code);
        $promotion->setName($name);
        foreach ($channelCodes as $channelCode) {
            $channel = new \Sylius\Component\Core\Model\Channel();
            $channel->setCode($channelCode);
            $promotion->addChannel($channel);
        }

        return $promotion;
    }

    #[Test]
    public function testGetActiveForChannelFiltersByChannel(): void
    {
        $fashion = $this->createPromotion('SPRING', 'Spring Sale', ['FASHION_WEB']);
        $other = $this->createPromotion('SPORTS', 'Sports Sale', ['SPORTS_WEB']);

        $handler = $this->createMock(EligibleCatalogPromotionsProviderInterface::class);
        $handler->method('provide')->willReturn([$fashion, $other]);

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('FASHION_WEB');

        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($channel);

        $provider = new EligiblePromotionsProvider($handler, $channelContext);

        $result = $provider->getActiveForChannel($channel);

        self::assertCount(1, $result);
        self::assertSame('SPRING', $result[0]->getCode());
    }

    #[Test]
    public function testSummarizeReturnsSlimPayload(): void
    {
        $fashion = $this->createPromotion('SUMMER', 'Summer Sale', ['FASHION_WEB']);
        $handler = $this->createMock(EligibleCatalogPromotionsProviderInterface::class);
        $channelContext = $this->createMock(ChannelContextInterface::class);

        $provider = new EligiblePromotionsProvider($handler, $channelContext);

        $summary = $provider->summarize([$fashion]);

        self::assertSame(
            [['code' => 'SUMMER', 'name' => 'Summer Sale']],
            $summary,
        );
    }
}
