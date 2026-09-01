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

use BitExpert\SyliusWishlistConciergePlugin\Attribute\WebMcpTool;
use BitExpert\SyliusWishlistConciergePlugin\Dto\ProductSearchRequest;
use BitExpert\SyliusWishlistConciergePlugin\Service\ThemedProductFinder;
use BitExpert\SyliusWishlistConciergePlugin\Service\ValidationErrorFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProductSearchController extends AbstractController
{
    public function __construct(
        private readonly ThemedProductFinder $themedProductFinder,
        private readonly ValidatorInterface $validator,
        private readonly ValidationErrorFormatter $errorFormatter,
    ) {
    }

    #[WebMcpTool(
        name: 'product.search',
        description: 'Search products by theme and optional taxon/price filters. The active channel is automatically inferred from the current Sylius context. Returns products with code, name, variantCode, price (cents), taxonCodes for curation. Matches products tagged with the concierge_tags attribute (e.g. "gift", "summer") or whose name contains the theme string.',
        dtoClass: ProductSearchRequest::class,
        readOnlyHint: true,
    )]
    #[Route('/_webmcp/wishlist_concierge/products/search', name: 'bitexpert_concierge_product_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $dto = ProductSearchRequest::fromRequest($request);

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->json(
                ['error' => 'Validation failed', 'violations' => $this->errorFormatter->format($violations)],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (null !== $dto->priceMin && null !== $dto->priceMax && $dto->priceMin > $dto->priceMax) {
            return $this->json(['error' => 'priceMin must be <= priceMax'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $results = $this->themedProductFinder->find(
                theme: $dto->theme,
                channelCode: null,
                priceMin: $dto->priceMin,
                priceMax: $dto->priceMax,
                taxonCodes: $dto->taxonCodes,
                limit: $dto->limit,
            );
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return $this->json(['error' => $e->getMessage()], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json([
            'channelCode' => $this->themedProductFinder->getDefaultChannelCode(),
            'theme' => $dto->theme,
            'count' => count($results),
            'products' => $results,
        ]);
    }

    #[WebMcpTool(
        name: 'product.get_details',
        description: 'Get product details by productCode, including variants and pricing.',
        readOnlyHint: true,
        queryParams: ['channelCode' => 'FASHION_WEB'],
    )]
    #[Route('/_webmcp/wishlist_concierge/products/{productCode}', name: 'bitexpert_concierge_product_get_details', methods: ['GET'])]
    public function getDetails(string $productCode, Request $request): JsonResponse
    {
        $channelCode = $request->query->get('channelCode');

        $product = $this->themedProductFinder->findByCode($productCode, $channelCode);

        if (null === $product) {
            return $this->json(['error' => 'Product not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json([
            'channelCode' => $channelCode ?? $this->themedProductFinder->getDefaultChannelCode(),
            'count' => 1,
            'products' => [$product],
        ]);
    }
}
