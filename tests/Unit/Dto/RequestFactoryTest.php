<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Dto;

use BitExpert\SyliusWishlistConciergePlugin\Dto\MoveToCartRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistBulkAddRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistBulkItem;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistClearRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistCreateRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistDeleteRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistRemoveItemRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class RequestFactoryTest extends TestCase
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

    private function jsonRequest(string $content): Request
    {
        $request = Request::create('/_webmcp/wishlist_concierge', 'POST', [], [], [], [], $content);
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    #[Test]
    public function testWishlistCreateFromRequestReadsNameAndTheme(): void
    {
        $request = $this->jsonRequest(json_encode([
            'name' => 'Summer Party',
            'theme' => 'summer',
        ], \JSON_THROW_ON_ERROR));

        $dto = WishlistCreateRequest::fromRequest($request, $this->serializer);

        self::assertSame('Summer Party', $dto->name);
        self::assertSame('summer', $dto->theme);
    }

    #[Test]
    public function testWishlistCreateFromRequestEmptyBodyUsesDefaults(): void
    {
        $dto = WishlistCreateRequest::fromRequest($this->jsonRequest('{}'), $this->serializer);

        self::assertSame('Gift Wishlist', $dto->name);
        self::assertSame('gift', $dto->theme);
    }

    #[Test]
    public function testMoveToCartFromRequestReadsVariantCodes(): void
    {
        $request = $this->jsonRequest(json_encode([
            'variantCodes' => ['T_SHIRT_V', 'MUG_V'],
        ], \JSON_THROW_ON_ERROR));

        $dto = MoveToCartRequest::fromRequest($request, $this->serializer);

        self::assertSame(['T_SHIRT_V', 'MUG_V'], $dto->variantCodes);
    }

    #[Test]
    public function testMoveToCartFromRequestDefaultNull(): void
    {
        $dto = MoveToCartRequest::fromRequest($this->jsonRequest('{}'), $this->serializer);

        self::assertNull($dto->variantCodes);
    }

    #[Test]
    public function testWishlistDeleteFromRequestReadsWishlistId(): void
    {
        $request = $this->jsonRequest(json_encode([
            'wishlistId' => 42,
        ], \JSON_THROW_ON_ERROR));

        $dto = WishlistDeleteRequest::fromRequest($request, $this->serializer);

        self::assertSame(42, $dto->wishlistId);
    }

    #[Test]
    public function testWishlistClearFromRequestReadsWishlistId(): void
    {
        $request = $this->jsonRequest(json_encode([
            'wishlistId' => 7,
        ], \JSON_THROW_ON_ERROR));

        $dto = WishlistClearRequest::fromRequest($request, $this->serializer);

        self::assertSame(7, $dto->wishlistId);
    }

    #[Test]
    public function testWishlistRemoveItemFromRequestReadsItemIdAndToken(): void
    {
        $request = $this->jsonRequest(json_encode([
            'itemId' => 3,
            'wishlistToken' => 'tok-abc',
        ], \JSON_THROW_ON_ERROR));

        $dto = WishlistRemoveItemRequest::fromRequest($request, $this->serializer);

        self::assertSame(3, $dto->itemId);
        self::assertSame('tok-abc', $dto->wishlistToken);
    }

    #[Test]
    public function testWishlistRemoveItemFromRequestTokenOptional(): void
    {
        $request = $this->jsonRequest(json_encode([
            'itemId' => 3,
        ], \JSON_THROW_ON_ERROR));

        $dto = WishlistRemoveItemRequest::fromRequest($request, $this->serializer);

        self::assertSame(3, $dto->itemId);
        self::assertNull($dto->wishlistToken);
    }

    #[Test]
    public function testWishlistBulkItemDenormalizationWithinBulkRequest(): void
    {
        $request = $this->jsonRequest(json_encode([
            'items' => [
                ['variantCode' => 'A', 'quantity' => 2],
                ['variantCode' => 'B', 'quantity' => 1],
            ],
        ], \JSON_THROW_ON_ERROR));

        $dto = WishlistBulkAddRequest::fromRequest($request, $this->serializer);

        self::assertCount(2, $dto->items);
        self::assertSame('A', $dto->items[0]['variantCode']);
        self::assertSame(2, $dto->items[0]['quantity']);
        self::assertSame('B', $dto->items[1]['variantCode']);
        self::assertSame(1, $dto->items[1]['quantity']);
    }

    #[Test]
    public function testWishlistBulkItemIsPlainDataObject(): void
    {
        $item = new WishlistBulkItem();
        $item->variantCode = 'V1';
        $item->quantity = 5;

        self::assertSame('V1', $item->variantCode);
        self::assertSame(5, $item->quantity);
    }
}
