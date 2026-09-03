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

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Centralizes the JSON error envelope used across the WebMCP tool API so every
 * failure — validation, not-found, invalid body — is shaped consistently
 * ({error, message, violations?}) and the front-end can rely on one contract.
 */
final class ErrorResponseFactory
{
    public function invalidJson(): JsonResponse
    {
        return $this->error(
            'Invalid JSON payload',
            'The request body is not valid JSON.',
            Response::HTTP_BAD_REQUEST,
        );
    }

    public function validationFailed(ConstraintViolationListInterface $violations, ValidationErrorFormatter $formatter): JsonResponse
    {
        return $this->error(
            'Validation failed',
            'The tool input does not satisfy its declared contract.',
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            ['violations' => $formatter->format($violations)],
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function error(string $error, string $message, int $status = Response::HTTP_BAD_REQUEST, array $extra = []): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => $error,
                'message' => $message,
                ...$extra,
            ],
            $status,
            ['Content-Type' => 'application/json'],
        );
    }

    public function notFound(string $message): JsonResponse
    {
        return $this->error('Not found', $message, Response::HTTP_NOT_FOUND);
    }

    public function forbidden(string $message): JsonResponse
    {
        return $this->error('Forbidden', $message, Response::HTTP_FORBIDDEN);
    }
}
