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

final class ProductSearchRequest
{
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/^[\p{L}\p{N}\s\-_]*$/u', message: 'Theme contains invalid characters.')]
    public ?string $theme = null;

    #[Assert\Length(max: 50)]
    #[Assert\Regex(pattern: '/^[A-Z0-9_]+$/', message: 'Invalid channel code.')]
    public string $channelCode = 'FASHION_WEB';

    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(value: 10000000)]
    public ?int $priceMin = null;

    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(value: 10000000)]
    public ?int $priceMax = null;

    /**
     * @var string[]|null
     */
    #[Assert\All(constraints: [
        new Assert\NotBlank(),
        new Assert\Regex(pattern: '/^[a-z0-9_]+$/'),
    ])]
    public ?array $taxonCodes = null;

    #[Assert\Positive]
    #[Assert\LessThanOrEqual(value: 50)]
    public int $limit = 12;
}
