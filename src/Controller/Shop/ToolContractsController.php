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
use Symfony\Component\Routing\Attribute\Route;

final class ToolContractsController extends AbstractController
{
    public function __construct(
        private readonly ModelContextToolCollector $collector,
    ) {
    }

    #[Route('/_webmcp/wishlist_concierge/tools.json', name: 'bitexpert_concierge_tool_manifest', methods: ['GET'])]
    public function manifest(): JsonResponse
    {
        return $this->manifestResponse(['tools' => $this->collector->collect()]);
    }

    #[Route('/_webmcp/wishlist_concierge/contracts', name: 'bitexpert_concierge_tool_contracts', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->manifestResponse(['tools' => $this->collector->collect()]);
    }

    #[Route('/_webmcp/wishlist_concierge/contracts/{tool}', name: 'bitexpert_concierge_tool_contract', methods: ['GET'])]
    public function show(string $tool): JsonResponse
    {
        $tools = $this->collector->collect();

        foreach ($tools as $entry) {
            if ($entry['name'] === $tool) {
                return $this->manifestResponse($entry);
            }
        }

        return $this->json(['error' => 'Unknown tool'], Response::HTTP_NOT_FOUND);
    }

    /**
     * The manifest is plain data (scalars, arrays, stdClass for empty JSON objects).
     * We serialize with json_encode directly so that empty "properties" becomes an
     * object "{}" rather than an array "[]" (which the Symfony Serializer would do).
     *
     * @param array<string, mixed> $data
     */
    private function manifestResponse(array $data): JsonResponse
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new JsonResponse($json, Response::HTTP_OK, [], true);
    }
}
