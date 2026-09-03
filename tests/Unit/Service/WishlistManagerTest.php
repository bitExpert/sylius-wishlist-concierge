<?php

declare(strict_types=1);

namespace BitExpert\SyliusWishlistConciergePlugin\Tests\Unit\Service;

use BitExpert\SyliusWishlistConciergePlugin\Service\WishlistManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\WishlistPlugin\Entity\WishlistInterface;
use Sylius\WishlistPlugin\Factory\WishlistFactoryInterface;
use Sylius\WishlistPlugin\Factory\WishlistProductFactoryInterface;
use Sylius\WishlistPlugin\Repository\WishlistRepositoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Unit tests for {@see WishlistManager} focusing on the theming logic.
 */
class WishlistManagerTest extends TestCase
{
    private WishlistManager $manager;

    private $wishlistMock;

    private $channelMock;

    protected function setUp(): void
    {
        // Mocks for dependencies
        $wishlistFactory = $this->createMock(WishlistFactoryInterface::class);
        $wishlistRepository = $this->createMock(WishlistRepositoryInterface::class);
        $wishlistProductFactory = $this->createMock(WishlistProductFactoryInterface::class);
        $variantRepository = $this->createMock(ProductVariantRepositoryInterface::class);
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        // Dummy channel used by the manager
        $this->channelMock = $this->createMock(ChannelInterface::class);
        $this->channelMock->method('getCode')->willReturn('FASHION_WEB');
        $channelContext->method('getChannel')->willReturn($this->channelMock);

        // Dummy wishlist returned by the factory
        $this->wishlistMock = $this->createMock(WishlistInterface::class);
        $wishlistFactory->method('createNew')->willReturn($this->wishlistMock);
        $wishlistFactory->method('createForUserAndChannel')->willReturn($this->wishlistMock);

        // Token storage returns null (anonymous user)
        $tokenStorage->method('getToken')->willReturn(null);

        $this->manager = new WishlistManager(
            $wishlistFactory,
            $wishlistRepository,
            $wishlistProductFactory,
            $variantRepository,
            $channelContext,
            $channelRepository,
            $tokenStorage,
            $entityManager,
        );
    }

    #[Test]
    public function createsThemedWishlistAndSetsChannel(): void
    {
        $name = 'My Wishlist';
        $theme = 'summer';
        $expectedName = 'My Wishlist — summer';

        $this->wishlistMock->expects($this->once())
            ->method('setChannel')
            ->with($this->channelMock);

        $result = $this->manager->createThemed($name, $theme);
        $this->assertSame($this->wishlistMock, $result);
    }

    #[Test]
    public function keepsNameWhenThemeAlreadyContained(): void
    {
        $name = 'Summer Gifts'; // already contains the theme word
        $theme = 'summer';
        $expectedName = $name; // unchanged

        $this->wishlistMock->expects($this->once())
            ->method('setChannel')
            ->with($this->channelMock);

        $result = $this->manager->createThemed($name, $theme);
        $this->assertSame($this->wishlistMock, $result);
    }
}
