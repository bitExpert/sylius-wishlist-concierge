<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Dto;

use BitExpert\SyliusWishlistConciergePlugin\Dto\BudgetOptimizeRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\ProductSearchRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistAddItemRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistBulkAddRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class DtoFromRequestTest extends TestCase
{
    private Serializer $serializer;

    protected function setUp(): void
    {
        $extractor = new PropertyInfoExtractor(
            [new ReflectionExtractor()],
            [new ReflectionExtractor()],
        );
        $objectNormalizer = new ObjectNormalizer(null, null, null, $extractor);

        $this->serializer = new Serializer([$objectNormalizer, new ArrayDenormalizer()], [new JsonEncoder()]);
    }

    #[Test]
    public function testProductSearchFromRequestReadsQueryParams(): void
    {
        $request = Request::create('/_webmcp/wishlist_concierge/products/search', 'GET', [
            'theme' => 'summer',
            'limit' => '25',
            'priceMin' => '1000',
            'priceMax' => '5000',
            'taxonCodes' => ['shirts', 'shoes'],
        ]);

        $dto = ProductSearchRequest::fromRequest($request);

        self::assertSame('summer', $dto->theme);
        self::assertSame(25, $dto->limit);
        self::assertSame(1000, $dto->priceMin);
        self::assertSame(5000, $dto->priceMax);
        self::assertSame(['shirts', 'shoes'], $dto->taxonCodes);
    }

    #[Test]
    public function testProductSearchFromRequestDefaultsAndNonNullCasting(): void
    {
        $request = Request::create('/search', 'GET', ['theme' => 'gift']);

        $dto = ProductSearchRequest::fromRequest($request);

        self::assertSame('gift', $dto->theme);
        self::assertSame(12, $dto->limit);
        self::assertNull($dto->priceMin);
        self::assertNull($dto->priceMax);
        self::assertNull($dto->taxonCodes);
    }

    #[Test]
    public function testAddItemFromRequestPopulatesFieldsAndLegacyAlias(): void
    {
        $request = Request::create('/add', 'POST', [], [], [], [], json_encode([
            'productVariantCode' => 'T_SHIRT_VARIANT',
            'quantity' => 3,
        ], \JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        $dto = WishlistAddItemRequest::fromRequest($request, $this->serializer);

        self::assertSame('T_SHIRT_VARIANT', $dto->variantCode);
        self::assertSame(3, $dto->quantity);
    }

    #[Test]
    public function testBulkAddFromRequestDenormalizesNestedItems(): void
    {
        $request = Request::create('/bulk', 'POST', [], [], [], [], json_encode([
            'items' => [
                ['variantCode' => 'A', 'quantity' => 1],
                ['variantCode' => 'B', 'quantity' => 2],
            ],
        ], \JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        $dto = WishlistBulkAddRequest::fromRequest($request, $this->serializer);

        self::assertCount(2, $dto->items);
        self::assertSame('A', $dto->items[0]['variantCode']);
        self::assertSame(2, $dto->items[1]['quantity']);
    }

    #[Test]
    public function testBudgetOptimizeFromRequestAcceptsLegacyBudgetAlias(): void
    {
        $request = Request::create('/optimize', 'POST', [], [], [], [], json_encode([
            'budget' => 15000,
        ], \JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        $dto = BudgetOptimizeRequest::fromRequest($request, $this->serializer);

        self::assertSame(15000, $dto->budgetCents);
        self::assertTrue($dto->includePromotions);
    }
}
