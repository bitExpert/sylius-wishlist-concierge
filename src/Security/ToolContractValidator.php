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

namespace BitExpert\SyliusWishlistConciergePlugin\Security;

use BitExpert\SyliusWishlistConciergePlugin\Dto\BudgetOptimizeRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\MoveToCartRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistAddItemRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistCreateRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistRemoveItemRequest;
use BitExpert\SyliusWishlistConciergePlugin\Service\ErrorResponseFactory;
use BitExpert\SyliusWishlistConciergePlugin\Service\ValidationErrorFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Centralized server-side validation of WebMCP tool contracts.
 *
 * Runs on every kernel.request and, for the imperative WebMCP tool endpoints
 * that accept a JSON payload, deserializes the incoming body into the tool's
 * declared input DTO and validates it against the documented constraints.
 * A failed contract yields a single, deterministic 422 response carrying the
 * exact validation errors, so the "structured contract" promised by WebMCP is
 * honored regardless of which client (browser, script, test) invokes it.
 */
final class ToolContractValidator
{
    /**
     * Route name => input DTO used to validate the tool payload.
     *
     * @var array<string, class-string>
     */
    private const TOOL_CONTRACTS = [
        'bitexpert_concierge_wishlist_create' => WishlistCreateRequest::class,
        'bitexpert_concierge_wishlist_add_item' => WishlistAddItemRequest::class,
        'bitexpert_concierge_wishlist_remove_item_post' => WishlistRemoveItemRequest::class,
        'bitexpert_concierge_wishlist_optimize' => BudgetOptimizeRequest::class,
        'bitexpert_concierge_wishlist_move_to_cart' => MoveToCartRequest::class,
    ];

    /** Request attribute under which the validated DTO is exposed to controllers. */
    public const DTO_ATTRIBUTE = '_webmcp_validated_dto';

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly SerializerInterface $serializer,
        private readonly ErrorResponseFactory $errorResponseFactory,
        private readonly ValidationErrorFormatter $errorFormatter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->supports($request)) {
            return;
        }

        $routeName = (string) $request->attributes->get('_route');
        $dtoClass = self::TOOL_CONTRACTS[$routeName];

        try {
            $dto = $this->serializer->deserialize(
                $request->getContent() ?: '{}',
                $dtoClass,
                'json',
            );
        } catch (\Exception $e) {
            $this->logger->info('WebMCP tool rejected: invalid JSON payload', [
                'route' => $routeName,
                'exception' => $e->getMessage(),
            ]);
            $event->setResponse($this->errorResponseFactory->invalidJson());
            return;
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            $this->logger->info('WebMCP tool rejected: contract violations', [
                'route' => $routeName,
                'violations' => $this->errorFormatter->format($violations),
            ]);
            $event->setResponse($this->errorResponseFactory->validationFailed($violations, $this->errorFormatter));
            return;
        }

        $request->attributes->set(self::DTO_ATTRIBUTE, $dto);
    }

    private function supports(Request $request): bool
    {
        $routeName = (string) $request->attributes->get('_route');

        if ('' === $routeName || !isset(self::TOOL_CONTRACTS[$routeName])) {
            return false;
        }

        return in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true);
    }
}
