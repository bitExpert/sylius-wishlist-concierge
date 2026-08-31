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

final class MoveToCartRequest
{
    /**
     * @var string[]|null
     */
    #[Assert\All(constraints: [
        new Assert\NotBlank(),
        new Assert\Regex(pattern: '/^[A-Za-z0-9._\-]+$/'),
        new Assert\Length(max: 255),
    ])]
    public ?array $variantCodes = null;
}
