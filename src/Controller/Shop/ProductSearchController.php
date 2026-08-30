<?php

declare(strict_types=1);

namespace BitExpert\SyliusWishlistConciergePlugin\Controller\Shop;

use BitExpert\SyliusWishlistConciergePlugin\Dto\ProductSearchRequest;
use BitExpert\SyliusWishlistConciergePlugin\Service\ThemedProductFinder;
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
    ) {
    }

    #[Route('/concierge/products/search', name: 'bitexpert_concierge_product_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $dto = new ProductSearchRequest();
        $dto->theme = $request->query->get('theme');
        $dto->channelCode = $request->query->get('channelCode', 'FASHION_WEB');
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

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->json(['error' => 'Validation failed', 'violations' => $this->formatViolations($violations)], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (null !== $dto->priceMin && null !== $dto->priceMax && $dto->priceMin > $dto->priceMax) {
            return $this->json(['error' => 'priceMin must be <= priceMax'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
                $results = $this->themedProductFinder->find(
                theme: $dto->theme,
                channelCode: $dto->channelCode,
                priceMin: $dto->priceMin,
                priceMax: $dto->priceMax,
                taxonCodes: $dto->taxonCodes,
                limit: $dto->limit,
            );
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return $this->json(['error' => $e->getMessage()], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json([
            'channelCode' => $dto->channelCode,
            'theme' => $dto->theme,
            'count' => count($results),
            'products' => $results,
        ]);
    }

    private function formatViolations($violations): array
    {
        $errors = [];
        foreach ($violations as $v) {
            $errors[] = ['property' => $v->getPropertyPath(), 'message' => $v->getMessage()];
        }
        return $errors;
    }
}
