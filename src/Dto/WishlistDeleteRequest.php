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

namespace BitExpert\SyliusWishlistConciergePlugin\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class WishlistDeleteRequest
{
    use DtoFromRequest;

    #[Assert\NotNull]
    #[Assert\Positive(message: 'Wishlist ID must be positive.')]
    public int $wishlistId;
}
