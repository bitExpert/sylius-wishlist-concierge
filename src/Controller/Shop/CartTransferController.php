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
use BitExpert\SyliusWishlistConciergePlugin\Dto\MoveToCartRequest;
use BitExpert\SyliusWishlistConciergePlugin\Security\ToolContractValidator;
use BitExpert\SyliusWishlistConciergePlugin\Security\WishlistAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\WishlistPlugin\Repository\WishlistRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CartTransferController extends AbstractController
{
    public function __construct(
        private readonly WishlistRepositoryInterface $wishlistRepository,
        private readonly ChannelContextInterface $channelContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly FactoryInterface $orderFactory,
        private readonly FactoryInterface $orderItemFactory,
        private readonly OrderItemQuantityModifierInterface $quantityModifier,
        private readonly OrderProcessorInterface $orderProcessor,
        private readonly WishlistAccessChecker $accessChecker,
    ) {
    }

    #[WebMcpTool(
        name: 'wishlist.move_to_cart',
        description: 'Move wishlist items to cart (anon allowed). Optionally pass variantCodes to move subset, else all.',
        dtoClass: MoveToCartRequest::class,
        confirmMessage: 'Move items from this wishlist to cart?',
        emitsEvents: ['webmcp:cart-created'],
        pathParams: ['wishlistId' => 'id'],
    )]
    #[Route('/_webmcp/wishlist_concierge/wishlist/{id}/move-to-cart', name: 'bitexpert_concierge_wishlist_move_to_cart', methods: ['POST'])]
    public function moveToCart(Request $request, int $id, OrderRepositoryInterface $orderRepository): JsonResponse
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

        /** @var MoveToCartRequest $dto */
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);

        $variantCodes = $dto->variantCodes;
        $channel = $wishlist->getChannel() ?? $this->channelContext->getChannel();

        /** @var OrderInterface $cart */
        $cart = $this->orderFactory->createNew();
        $cart->setChannel($channel);
        $cart->setCurrencyCode($channel->getBaseCurrency()?->getCode() ?? 'USD');
        $cart->setLocaleCode($channel->getDefaultLocale()?->getCode() ?? 'en_US');
        $cart->setTokenValue(bin2hex(random_bytes(16)));

        $itemsToAdd = [];
        foreach ($wishlist->getWishlistProducts() as $wp) {
            $code = $wp->getVariant()?->getCode();
            if (null === $variantCodes || in_array($code, $variantCodes, true)) {
                $itemsToAdd[] = $wp;
            }
        }

        if ([] === $itemsToAdd) {
            return $this->json(['error' => 'No items to move'], Response::HTTP_BAD_REQUEST);
        }

        foreach ($itemsToAdd as $wp) {
            $variant = $wp->getVariant();
            if (null === $variant) {
                continue;
            }
            $quantity = $wp->getQuantity();
            $orderItem = $this->orderItemFactory->createNew();
            $orderItem->setVariant($variant);
            $this->quantityModifier->modify($orderItem, $quantity);
            $cart->addItem($orderItem);
        }

        $this->orderProcessor->process($cart);
        $orderRepository->add($cart);

        return $this->json([
            'cartToken' => $cart->getTokenValue(),
            'channelCode' => $channel->getCode(),
            'items' => array_map(fn($i) => [
                'variantCode' => $i->getVariant()?->getCode(),
                'quantity' => $i->getQuantity(),
                'unitPrice' => $i->getUnitPrice(),
                'total' => $i->getTotal(),
            ], $cart->getItems()->toArray()),
            'total' => $cart->getTotal(),
            'totalFormatted' => sprintf('$%.2f', $cart->getTotal() / 100),
            'cartUrl' => sprintf('/%s/cart', $channel->getDefaultLocale()?->getCode() ?? 'en_US'),
        ], Response::HTTP_CREATED);
    }
}
