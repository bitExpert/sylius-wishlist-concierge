<?php

declare(strict_types=1);

namespace BitExpert\SyliusWishlistConciergePlugin\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class WishlistAddItemRequest
{
    #[Assert\NotBlank(message: 'variantCode must not be blank.')]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9._\-]+$/', message: 'Invalid variant code format.')]
    public string $variantCode = '';

    #[Assert\NotNull]
    #[Assert\Positive(message: 'Quantity must be positive.')]
    #[Assert\LessThanOrEqual(value: 99, message: 'Quantity must be at most 99.')]
    public int $quantity = 1;

    // Accepts legacy alias from API docs
    public function setProductVariantCode(?string $code): void
    {
        if (null !== $code && '' === $this->variantCode) {
            $this->variantCode = $code;
        }
    }
}
