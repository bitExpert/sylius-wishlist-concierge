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

namespace BitExpert\SyliusWishlistConciergePlugin\Controller\Shop;

use BitExpert\SyliusWishlistConciergePlugin\Service\ModelContextToolCollector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ToolContractsController extends AbstractController
{
    public function __construct(
        private readonly ModelContextToolCollector $collector,
    ) {
    }

    public function manifest(): JsonResponse
    {
        return $this->manifestResponse(['tools' => $this->collector->collect()]);
    }

    /**
     * The manifest is plain data (scalars, arrays, stdClass for empty JSON objects).
     * We serialize with json_encode directly so that empty "properties" become an
     * object "{}" rather than an array "[]" (which the Symfony Serializer would do).
     *
     * @param array<string, mixed> $data
     * @throws \JsonException
     */
    private function manifestResponse(array $data): JsonResponse
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new JsonResponse($json, Response::HTTP_OK, [], true);
    }
}
