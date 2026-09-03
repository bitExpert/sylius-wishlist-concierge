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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

final class ProductSearchRequest
{
    public static function fromRequest(Request $request): static
    {
        $dto = new self();
        $dto->theme = $request->query->get('theme');
        $dto->limit = (int) $request->query->get('limit', 12);

        $priceMin = $request->query->get('priceMin');
        $priceMax = $request->query->get('priceMax');
        $dto->priceMin = null !== $priceMin ? (int) $priceMin : null;
        $dto->priceMax = null !== $priceMax ? (int) $priceMax : null;

        $taxonCodes = $request->query->all('taxonCodes');
        if ([] === $taxonCodes && $request->query->has('taxonCodes')) {
            $raw = $request->query->get('taxonCodes');
            $taxonCodes = is_string($raw) ? [$raw] : (array) $raw;
        }
        $dto->taxonCodes = [] === $taxonCodes ? null : $taxonCodes;

        return $dto;
    }

    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/^[\p{L}\p{N}\s\-_]*$/u', message: 'Theme contains invalid characters.')]
    public ?string $theme = null;

    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(value: 10000000)]
    public ?int $priceMin = null;

    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(value: 10000000)]
    public ?int $priceMax = null;

    /** @var string[]|null */
    #[Assert\All(
        constraints: [
        new Assert\NotBlank(),
        new Assert\Regex(pattern: '/^[a-z0-9_]+$/'),
        ],
    )]
    public ?array $taxonCodes = null;

    #[Assert\Positive]
    #[Assert\LessThanOrEqual(value: 50)]
    public int $limit = 12;
}
