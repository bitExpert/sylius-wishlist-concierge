<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Service;

use BitExpert\SyliusWishlistConciergePlugin\Controller\Shop\CartTransferController;
use BitExpert\SyliusWishlistConciergePlugin\Controller\Shop\ProductSearchController;
use BitExpert\SyliusWishlistConciergePlugin\Controller\Shop\WishlistController;
use BitExpert\SyliusWishlistConciergePlugin\Service\ModelContextToolCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader;

final class ModelContextToolCollectorTest extends TestCase
{
    private ModelContextToolCollector $collector;

    protected function setUp(): void
    {
        $loader = new AttributeLoader();
        $this->collector = new ModelContextToolCollector(
            [
                WishlistController::class,
                ProductSearchController::class,
                CartTransferController::class,
            ],
            new LazyLoadingMetadataFactory($loader),
            $this->createRouter(),
            new NullLogger(),
        );
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

        $router = $this->createMock(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        return $router;
    }

    #[Test]
    public function testCollectsAllTwelveTools(): void
    {
        $tools = $this->collector->collect();

        $names = array_map(static fn (array $t): string => $t['name'], $tools);

        self::assertCount(12, $tools);
        self::assertContains('wishlist.list', $names);
        self::assertContains('wishlist.create', $names);
        self::assertContains('wishlist.get', $names);
        self::assertContains('wishlist.add_item', $names);
        self::assertContains('wishlist.bulk_add', $names);
        self::assertContains('wishlist.clear', $names);
        self::assertContains('wishlist.delete', $names);
        self::assertContains('wishlist.remove_item', $names);
        self::assertContains('wishlist.optimize_for_budget', $names);
        self::assertContains('wishlist.move_to_cart', $names);
        self::assertContains('product.search', $names);
        self::assertContains('product.get_details', $names);
    }

    #[Test]
    public function testManifestCarriesRouteAndAnnotations(): void
    {
        $tools = $this->collector->collect();
        $create = $this->byName($tools, 'wishlist.create');

        self::assertSame('/_webmcp/wishlist_concierge/wishlist', $create['route']['path']);
        self::assertSame('bitexpert_concierge_wishlist_create', $create['route']['name']);
        self::assertSame(['POST'], $create['route']['methods']);
        self::assertSame(['webmcp:wishlist-created'], $create['emitsEvents']);
        self::assertSame(['name', 'theme'], $create['inputSchema']['required']);
    }

    #[Test]
    public function testReadOnlyHintExposed(): void
    {
        $tools = $this->collector->collect();
        $list = $this->byName($tools, 'wishlist.list');

        self::assertTrue($list['annotations']['readOnlyHint']);
    }

    #[Test]
    public function testEmptyPropertiesSerializesAsObject(): void
    {
        $tools = $this->collector->collect();
        $list = $this->byName($tools, 'wishlist.list');

        self::assertArrayHasKey('properties', $list['inputSchema']);
        // Empty properties must be represented as a JSON object ({}) not an array ([]).
        self::assertInstanceOf(\stdClass::class, $list['inputSchema']['properties']);
    }

    #[Test]
    public function testPathParamsExposed(): void
    {
        $tools = $this->collector->collect();
        $get = $this->byName($tools, 'wishlist.get');

        self::assertSame(['wishlistId' => 'id'], $get['pathParams']);
        self::assertSame('/_webmcp/wishlist_concierge/wishlist/{id}', $get['route']['path']);
    }

    #[Test]
    public function testPathParamKeysMergedIntoSchema(): void
    {
        $tools = $this->collector->collect();

        $get = $this->byName($tools, 'wishlist.get');
        self::assertSame('integer', $get['inputSchema']['properties']['wishlistId']['type']);
        self::assertContains('wishlistId', $get['inputSchema']['required']);

        $addItem = $this->byName($tools, 'wishlist.add_item');
        self::assertSame('integer', $addItem['inputSchema']['properties']['wishlistId']['type']);
        self::assertContains('wishlistId', $addItem['inputSchema']['required']);
        self::assertArrayHasKey('variantCode', $addItem['inputSchema']['properties']);

        $removeItem = $this->byName($tools, 'wishlist.remove_item');
        self::assertSame('integer', $removeItem['inputSchema']['properties']['wishlistId']['type']);
        self::assertSame('integer', $removeItem['inputSchema']['properties']['itemId']['type']);
        self::assertContains('wishlistId', $removeItem['inputSchema']['required']);
        self::assertContains('itemId', $removeItem['inputSchema']['required']);

        $optimize = $this->byName($tools, 'wishlist.optimize_for_budget');
        self::assertSame('integer', $optimize['inputSchema']['properties']['wishlistId']['type']);

        $moveToCart = $this->byName($tools, 'wishlist.move_to_cart');
        self::assertSame('integer', $moveToCart['inputSchema']['properties']['wishlistId']['type']);
        self::assertSame('array', $moveToCart['inputSchema']['properties']['variantCodes']['type']);
        self::assertArrayNotHasKey('variantCodes', $moveToCart['inputSchema']['required']);

        $getDetails = $this->byName($tools, 'product.get_details');
        self::assertSame('string', $getDetails['inputSchema']['properties']['productCode']['type']);
        self::assertContains('productCode', $getDetails['inputSchema']['required']);
    }

    #[Test]
    public function testListKeepsEmptyProperties(): void
    {
        $tools = $this->collector->collect();
        $delete = $this->byName($tools, 'wishlist.list');

        self::assertInstanceOf(\stdClass::class, $delete['inputSchema']['properties']);
        self::assertArrayNotHasKey('required', $delete['inputSchema']);
    }

    #[Test]
    public function testConfirmMessageExposed(): void
    {
        $tools = $this->collector->collect();
        $delete = $this->byName($tools, 'wishlist.delete');

        self::assertStringContainsString('permanently delete', $delete['confirmMessage']);
        self::assertTrue($delete['annotations']['destructiveHint']);
    }

    #[Test]
    public function testRouteDtoMap(): void
    {
        $map = $this->collector->routeDtoMap();

        self::assertArrayHasKey('bitexpert_concierge_wishlist_create', $map);
        self::assertArrayHasKey('bitexpert_concierge_wishlist_move_to_cart', $map);
        // Read-only tools without a POST body DTO are excluded
        self::assertArrayNotHasKey('bitexpert_concierge_wishlist_get', $map);
    }

    /**
     * @param list<array{name: string}> $tools
     *
     * @return array<string, mixed>
     */
    private function byName(array $tools, string $name): array
    {
        foreach ($tools as $tool) {
            if ($tool['name'] === $name) {
                return $tool;
            }
        }

        self::fail(sprintf('Tool "%s" not found in manifest', $name));
    }
}
