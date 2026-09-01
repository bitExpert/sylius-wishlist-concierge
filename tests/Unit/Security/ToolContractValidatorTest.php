<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Security;

use BitExpert\SyliusWishlistConciergePlugin\Controller\Shop\CartTransferController;
use BitExpert\SyliusWishlistConciergePlugin\Controller\Shop\ProductSearchController;
use BitExpert\SyliusWishlistConciergePlugin\Controller\Shop\WishlistController;
use BitExpert\SyliusWishlistConciergePlugin\Security\ToolContractValidator;
use BitExpert\SyliusWishlistConciergePlugin\Service\ErrorResponseFactory;
use BitExpert\SyliusWishlistConciergePlugin\Service\ValidationErrorFormatter;
use BitExpert\SyliusWishlistConciergePlugin\Service\ModelContextToolCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
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

    public function testIgnoresNonConciergeRoutes(): void
    {
        $request = Request::create('/some/other/route', 'POST', [], [], [], [], '{}');
        $request->attributes->set('_route', 'some_other_route');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertFalse($request->attributes->has(ToolContractValidator::DTO_ATTRIBUTE));
    }

    public function testIgnoresGetRequests(): void
    {
        $request = Request::create('/_webmcp/wishlist_concierge/wishlist', 'GET');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_create');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testValidWishlistCreatePassesThrough(): void
    {
        $request = Request::create('/en_US/_webmcp/wishlist_concierge/wishlist', 'POST', [], [], [], [], json_encode([
            'name' => 'Birthday Box',
            'theme' => 'birthday',
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_create');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertTrue($request->attributes->has(ToolContractValidator::DTO_ATTRIBUTE));
    }

    public function testInvalidWishlistCreateReturns422(): void
    {
        $request = Request::create('/en_US/_webmcp/wishlist_concierge/wishlist', 'POST', [], [], [], [], json_encode([
            'name' => '',
            'theme' => 'bad theme!!',
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_create');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $payload = json_decode($response->getContent(), true);
        self::assertSame('Validation failed', $payload['error']);
        self::assertNotEmpty($payload['violations']);
        self::assertSame('name', $payload['violations'][0]['field']);
    }

    public function testInvalidAddItemQuantityReturns422(): void
    {
        $request = Request::create('/en_US/_webmcp/wishlist_concierge/wishlist/1/items', 'POST', [], [], [], [], json_encode([
            'variantCode' => 'T_SHIRT_VARIANT',
            'quantity' => 0,
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_add_item');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testOptimizeAcceptsLegacyBudgetAlias(): void
    {
        $request = Request::create('/en_US/_webmcp/wishlist_concierge/wishlist/1/optimize', 'POST', [], [], [], [], json_encode([
            'budget' => 15000,
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_optimize');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
        $dto = $request->attributes->get(ToolContractValidator::DTO_ATTRIBUTE);
        self::assertSame(15000, $dto->budgetCents);
    }

    public function testInvalidJsonReturns400(): void
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

    public function testBulkAddDoesNotRequireWishlistIdInBody(): void
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
            ], JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route', 'bitexpert_concierge_wishlist_bulk_add');

        $event = $this->makeEvent($request);

        $this->validator->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertTrue($request->attributes->has(ToolContractValidator::DTO_ATTRIBUTE));
    }

    public function testClearDoesNotRequireWishlistIdInBody(): void
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

    private function makeEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
