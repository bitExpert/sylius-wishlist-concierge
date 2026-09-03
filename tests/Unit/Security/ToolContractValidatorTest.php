<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Security;

use BitExpert\SyliusWishlistConciergePlugin\Controller\Shop\CartTransferController;
use BitExpert\SyliusWishlistConciergePlugin\Controller\Shop\ProductSearchController;
use BitExpert\SyliusWishlistConciergePlugin\Controller\Shop\WishlistController;
use BitExpert\SyliusWishlistConciergePlugin\Security\ToolContractValidator;
use BitExpert\SyliusWishlistConciergePlugin\Service\ErrorResponseFactory;
use BitExpert\SyliusWishlistConciergePlugin\Service\ModelContextToolCollector;
use BitExpert\SyliusWishlistConciergePlugin\Service\ValidationErrorFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader;
use Symfony\Component\Validator\Validation;

final class ToolContractValidatorTest extends TestCase
{
    private ToolContractValidator $validator;

    protected function setUp(): void
    {
        $serializer = new Serializer([new ObjectNormalizer(), new ArrayDenormalizer()], [new JsonEncoder()]);
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $collector = new ModelContextToolCollector(
            [
                WishlistController::class,
                ProductSearchController::class,
                CartTransferController::class,
            ],
            new LazyLoadingMetadataFactory(new AttributeLoader()),
            $this->createRouter(),
            new NullLogger(),
        );
        $this->validator = new ToolContractValidator(
            $collector,
            $validator,
            $serializer,
            new ErrorResponseFactory(),
            new ValidationErrorFormatter(),
            new NullLogger(),
        );
    }

    #[Test]
    public function IgnoresNonConciergeRoutes(): void
    {
        $request = Request::create('/some/other/route', 'POST', [], [], [], [], '{}');
        $request->attributes->set('_route', 'some_other_route');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertFalse($request->attributes->has(ToolContractValidator::DTO_ATTRIBUTE));
    }

    #[Test]
    public function IgnoresGetRequests(): void
    {
        $request = Request::create('/_webmcp/wishlist_concierge/wishlist', 'GET');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_create');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function ValidWishlistCreatePassesThrough(): void
    {
        $request = Request::create('/en_US/_webmcp/wishlist_concierge/wishlist', 'POST', [], [], [], [], json_encode([
            'name' => 'Birthday Box',
            'theme' => 'birthday',
        ], \JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_create');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertTrue($request->attributes->has(ToolContractValidator::DTO_ATTRIBUTE));
    }

    #[Test]
    public function InvalidWishlistCreateReturns422(): void
    {
        $request = Request::create('/en_US/_webmcp/wishlist_concierge/wishlist', 'POST', [], [], [], [], json_encode([
            'name' => '',
            'theme' => 'bad theme!!',
        ], \JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_create');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame('Validation failed', $payload['error']);
        self::assertNotEmpty($payload['violations']);
        self::assertSame('name', $payload['violations'][0]['field']);
    }

    #[Test]
    public function InvalidAddItemQuantityReturns422(): void
    {
        $request = Request::create('/en_US/_webmcp/wishlist_concierge/wishlist/1/items', 'POST', [], [], [], [], json_encode([
            'variantCode' => 'T_SHIRT_VARIANT',
            'quantity' => 0,
        ], \JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_add_item');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    #[Test]
    public function OptimizeAcceptsLegacyBudgetAlias(): void
    {
        $request = Request::create('/en_US/_webmcp/wishlist_concierge/wishlist/1/optimize', 'POST', [], [], [], [], json_encode([
            'budget' => 15000,
        ], \JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_optimize');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);
        self::assertSame(15000, $dto->budgetCents);
    }

    #[Test]
    public function InvalidJsonReturns400(): void
    {
        $request = Request::create('/en_US/_webmcp/wishlist_concierge/wishlist', 'POST', [], [], [], [], '{not valid json');
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_create');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    #[Test]
    public function BulkAddDoesNotRequireWishlistIdInBody(): void
    {
        $request = Request::create(
            '/en_US/_webmcp/wishlist_concierge/wishlist/5/items/bulk',
            'POST',
            [],
            [],
            [],
            [],
            json_encode([
                'items' => [
                    ['variantCode' => 'T_SHIRT_VARIANT', 'quantity' => 1],
                ],
            ], \JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_bulk_add');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertTrue($request->attributes->has(ToolContractValidator::DTO_ATTRIBUTE));
    }

    #[Test]
    public function ClearDoesNotRequireWishlistIdInBody(): void
    {
        $request = Request::create(
            '/en_US/_webmcp/wishlist_concierge/wishlist/5/items/clear',
            'POST',
            [],
            [],
            [],
            [],
            '{}',
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_clear');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertTrue($request->attributes->has(ToolContractValidator::DTO_ATTRIBUTE));
    }

    #[Test]
    public function MoveToCartWithEmptyVariantCodes(): void
    {
        $request = Request::create(
            '/en_US/_webmcp/wishlist_concierge/wishlist/5/move-to-cart',
            'POST',
            [],
            [],
            [],
            [],
            json_encode([], \JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_move_to_cart');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertTrue($request->attributes->has(ToolContractValidator::DTO_ATTRIBUTE));
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);
        self::assertNull($dto->variantCodes);
    }

    #[Test]
    public function MoveToCartWithVariantCodesAsArray(): void
    {
        $request = Request::create(
            '/en_US/_webmcp/wishlist_concierge/wishlist/5/move-to-cart',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['variantCodes' => ['VARIANT_1', 'VARIANT_2']], \JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_move_to_cart');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);
        self::assertSame(['VARIANT_1', 'VARIANT_2'], $dto->variantCodes);
    }

    #[Test]
    public function MoveToCartWithSingleVariantCode(): void
    {
        $request = Request::create(
            '/en_US/_webmcp/wishlist_concierge/wishlist/5/move-to-cart',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['variantCodes' => ['VARIANT_1']], \JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_move_to_cart');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);
        self::assertSame(['VARIANT_1'], $dto->variantCodes);
    }

    #[Test]
    public function MoveToCartWithInvalidVariantCode(): void
    {
        $request = Request::create(
            '/en_US/_webmcp/wishlist_concierge/wishlist/5/move-to-cart',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['variantCodes' => ['INVALID CODE!']], \JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_move_to_cart');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    private function makeEvent(Request $request): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    /**
     * Build a router whose route collection mirrors config/routes/shop.yaml
     * (without the /{_locale} prefix that the host app adds at import time).
     */
    private function createRouter(): RouterInterface
    {
        $routes = [
            'bitexpert_concierge_product_search' => ['/_webmcp/wishlist_concierge/products/search', ['GET']],
            'bitexpert_concierge_product_get_details' => ['/_webmcp/wishlist_concierge/products/{productCode}', ['GET']],
            'bitexpert_concierge_wishlist_list' => ['/_webmcp/wishlist_concierge/wishlist', ['GET']],
            'bitexpert_concierge_wishlist_create' => ['/_webmcp/wishlist_concierge/wishlist', ['POST']],
            'bitexpert_concierge_wishlist_get' => ['/_webmcp/wishlist_concierge/wishlist/{id}', ['GET']],
            'bitexpert_concierge_wishlist_delete' => ['/_webmcp/wishlist_concierge/wishlist/{id}', ['DELETE']],
            'bitexpert_concierge_wishlist_add_item' => ['/_webmcp/wishlist_concierge/wishlist/{id}/items', ['POST']],
            'bitexpert_concierge_wishlist_bulk_add' => ['/_webmcp/wishlist_concierge/wishlist/{id}/items/bulk', ['POST']],
            'bitexpert_concierge_wishlist_clear' => ['/_webmcp/wishlist_concierge/wishlist/{id}/items/clear', ['POST']],
            'bitexpert_concierge_wishlist_remove_item' => ['/_webmcp/wishlist_concierge/wishlist/{id}/items/remove', ['POST']],
            'bitexpert_concierge_wishlist_optimize' => ['/_webmcp/wishlist_concierge/wishlist/{id}/optimize', ['POST']],
            'bitexpert_concierge_wishlist_move_to_cart' => ['/_webmcp/wishlist_concierge/wishlist/{id}/move-to-cart', ['POST']],
        ];

        $collection = new RouteCollection();
        foreach ($routes as $name => [$path, $methods]) {
            $collection->add($name, new Route($path, [], [], [], '', [], $methods));
        }

        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        return $router;
    }
}
