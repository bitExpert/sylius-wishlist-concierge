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

namespace BitExpert\SyliusWishlistConciergePlugin\Security;

use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\WishlistPlugin\Entity\WishlistInterface;
use Sylius\WishlistPlugin\Resolver\WishlistCookieTokenResolverInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class WishlistAccessChecker
{
    public function __construct(
        private Security $security,
        private WishlistCookieTokenResolverInterface $cookieTokenResolver,
    ) {
    }

    public function assertCanView(WishlistInterface $wishlist): void
    {
        $user = $this->security->getUser();

        // Owned wishlist — only owner may view
        if (null !== $wishlist->getShopUser()) {
            if (!$user instanceof ShopUserInterface) {
                throw new AccessDeniedHttpException('Authentication required to view this wishlist.');
            }
            if ($wishlist->getShopUser()->getId() !== $user->getId()) {
                throw new AccessDeniedHttpException('You do not own this wishlist.');
            }
            return;
        }

        // Anonymous wishlist — only the visitor whose cookie token matches the
        // wishlist token may view it. This mirrors the Sylius WishlistPlugin flow.
        $cookieToken = $this->cookieTokenResolver->resolve();
        if ($wishlist->getToken() !== $cookieToken) {
            throw new AccessDeniedHttpException('Wishlist token does not match your session.');
        }
    }

    public function assertCanModify(WishlistInterface $wishlist): void
    {
        // Same policy for modify as view for this plugin
        $this->assertCanView($wishlist);
    }
}
