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

use BitExpert\SyliusWishlistConciergePlugin\Attribute\ModelContextTool;
use BitExpert\SyliusWishlistConciergePlugin\Dto\BudgetOptimizeRequest;
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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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

    #[ModelContextTool(
        name: 'wishlist.create',
        description: 'Create a new themed wishlist. The active channel is automatically inferred from the current Sylius context. Theme examples: birthday, gift, summer, casual, formal. Name should be human readable like "Birthday Wishlist — $150".',
        dtoClass: WishlistCreateRequest::class,
        emitsEvents: ['webmcp:wishlist-created'],
    )]
    #[Route('/_webmcp/wishlist_concierge/wishlist', name: 'bitexpert_concierge_wishlist_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var WishlistCreateRequest $dto */
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);

        $existingToken = $this->cookieTokenResolver->resolve();
        $wishlist = $this->wishlistManager->createThemed($dto->name, $dto->theme, null);
        if ($existingToken !== '' && null === $wishlist->getShopUser()) {
            $wishlist->setToken($existingToken);
        }

        $this->entityManager->persist($wishlist);
        $this->entityManager->flush();

        $response = $this->json([
            'wishlist' => $this->wishlistManager->toArray($wishlist),
        ], Response::HTTP_CREATED);

        // Only set the cookie if there wasn't an existing token (first anonymous wishlist).
        // Anonymous wishlists are accessed via the cookie token, mirroring the
        // Sylius WishlistPlugin flow (shared cookie name = interchangeable).
        if (null === $wishlist->getShopUser() && $existingToken === '') {
            $response->headers->setCookie(new Cookie(
                'wishlist_cookie_token',
                $wishlist->getToken(),
                strtotime('+1 year'),
            ));
        }

        return $response;
    }

    #[ModelContextTool(
        name: 'wishlist.get',
        description: 'Get details of a single wishlist by id, including items with variantCode, productName, price and quantities.',
        readOnlyHint: true,
        pathParams: ['wishlistId' => 'id'],
    )]
    #[Route('/_webmcp/wishlist_concierge/wishlist/{id}', name: 'bitexpert_concierge_wishlist_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $wishlist = $this->wishlistRepository->find($id);
        if (null === $wishlist) {
            return $this->json(['error' => 'Wishlist not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->accessChecker->assertCanView($wishlist);
        } catch (AccessDeniedHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'wishlist' => $this->wishlistManager->toArray($wishlist),
        ]);
    }

    #[ModelContextTool(
        name: 'wishlist.list',
        description: 'List recent wishlists for the current channel. The active channel is automatically inferred from the current Sylius context. Use to discover existing wishlists before creating a new themed one.',
        readOnlyHint: true,
    )]
    #[Route('/_webmcp/wishlist_concierge/wishlist', name: 'bitexpert_concierge_wishlist_list', methods: ['GET'])]
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

    #[ModelContextTool(
        name: 'wishlist.delete',
        description: 'Delete a wishlist permanently.',
        dtoClass: WishlistDeleteRequest::class,
        destructiveHint: true,
        confirmMessage: 'Are you sure you want to permanently delete this wishlist? This cannot be undone.',
        emitsEvents: ['webmcp:wishlist-deleted'],
        pathParams: ['wishlistId' => 'id'],
    )]
    #[Route('/_webmcp/wishlist_concierge/wishlist/{id}', name: 'bitexpert_concierge_wishlist_delete', methods: ['DELETE'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        $wishlist = $this->wishlistRepository->find($id);
        if (null === $wishlist) {
            return $this->json(['error' => 'Wishlist not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->accessChecker->assertCanModify($wishlist);
        } catch (AccessDeniedHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        /** @var WishlistDeleteRequest $dto */
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);

        $this->wishlistManager->deleteWishlist($wishlist);
        $this->entityManager->flush();

        return $this->json(['deleted' => true, 'wishlistId' => $id]);
    }

    #[ModelContextTool(
        name: 'wishlist.add_item',
        description: 'Add a product variant to a wishlist by variantCode and quantity.',
        dtoClass: WishlistAddItemRequest::class,
        emitsEvents: ['webmcp:wishlist-updated'],
        pathParams: ['wishlistId' => 'id'],
    )]
    #[Route('/_webmcp/wishlist_concierge/wishlist/{id}/items', name: 'bitexpert_concierge_wishlist_add_item', methods: ['POST'])]
    public function addItem(Request $request, int $id): JsonResponse
    {
        $wishlist = $this->wishlistRepository->find($id);
        if (null === $wishlist) {
            return $this->json(['error' => 'Wishlist not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->accessChecker->assertCanModify($wishlist);
        } catch (AccessDeniedHttpException $e) {
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

    #[ModelContextTool(
        name: 'wishlist.bulk_add',
        description: 'Add multiple product variants to a wishlist in one call. Input is an array of {variantCode, quantity} objects.',
        dtoClass: WishlistBulkAddRequest::class,
        emitsEvents: ['webmcp:wishlist-updated'],
        pathParams: ['wishlistId' => 'id'],
    )]
    #[Route('/_webmcp/wishlist_concierge/wishlist/{id}/items/bulk', name: 'bitexpert_concierge_wishlist_bulk_add', methods: ['POST'])]
    public function bulkAddItems(Request $request, int $id): JsonResponse
    {
        $wishlist = $this->wishlistRepository->find($id);
        if (null === $wishlist) {
            return $this->json(['error' => 'Wishlist not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->accessChecker->assertCanModify($wishlist);
        } catch (AccessDeniedHttpException $e) {
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

    #[ModelContextTool(
        name: 'wishlist.clear',
        description: 'Remove all items from a wishlist in one call. Useful for resetting a themed list before re-curating.',
        dtoClass: WishlistClearRequest::class,
        destructiveHint: true,
        emitsEvents: ['webmcp:wishlist-updated'],
        pathParams: ['wishlistId' => 'id'],
    )]
    #[Route('/_webmcp/wishlist_concierge/wishlist/{id}/items/clear', name: 'bitexpert_concierge_wishlist_clear', methods: ['POST'])]
    public function clearAllItems(Request $request, int $id): JsonResponse
    {
        $wishlist = $this->wishlistRepository->find($id);
        if (null === $wishlist) {
            return $this->json(['error' => 'Wishlist not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->accessChecker->assertCanModify($wishlist);
        } catch (AccessDeniedHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        /** @var WishlistClearRequest $dto */
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);

        $this->wishlistManager->clearAllItems($wishlist);
        $this->entityManager->persist($wishlist);
        $this->entityManager->flush();

        return $this->json(['wishlist' => $this->wishlistManager->toArray($wishlist)]);
    }

    #[ModelContextTool(
        name: 'wishlist.remove_item',
        description: 'Remove an item from a wishlist by itemId.',
        destructiveHint: true,
        emitsEvents: ['webmcp:wishlist-updated'],
        pathParams: ['wishlistId' => 'id', 'itemId' => 'itemId'],
    )]
    #[Route('/_webmcp/wishlist_concierge/wishlist/{id}/items/{itemId}', name: 'bitexpert_concierge_wishlist_remove_item', methods: ['DELETE'])]
    public function removeItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $wishlist = $this->wishlistRepository->find($id);
        if (null === $wishlist) {
            return $this->json(['error' => 'Wishlist not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->accessChecker->assertCanModify($wishlist);
        } catch (AccessDeniedHttpException $e) {
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

    #[Route('/_webmcp/wishlist_concierge/wishlist/{id}/items/remove', name: 'bitexpert_concierge_wishlist_remove_item_post', methods: ['POST'])]
    public function postRemoveItem(Request $request, int $id): JsonResponse
    {
        $wishlist = $this->wishlistRepository->find($id);
        if (null === $wishlist) {
            return $this->json(['error' => 'Wishlist not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->accessChecker->assertCanModify($wishlist);
        } catch (AccessDeniedHttpException $e) {
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

    #[ModelContextTool(
        name: 'wishlist.optimize_for_budget',
        description: 'Optimize a wishlist for a budget (cents, USD). Applies eligible Sylius catalog promotions when includePromotions is true: returns chosen variantCodes, totalCents/savedCents, the list of active promotionsApplied and a human explanation. Use before move_to_cart to stay under budget.',
        dtoClass: BudgetOptimizeRequest::class,
        readOnlyHint: true,
        emitsEvents: ['webmcp:promotions-applied'],
        pathParams: ['wishlistId' => 'id'],
    )]
    #[Route('/_webmcp/wishlist_concierge/wishlist/{id}/optimize', name: 'bitexpert_concierge_wishlist_optimize', methods: ['POST'])]
    public function optimize(Request $request, int $id): JsonResponse
    {
        $wishlist = $this->wishlistRepository->find($id);
        if (null === $wishlist) {
            return $this->json(['error' => 'Wishlist not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->accessChecker->assertCanView($wishlist);
        } catch (AccessDeniedHttpException $e) {
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
