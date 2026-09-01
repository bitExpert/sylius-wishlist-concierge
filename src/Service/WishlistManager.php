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
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistBulkAddRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistClearRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistDeleteRequest;
use Doctrine\ORM\EntityManagerInterface;

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
        private EntityManagerInterface $entityManager,
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
                $wishlist->removeProduct($wp);
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

    /**
     * @return array<int, array{variantCode: string, quantity: int, status: 'added'|'skipped', reason?: string}>
     */
    public function bulkAddItems(WishlistInterface $wishlist, array $items): array
    {
        $results = [];
        
        foreach ($items as $item) {
            $variantCode = $item['variantCode'];
            $quantity = $item['quantity'] ?? 1;
            
            $variant = $this->variantRepository->findOneBy(['code' => $variantCode]);
            
            if (null === $variant) {
                $results[] = [
                    'variantCode' => $variantCode,
                    'quantity' => $quantity,
                    'status' => 'skipped',
                    'reason' => sprintf('Variant "%s" not found', $variantCode),
                ];
                continue;
            }
            
            if ($wishlist->hasProductVariant($variant)) {
                foreach ($wishlist->getWishlistProducts() as $wp) {
                    if ($wp->getVariant()?->getCode() === $variantCode) {
                        $wp->setQuantity($wp->getQuantity() + $quantity);
                        $results[] = [
                            'variantCode' => $variantCode,
                            'quantity' => $quantity,
                            'status' => 'skipped',
                            'reason' => sprintf('Variant "%s" already in wishlist, quantity updated', $variantCode),
                        ];
                        continue 2;
                    }
                }
            }
            
            $product = $variant->getProduct();
            if (null === $product) {
                $results[] = [
                    'variantCode' => $variantCode,
                    'quantity' => $quantity,
                    'status' => 'skipped',
                    'reason' => sprintf('Variant "%s" has no product', $variantCode),
                ];
                continue;
            }
            
            /** @var \Sylius\WishlistPlugin\Entity\WishlistProductInterface $wishlistProduct */
            $wishlistProduct = $this->wishlistProductFactory->createNew();
            $wishlistProduct->setProduct($product);
            $wishlistProduct->setVariant($variant);
            $wishlistProduct->setQuantity($quantity);
            
            $wishlist->addWishlistProduct($wishlistProduct);
            
            $results[] = [
                'variantCode' => $variantCode,
                'quantity' => $quantity,
                'status' => 'added',
            ];
        }
        
        return $results;
    }

    public function bulkAddItemsFromRequest(WishlistInterface $wishlist, WishlistBulkAddRequest $request): array
    {
        $items = [];
        foreach ($request->items as $item) {
            $items[] = [
                'variantCode' => $item->variantCode,
                'quantity' => $item->quantity,
            ];
        }
        
        return $this->bulkAddItems($wishlist, $items);
    }

    public function clearAllItems(WishlistInterface $wishlist): WishlistInterface
    {
        foreach ($wishlist->getWishlistProducts() as $wp) {
            $wishlist->removeProduct($wp);
        }
        
        return $wishlist;
    }

    public function clearAllItemsFromRequest(WishlistInterface $wishlist, WishlistClearRequest $request): WishlistInterface
    {
        return $this->clearAllItems($wishlist);
    }

    public function deleteWishlist(WishlistInterface $wishlist): void
    {
        $this->entityManager->remove($wishlist);
    }

    public function deleteWishlistFromRequest(WishlistInterface $wishlist, WishlistDeleteRequest $request): void
    {
        $this->deleteWishlist($wishlist);
    }
}
