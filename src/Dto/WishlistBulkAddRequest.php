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

final class WishlistBulkAddRequest
{
    use DtoFromRequest;

    #[Assert\NotBlank(message: 'Items array must not be empty.')]
    #[Assert\Count(min: 1, max: 200, minMessage: 'At least one item is required.')]
    /** @phpstan-ignore-next-line */
    public iterable $items = [];

    public function __construct()
    {
        $this->items = [];
    }
}
