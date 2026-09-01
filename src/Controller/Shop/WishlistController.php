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

use BitExpert\SyliusWishlistConciergePlugin\Dto\BudgetOptimizeRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\MoveToCartRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistAddItemRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistBulkAddRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistClearRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistCreateRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistDeleteRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistRemoveItemRequest;
use BitExpert\SyliusWishlistConciergePlugin\Security\ToolContractValidator;
use BitExpert\SyliusWishlistConciergePlugin\Security\WishlistAccessChecker;
use BitExpert\SyliusWishlistConciergePlugin\Service\BudgetOptimizer;
use BitExpert\SyliusWishlistConciergePlugin\Service\WishlistManager;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\WishlistPlugin\Repository\WishlistRepositoryInterface;
use Sylius\WishlistPlugin\Resolver\WishlistCookieTokenResolverInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WishlistController extends AbstractController
{
    public function __construct(
        private readonly WishlistManager $wishlistManager,
        private readonly BudgetOptimizer $budgetOptimizer,
        private readonly WishlistRepositoryInterface $wishlistRepository,
        private readonly ChannelContextInterface $channelContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly WishlistAccessChecker $accessChecker,
        private readonly WishlistCookieTokenResolverInterface $cookieTokenResolver,
    ) {
    }

    #[Route('/concierge/wishlist', name: 'bitexpert_concierge_wishlist_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var WishlistCreateRequest $dto */
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);

        $wishlist = $this->wishlistManager->createThemed($dto->name, $dto->theme, null);
        $this->entityManager->persist($wishlist);
        $this->entityManager->flush();

        $response = $this->json([
            'wishlist' => $this->wishlistManager->toArray($wishlist),
        ], Response::HTTP_CREATED);

        // Anonymous wishlists are accessed via the cookie token, mirroring the
        // Sylius WishlistPlugin flow (shared cookie name = interchangeable).
        if (null === $wishlist->getShopUser()) {
            $response->headers->setCookie(new Cookie(
                'wishlist_cookie_token',
                $wishlist->getToken(),
                strtotime('+1 year'),
            ));
        }

        return $response;
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
        $user = $this->getUser();

        if ($user instanceof ShopUserInterface) {
            $wishlists = $this->wishlistRepository->findAllByShopUserAndChannel($user, $channel);
        } else {
            // Anonymous: only wishlists whose token matches the visitor's cookie.
            $cookieToken = $this->cookieTokenResolver->resolve();
            $wishlists = $this->wishlistRepository->findAllByAnonymousAndChannel($cookieToken, $channel);
        }

        $arr = array_map(fn($w) => $this->wishlistManager->toArray($w), $wishlists);

        return $this->json(['wishlists' => $arr, 'channelCode' => $channel->getCode()]);
    }

    #[Route('/concierge/wishlist/{id}', name: 'bitexpert_concierge_wishlist_delete', methods: ['DELETE'])]
    public function delete(Request $request, int $id): JsonResponse
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

        /** @var WishlistDeleteRequest $dto */
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);

        $this->wishlistManager->deleteWishlist($wishlist);
        $this->entityManager->flush();

        return $this->json(['deleted' => true, 'wishlistId' => $id]);
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

        /** @var WishlistAddItemRequest $dto */
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);

        try {
            $wishlist = $this->wishlistManager->addItem($wishlist, $dto->variantCode, $dto->quantity);
            $this->entityManager->persist($wishlist);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Variant not found'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['wishlist' => $this->wishlistManager->toArray($wishlist)]);
    }

    #[Route('/concierge/wishlist/{id}/items/bulk', name: 'bitexpert_concierge_wishlist_bulk_add', methods: ['POST'])]
    public function bulkAddItems(Request $request, int $id): JsonResponse
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

        /** @var WishlistBulkAddRequest $dto */
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);

        $results = $this->wishlistManager->bulkAddItemsFromRequest($wishlist, $dto);
        $this->entityManager->persist($wishlist);
        $this->entityManager->flush();

        return $this->json([
            'wishlistId' => $id,
            'results' => $results,
            'totalAdded' => count(array_filter($results, fn($r) => $r['status'] === 'added')),
            'totalSkipped' => count(array_filter($results, fn($r) => $r['status'] === 'skipped')),
        ]);
    }

    #[Route('/concierge/wishlist/{id}/items/clear', name: 'bitexpert_concierge_wishlist_clear', methods: ['POST'])]
    public function clearAllItems(Request $request, int $id): JsonResponse
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

        /** @var WishlistClearRequest $dto */
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);

        $this->wishlistManager->clearAllItems($wishlist);
        $this->entityManager->persist($wishlist);
        $this->entityManager->flush();

        return $this->json(['wishlist' => $this->wishlistManager->toArray($wishlist)]);
    }

    #[Route('/concierge/wishlist/{id}/items/{itemId}', name: 'bitexpert_concierge_wishlist_remove_item', methods: ['DELETE'])]
    public function removeItem(Request $request, int $id, int $itemId): JsonResponse
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

        try {
            $wishlist = $this->wishlistManager->removeItem($wishlist, $itemId);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['wishlist' => $this->wishlistManager->toArray($wishlist)]);
    }

    #[Route('/concierge/wishlist/{id}/items/remove', name: 'bitexpert_concierge_wishlist_remove_item_post', methods: ['POST'])]
    public function postRemoveItem(Request $request, int $id): JsonResponse
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

        /** @var WishlistRemoveItemRequest $dto */
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);

        try {
            $wishlist = $this->wishlistManager->removeItem($wishlist, $dto->itemId);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
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

        /** @var BudgetOptimizeRequest $dto */
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);

        $includePromotions = $dto->includePromotions;
        $result = $this->budgetOptimizer->optimize($wishlist, $dto->budgetCents, $includePromotions);

        return $this->json([
            'wishlistId' => $id,
            'budgetCents' => $dto->budgetCents,
            'budgetFormatted' => sprintf('$%.2f', $dto->budgetCents / 100),
            ...$result,
            'totalFormatted' => sprintf('$%.2f', $result['totalCents'] / 100),
            'savedFormatted' => sprintf('$%.2f', $result['savedCents'] / 100),
        ]);
    }
}
