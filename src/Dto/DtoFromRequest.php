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
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Builds a request-backed DTO from the raw JSON body using the injected
 * Symfony Serializer, so the DTO owns its own request hydration without
 * constructing a serializer itself.
 */
trait DtoFromRequest
{
    public static function fromRequest(Request $request, SerializerInterface $serializer): static
    {
        /** @var static */
        return $serializer->deserialize($request->getContent() ?: '{}', static::class, 'json');
    }
}
