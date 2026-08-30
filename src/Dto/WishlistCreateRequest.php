<?php

declare(strict_types=1);

namespace BitExpert\SyliusWishlistConciergePlugin\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class WishlistCreateRequest
{
    #[Assert\NotBlank(message: 'Wishlist name must not be blank.')]
    #[Assert\Length(max: 100, maxMessage: 'Name must be at most {{ limit }} characters.')]
    public string $name = 'Gift Wishlist';

    #[Assert\NotBlank(message: 'Theme must not be blank.')]
    #[Assert\Length(max: 50)]
    #[Assert\Regex(pattern: '/^[\p{L}\p{N}\s\-_]+$/u', message: 'Theme contains invalid characters.')]
    public string $theme = 'gift';

    #[Assert\Length(max: 50)]
    #[Assert\Regex(pattern: '/^[A-Z0-9_]+$/', message: 'Channel code must be uppercase alphanumeric with underscores.')]
    public ?string $channelCode = 'FASHION_WEB';
}
