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

final class WishlistBulkItem
{
    #[Assert\NotBlank(message: 'variantCode must not be blank.')]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9._\-]+$/', message: 'Invalid variant code format.')]
    public string $variantCode = '';

    #[Assert\NotNull]
    #[Assert\Positive(message: 'Quantity must be positive.')]
    #[Assert\LessThanOrEqual(value: 99, message: 'Quantity must be at most 99.')]
    public int $quantity = 1;
}
