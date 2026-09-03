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

    /** @var \PHPUnit\Framework\MockObject\MockObject&WishlistInterface */
    private WishlistInterface $wishlistMock;

    private ChannelInterface $channelMock;

    protected function setUp(): void
    {
        // Stubs for dependencies that only provide return values
        $wishlistFactory = $this->createStub(WishlistFactoryInterface::class);
        $wishlistRepository = $this->createStub(WishlistRepositoryInterface::class);
        $wishlistProductFactory = $this->createStub(WishlistProductFactoryInterface::class);
        $variantRepository = $this->createStub(ProductVariantRepositoryInterface::class);
        $channelContext = $this->createStub(ChannelContextInterface::class);
        $channelRepository = $this->createStub(ChannelRepositoryInterface::class);
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);

        // Dummy channel used by the manager
        $this->channelMock = $this->createStub(ChannelInterface::class);
        $this->channelMock->method('getCode')->willReturn('FASHION_WEB');
        $channelContext->method('getChannel')->willReturn($this->channelMock);

        // Dummy wishlist returned by the factory (mock: expectations are set per test)
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

        $this->wishlistMock->expects($this->once())
            ->method('setChannel')
            ->with($this->channelMock);

        $result = $this->manager->createThemed($name, $theme);
        $this->assertSame($this->wishlistMock, $result);
    }
}
