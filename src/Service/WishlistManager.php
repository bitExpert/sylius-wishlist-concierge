<?php

declare(strict_types=1);

namespace BitExpert\SyliusWishlistConciergePlugin\Service;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\WishlistPlugin\Entity\WishlistInterface;
use Sylius\WishlistPlugin\Factory\WishlistFactoryInterface;
use Sylius\WishlistPlugin\Factory\WishlistProductFactoryInterface;
use Sylius\WishlistPlugin\Repository\WishlistRepositoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class WishlistManager
{
    public function __construct(
        private WishlistFactoryInterface $wishlistFactory,
        private WishlistRepositoryInterface $wishlistRepository,
        private WishlistProductFactoryInterface $wishlistProductFactory,
        private ProductVariantRepositoryInterface $variantRepository,
        private ChannelContextInterface $channelContext,
        private ChannelRepositoryInterface $channelRepository,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function findOrCreate(?string $token = null): WishlistInterface
    {
        if (null !== $token) {
            $found = $this->wishlistRepository->findByToken($token);
            if (null !== $found) {
                return $found;
            }
        }

        $user = $this->resolveShopUser();
        $channel = $this->channelContext->getChannel();

        if ($user instanceof ShopUserInterface) {
            $existing = $this->wishlistRepository->findOneByShopUserAndChannel($user, $channel);
            if (null !== $existing) {
                return $existing;
            }
            return $this->wishlistFactory->createForUserAndChannel($user, $channel);
        }

        return $this->wishlistFactory->createNew();
    }

    public function createThemed(string $name, string $theme, ?string $channelCode = null): WishlistInterface
    {
        $wishlist = $this->findOrCreate();
        $wishlist->setName($name);

        if (!str_contains(strtolower($name), strtolower($theme))) {
            $wishlist->setName(sprintf('%s — %s', $name, $theme));
        }

        $channel = null;
        if (null !== $channelCode) {
            $channel = $this->channelRepository->findOneBy(['code' => $channelCode]);
        }

        if (null === $channel) {
            try {
                $channel = $this->channelContext->getChannel();
            } catch (ChannelNotFoundException) {
                $channel = null;
            }
        }

        if (null !== $channel && null === $wishlist->getChannel()) {
            $wishlist->setChannel($channel);
        }

        return $wishlist;
    }

    public function addItem(WishlistInterface $wishlist, string $variantCode, int $quantity = 1): WishlistInterface
    {
        $variant = $this->variantRepository->findOneBy(['code' => $variantCode]);
        if (null === $variant) {
            throw new \InvalidArgumentException(sprintf('Variant "%s" not found', $variantCode));
        }

        if ($wishlist->hasProductVariant($variant)) {
            foreach ($wishlist->getWishlistProducts() as $wp) {
                if ($wp->getVariant()?->getCode() === $variantCode) {
                    $wp->setQuantity($wp->getQuantity() + $quantity);
                    return $wishlist;
                }
            }
        }

        $product = $variant->getProduct();
        if (null === $product) {
            throw new \InvalidArgumentException(sprintf('Variant "%s" has no product', $variantCode));
        }

        /** @var \Sylius\WishlistPlugin\Entity\WishlistProductInterface $wishlistProduct */
        $wishlistProduct = $this->wishlistProductFactory->createNew();
        $wishlistProduct->setProduct($product);
        $wishlistProduct->setVariant($variant);
        $wishlistProduct->setQuantity($quantity);

        $wishlist->addWishlistProduct($wishlistProduct);

        return $wishlist;
    }

    public function removeItem(WishlistInterface $wishlist, int $itemId): WishlistInterface
    {
        foreach ($wishlist->getWishlistProducts() as $wp) {
            if ($wp->getId() === $itemId) {
                $wishlist->removeWishlistProduct($wp);
                return $wishlist;
            }
        }

        throw new \InvalidArgumentException(sprintf('Item %d not found in wishlist.', $itemId));
    }

    public function toArray(WishlistInterface $wishlist, ?string $locale = null): array
    {
        $resolvedLocale = $locale ?? $wishlist->getChannel()?->getDefaultLocale()?->getCode() ?? 'en_US';
        $products = [];
        foreach ($wishlist->getWishlistProducts() as $wp) {
            $variant = $wp->getVariant();
            $product = $wp->getProduct();
            $channel = $wishlist->getChannel() ?? $this->channelContext->getChannel();
            $pricing = $variant?->getChannelPricingForChannel($channel);
            $products[] = [
                'wishlistProductId' => $wp->getId(),
                'variantCode' => $variant?->getCode(),
                'productCode' => $product?->getCode(),
                'productName' => $product?->getTranslation($resolvedLocale)?->getName(),
                'quantity' => $wp->getQuantity(),
                'price' => $pricing?->getPrice(),
                'originalPrice' => $pricing?->getOriginalPrice(),
            ];
        }

        return [
            'id' => $wishlist->getId(),
            'token' => $wishlist->getToken(),
            'name' => $wishlist->getName(),
            'channelCode' => $wishlist->getChannel()?->getCode(),
            'items' => $products,
            'itemsCount' => count($products),
        ];
    }

    private function resolveShopUser(): ?ShopUserInterface
    {
        $token = $this->tokenStorage->getToken();
        if (null === $token) {
            return null;
        }
        $user = $token->getUser();
        if ($user instanceof ShopUserInterface) {
            return $user;
        }
        return null;
    }
}
