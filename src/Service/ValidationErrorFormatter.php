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

namespace BitExpert\SyliusWishlistConciergePlugin\Service;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Converts a Symfony ConstraintViolationList into the standard error shape used
 * across the WebMCP tool API, so every validation failure — whether raised by
 * the ToolContractValidator middleware or by a controller — is formatted the
 * same way and the front-end can rely on a single contract.
 */
final class ValidationErrorFormatter
{
    /**
     * @return list<array{field: string, message: string}>
     */
    public function format(ConstraintViolationListInterface $violations): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = [
                'field' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return $errors;
    }
}
