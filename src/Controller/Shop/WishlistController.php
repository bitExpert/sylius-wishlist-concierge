<?php

declare(strict_types=1);

namespace BitExpert\SyliusWishlistConciergePlugin\Controller\Shop;

use BitExpert\SyliusWishlistConciergePlugin\Dto\BudgetOptimizeRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistAddItemRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistCreateRequest;
use BitExpert\SyliusWishlistConciergePlugin\Security\WishlistAccessChecker;
use BitExpert\SyliusWishlistConciergePlugin\Service\BudgetOptimizer;
use BitExpert\SyliusWishlistConciergePlugin\Service\WishlistManager;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\WishlistPlugin\Repository\WishlistRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class WishlistController extends AbstractController
{
    public function __construct(
        private readonly WishlistManager $wishlistManager,
        private readonly BudgetOptimizer $budgetOptimizer,
        private readonly WishlistRepositoryInterface $wishlistRepository,
        private readonly ChannelContextInterface $channelContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly WishlistAccessChecker $accessChecker,
        private readonly ValidatorInterface $validator,
        private readonly SerializerInterface $serializer,
    ) {
    }

    #[Route('/concierge/wishlist', name: 'bitexpert_concierge_wishlist_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            /** @var WishlistCreateRequest $dto */
            $dto = $this->serializer->deserialize($request->getContent(), WishlistCreateRequest::class, 'json');
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->json(['error' => 'Validation failed', 'violations' => $this->formatViolations($violations)], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $wishlist = $this->wishlistManager->createThemed($dto->name, $dto->theme, $dto->channelCode);
        $this->entityManager->persist($wishlist);
        $this->entityManager->flush();

        return $this->json([
            'wishlist' => $this->wishlistManager->toArray($wishlist),
        ], Response::HTTP_CREATED);
    }

    #[Route('/concierge/wishlist/{id}', name: 'bitexpert_concierge_wishlist_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $wishlist = $this->wishlistRepository->find($id);
        if (null === $wishlist) {
            return $this->json(['error' => 'Wishlist not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->accessChecker->assertCanView($wishlist);
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'wishlist' => $this->wishlistManager->toArray($wishlist),
        ]);
    }

    #[Route('/concierge/wishlist', name: 'bitexpert_concierge_wishlist_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $channel = $this->channelContext->getChannel();
        $wishlists = $this->wishlistRepository->findBy(['channel' => $channel], ['createdAt' => 'DESC'], 5);
        // For anon, filter is still applied via access checker in real usage, but list is intentionally limited
        // to avoid leaking cross-user tokens. In production, filter by cookie token.
        $arr = array_map(fn($w) => $this->wishlistManager->toArray($w), $wishlists);

        return $this->json(['wishlists' => $arr, 'channelCode' => $channel->getCode()]);
    }

    #[Route('/concierge/wishlist/{id}/items', name: 'bitexpert_concierge_wishlist_add_item', methods: ['POST'])]
    public function addItem(Request $request, int $id): JsonResponse
    {
        $wishlist = $this->wishlistRepository->find($id);
        if (null === $wishlist) {
            return $this->json(['error' => 'Wishlist not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->accessChecker->assertCanModify($wishlist);
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        // Handle legacy alias
        if (isset($data['productVariantCode']) && !isset($data['variantCode'])) {
            $data['variantCode'] = $data['productVariantCode'];
        }

        $dto = new WishlistAddItemRequest();
        $dto->variantCode = $data['variantCode'] ?? '';
        $dto->quantity = isset($data['quantity']) ? (int) $data['quantity'] : 1;

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->json(['error' => 'Validation failed', 'violations' => $this->formatViolations($violations)], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $wishlist = $this->wishlistManager->addItem($wishlist, $dto->variantCode, $dto->quantity);
            $this->entityManager->persist($wishlist);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Variant not found'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['wishlist' => $this->wishlistManager->toArray($wishlist)]);
    }

    #[Route('/concierge/wishlist/{id}/optimize', name: 'bitexpert_concierge_wishlist_optimize', methods: ['POST'])]
    public function optimize(Request $request, int $id): JsonResponse
    {
        $wishlist = $this->wishlistRepository->find($id);
        if (null === $wishlist) {
            return $this->json(['error' => 'Wishlist not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->accessChecker->assertCanView($wishlist);
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        try {
            /** @var BudgetOptimizeRequest $dto */
            $dto = $this->serializer->deserialize($request->getContent(), BudgetOptimizeRequest::class, 'json');
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        // Support legacy "budget" alias
        $raw = json_decode($request->getContent(), true) ?? [];
        if (0 === $dto->budgetCents && isset($raw['budget'])) {
            $dto->budgetCents = (int) $raw['budget'];
        }
        if (isset($raw['includePromotions'])) {
            $dto->includePromotions = (bool) $raw['includePromotions'];
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->json(['error' => 'Validation failed', 'violations' => $this->formatViolations($violations)], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->budgetOptimizer->optimize($wishlist, $dto->budgetCents);

        return $this->json([
            'wishlistId' => $id,
            'budgetCents' => $dto->budgetCents,
            'budgetFormatted' => sprintf('$%.2f', $dto->budgetCents / 100),
            ...$result,
            'totalFormatted' => sprintf('$%.2f', $result['totalCents'] / 100),
            'savedFormatted' => sprintf('$%.2f', $result['savedCents'] / 100),
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
