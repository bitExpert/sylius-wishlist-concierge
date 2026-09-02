<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Service;

use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistBulkAddRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistBulkItem;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistClearRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistDeleteRequest;
use BitExpert\SyliusWishlistConciergePlugin\Service\WishlistManager;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\WishlistPlugin\Entity\Wishlist;
use Sylius\WishlistPlugin\Entity\WishlistInterface;
use Sylius\WishlistPlugin\Entity\WishlistProduct;
use Sylius\WishlistPlugin\Entity\WishlistProductInterface;
use Sylius\WishlistPlugin\Factory\WishlistFactoryInterface;
use Sylius\WishlistPlugin\Factory\WishlistProductFactoryInterface;
use Sylius\WishlistPlugin\Repository\WishlistRepositoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * @covers \BitExpert\SyliusWishlistConciergePlugin\Service\WishlistManager
 */
final class WishlistManagerTest extends TestCase
{
    private function createManager(
        ?WishlistFactoryInterface $wishlistFactory = null,
        ?WishlistRepositoryInterface $wishlistRepository = null,
        ?WishlistProductFactoryInterface $wishlistProductFactory = null,
        ?ProductVariantRepositoryInterface $variantRepository = null,
        ?ChannelContextInterface $channelContext = null,
        ?ChannelRepositoryInterface $channelRepository = null,
        ?TokenStorageInterface $tokenStorage = null,
        ?EntityManagerInterface $entityManager = null,
    ): WishlistManager {
        return new WishlistManager(
            $wishlistFactory ?? $this->createMock(WishlistFactoryInterface::class),
            $wishlistRepository ?? $this->createMock(WishlistRepositoryInterface::class),
            $wishlistProductFactory ?? $this->createMock(WishlistProductFactoryInterface::class),
            $variantRepository ?? $this->createMock(ProductVariantRepositoryInterface::class),
            $channelContext ?? $this->createMock(ChannelContextInterface::class),
            $channelRepository ?? $this->createMock(ChannelRepositoryInterface::class),
            $tokenStorage ?? $this->createMock(TokenStorageInterface::class),
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
        );
    }

    private function createVariant(string $code, ?ProductInterface $product = null): ProductVariantInterface
    {
        $variant = new ProductVariant();
        $variant->setCode($code);
        if (null !== $product) {
            $this->setProduct($variant, $product);
        }

        return $variant;
    }

    private function createProduct(string $code): ProductInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getCode')->willReturn($code);

        return $product;
    }

    private function setProduct(ProductVariantInterface $variant, ProductInterface $product): void
    {
        $ref = new \ReflectionProperty(ProductVariant::class, 'product');
        $ref->setAccessible(true);
        $ref->setValue($variant, $product);
    }

    private function setId(object $object, int $id): void
    {
        $ref = new \ReflectionProperty($object, 'id');
        $ref->setAccessible(true);
        $ref->setValue($object, $id);
    }

    private function createWishlistProduct(string $code, int $quantity, ?int $id = null): WishlistProduct
    {
        $product = $this->createProduct($code . '_PRODUCT');
        $variant = $this->createVariant($code, $product);

        $wp = new WishlistProduct();
        $wp->setProduct($product);
        $wp->setVariant($variant);
        $wp->setQuantity($quantity);
        if (null !== $id) {
            $this->setId($wp, $id);
        }

        return $wp;
    }

    private function channelContextWith(ChannelInterface $channel): ChannelContextInterface
    {
        $context = $this->createMock(ChannelContextInterface::class);
        $context->method('getChannel')->willReturn($channel);

        return $context;
    }

    private function createChannel(string $code): ChannelInterface
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($code);

        return $channel;
    }

    private function tokenWith(?ShopUserInterface $user): TokenStorageInterface
    {
        if (null === $user) {
            return $this->createMock(TokenStorageInterface::class);
        }

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);

        return $storage;
    }

    // ------------------------------------------------------------------
    // findOrCreate
    // ------------------------------------------------------------------

    public function testFindOrCreateReturnsExistingByToken(): void
    {
        $existing = new Wishlist();
        $repository = $this->createMock(WishlistRepositoryInterface::class);
        $repository->method('findByToken')->with('tok-123')->willReturn($existing);

        $manager = $this->createManager(wishlistRepository: $repository);

        self::assertSame($existing, $manager->findOrCreate('tok-123'));
    }

    public function testFindOrCreateReturnsExistingShopUserWishlist(): void
    {
        $existing = new Wishlist();
        $user = $this->createMock(ShopUserInterface::class);
        $channel = $this->createChannel('FASHION_WEB');

        $repository = $this->createMock(WishlistRepositoryInterface::class);
        $repository->method('findByToken')->willReturn(null);
        $repository->method('findOneByShopUserAndChannel')->willReturn($existing);

        $manager = $this->createManager(
            wishlistRepository: $repository,
            channelContext: $this->channelContextWith($channel),
            tokenStorage: $this->tokenWith($user),
        );

        self::assertSame($existing, $manager->findOrCreate());
    }

    public function testFindOrCreateCreatesForUserAndChannelWhenMissing(): void
    {
        $wishlist = new Wishlist();
        $user = $this->createMock(ShopUserInterface::class);
        $channel = $this->createChannel('FASHION_WEB');

        $repository = $this->createMock(WishlistRepositoryInterface::class);
        $repository->method('findByToken')->willReturn(null);
        $repository->method('findOneByShopUserAndChannel')->willReturn(null);

        $factory = $this->createMock(WishlistFactoryInterface::class);
        $factory->method('createForUserAndChannel')->with($user, $channel)->willReturn($wishlist);

        $manager = $this->createManager(
            wishlistFactory: $factory,
            wishlistRepository: $repository,
            channelContext: $this->channelContextWith($channel),
            tokenStorage: $this->tokenWith($user),
        );

        self::assertSame($wishlist, $manager->findOrCreate());
    }

    public function testFindOrCreateCreatesNewForAnonymousUser(): void
    {
        $wishlist = new Wishlist();

        $repository = $this->createMock(WishlistRepositoryInterface::class);
        $repository->method('findByToken')->willReturn(null);

        $factory = $this->createMock(WishlistFactoryInterface::class);
        $factory->method('createNew')->willReturn($wishlist);

        $manager = $this->createManager(
            wishlistFactory: $factory,
            wishlistRepository: $repository,
            tokenStorage: $this->tokenWith(null),
        );

        self::assertSame($wishlist, $manager->findOrCreate());
    }

    // ------------------------------------------------------------------
    // createThemed
    // ------------------------------------------------------------------

    public function testCreateThemedSetsNameAndAppendsThemeWhenAbsent(): void
    {
        $wishlist = new Wishlist();
        $factory = $this->createMock(WishlistFactoryInterface::class);
        $factory->method('createNew')->willReturn($wishlist);

        $repository = $this->createMock(WishlistRepositoryInterface::class);
        $repository->method('findByToken')->willReturn(null);

        $manager = $this->createManager(
            wishlistFactory: $factory,
            wishlistRepository: $repository,
            tokenStorage: $this->tokenWith(null),
        );

        $result = $manager->createThemed('Birthday', 'gift');

        self::assertSame('Birthday — gift', $result->getName());
    }

    public function testCreateThemedKeepsNameWhenThemeAlreadyContained(): void
    {
        $wishlist = new Wishlist();
        $factory = $this->createMock(WishlistFactoryInterface::class);
        $factory->method('createNew')->willReturn($wishlist);

        $repository = $this->createMock(WishlistRepositoryInterface::class);
        $repository->method('findByToken')->willReturn(null);

        $manager = $this->createManager(
            wishlistFactory: $factory,
            wishlistRepository: $repository,
            tokenStorage: $this->tokenWith(null),
        );

        $result = $manager->createThemed('Gift Basket', 'gift');

        self::assertSame('Gift Basket', $result->getName());
    }

    public function testCreateThemedSetsChannelFromCodeWhenProvided(): void
    {
        $wishlist = new Wishlist();
        $channel = $this->createChannel('FASHION_WEB');

        $factory = $this->createMock(WishlistFactoryInterface::class);
        $factory->method('createNew')->willReturn($wishlist);

        $repository = $this->createMock(WishlistRepositoryInterface::class);
        $repository->method('findByToken')->willReturn(null);

        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('findOneBy')->with(['code' => 'FASHION_WEB'])->willReturn($channel);

        $manager = $this->createManager(
            wishlistFactory: $factory,
            wishlistRepository: $repository,
            channelRepository: $channelRepository,
            tokenStorage: $this->tokenWith(null),
        );

        $result = $manager->createThemed('Birthday', 'gift', 'FASHION_WEB');

        self::assertSame($channel, $result->getChannel());
    }

    public function testCreateThemedFallsBackToChannelContextWhenCodeNotProvided(): void
    {
        $wishlist = new Wishlist();
        $channel = $this->createChannel('FASHION_WEB');

        $factory = $this->createMock(WishlistFactoryInterface::class);
        $factory->method('createNew')->willReturn($wishlist);

        $repository = $this->createMock(WishlistRepositoryInterface::class);
        $repository->method('findByToken')->willReturn(null);

        $manager = $this->createManager(
            wishlistFactory: $factory,
            wishlistRepository: $repository,
            channelContext: $this->channelContextWith($channel),
            tokenStorage: $this->tokenWith(null),
        );

        $result = $manager->createThemed('Birthday', 'gift');

        self::assertSame($channel, $result->getChannel());
    }

    public function testCreateThemedToleratesChannelNotFound(): void
    {
        // findOrCreate() (anonymous) calls channelContext::getChannel() once and
        // succeeds; only createThemed's own lookup (second call) throws, which
        // must be caught by the try/catch in createThemed.
        $wishlist = new Wishlist();

        $calls = 0;
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturnCallback(function () use (&$calls) {
            ++$calls;
            if (1 === $calls) {
                return $this->createChannel('FASHION_WEB');
            }

            throw new ChannelNotFoundException();
        });

        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('findOneBy')->willReturn(null);

        $wishlistFactory = $this->createMock(WishlistFactoryInterface::class);
        $wishlistFactory->method('createNew')->willReturn($wishlist);

        $manager = $this->createManager(
            wishlistFactory: $wishlistFactory,
            channelContext: $channelContext,
            channelRepository: $channelRepository,
        );

        $result = $manager->createThemed('Birthday', 'gift', 'FASHION_WEB');

        self::assertNull($result->getChannel());
    }

    // ------------------------------------------------------------------
    // addItem
    // ------------------------------------------------------------------

    public function testAddItemAddsNewWishlistProduct(): void
    {
        $wishlist = new Wishlist();
        $variant = $this->createVariant('T_SHIRT_V', $this->createProduct('T_SHIRT'));

        $variantRepository = $this->createMock(ProductVariantRepositoryInterface::class);
        $variantRepository->method('findOneBy')->with(['code' => 'T_SHIRT_V'])->willReturn($variant);

        $productFactory = $this->createMock(WishlistProductFactoryInterface::class);
        $productFactory->method('createNew')->willReturn(new WishlistProduct());

        $manager = $this->createManager(
            wishlistProductFactory: $productFactory,
            variantRepository: $variantRepository,
        );

        $manager->addItem($wishlist, 'T_SHIRT_V', 1);

        self::assertCount(1, $wishlist->getWishlistProducts());
        $added = $wishlist->getWishlistProducts()->first();
        self::assertSame(1, $added->getQuantity());
        self::assertSame('T_SHIRT_V', $added->getVariant()?->getCode());
        self::assertSame($wishlist, $added->getWishlist());
    }

    public function testAddItemIncrementsQuantityWhenAlreadyPresent(): void
    {
        $wishlist = new Wishlist();
        $product = $this->createProduct('T_SHIRT');
        $variant = $this->createVariant('T_SHIRT_V', $product);

        $wp = new WishlistProduct();
        $wp->setProduct($product);
        $wp->setVariant($variant);
        $wp->setQuantity(2);
        $wishlist->addWishlistProduct($wp);

        $variantRepository = $this->createMock(ProductVariantRepositoryInterface::class);
        $variantRepository->method('findOneBy')->with(['code' => 'T_SHIRT_V'])->willReturn($variant);

        $manager = $this->createManager(variantRepository: $variantRepository);

        $manager->addItem($wishlist, 'T_SHIRT_V', 3);

        self::assertCount(1, $wishlist->getWishlistProducts());
        self::assertSame(5, $wp->getQuantity());
    }

    public function testAddItemThrowsWhenVariantNotFound(): void
    {
        $wishlist = new Wishlist();

        $variantRepository = $this->createMock(ProductVariantRepositoryInterface::class);
        $variantRepository->method('findOneBy')->willReturn(null);

        $manager = $this->createManager(variantRepository: $variantRepository);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Variant "MISSING" not found');

        $manager->addItem($wishlist, 'MISSING', 1);
    }

    public function testAddItemThrowsWhenVariantHasNoProduct(): void
    {
        $wishlist = new Wishlist();
        $variant = $this->createVariant('NO_PRODUCT_V'); // no product attached

        $variantRepository = $this->createMock(ProductVariantRepositoryInterface::class);
        $variantRepository->method('findOneBy')->willReturn($variant);

        $productFactory = $this->createMock(WishlistProductFactoryInterface::class);
        $productFactory->method('createNew')->willReturn(new WishlistProduct());

        $manager = $this->createManager(
            wishlistProductFactory: $productFactory,
            variantRepository: $variantRepository,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Variant "NO_PRODUCT_V" has no product');

        $manager->addItem($wishlist, 'NO_PRODUCT_V', 1);
    }

    // ------------------------------------------------------------------
    // removeItem
    // ------------------------------------------------------------------

    public function testRemoveItemRemovesMatchingWishlistProduct(): void
    {
        $wishlist = new Wishlist();
        $a = $this->createWishlistProduct('A_V', 1, 1);
        $b = $this->createWishlistProduct('B_V', 2, 2);
        $wishlist->addWishlistProduct($a);
        $wishlist->addWishlistProduct($b);

        $manager = $this->createManager();

        $manager->removeItem($wishlist, 1);

        self::assertCount(1, $wishlist->getWishlistProducts());
        self::assertSame('B_V', $wishlist->getWishlistProducts()->first()->getVariant()?->getCode());
    }

    public function testRemoveItemThrowsWhenItemNotFound(): void
    {
        $wishlist = new Wishlist();
        $a = $this->createWishlistProduct('A_V', 1, 1);
        $wishlist->addWishlistProduct($a);

        $manager = $this->createManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Item 99 not found in wishlist.');

        $manager->removeItem($wishlist, 99);
    }

    // ------------------------------------------------------------------
    // clearAllItems
    // ------------------------------------------------------------------

    public function testClearAllItemsRemovesEveryWishlistProduct(): void
    {
        $wishlist = new Wishlist();
        $wishlist->addWishlistProduct($this->createWishlistProduct('A_V', 1));
        $wishlist->addWishlistProduct($this->createWishlistProduct('B_V', 2));
        $wishlist->addWishlistProduct($this->createWishlistProduct('C_V', 3));

        $manager = $this->createManager();

        $result = $manager->clearAllItems($wishlist);

        self::assertCount(0, $result->getWishlistProducts());
    }

    public function testClearAllItemsFromRequestDelegates(): void
    {
        $wishlist = new Wishlist();
        $wishlist->addWishlistProduct($this->createWishlistProduct('A_V', 1));

        $manager = $this->createManager();

        $manager->clearAllItemsFromRequest($wishlist, new WishlistClearRequest());

        self::assertCount(0, $wishlist->getWishlistProducts());
    }

    // ------------------------------------------------------------------
    // deleteWishlist
    // ------------------------------------------------------------------

    public function testDeleteWishlistRemovesFromEntityManager(): void
    {
        $wishlist = new Wishlist();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($wishlist);

        $manager = $this->createManager(entityManager: $entityManager);

        $manager->deleteWishlist($wishlist);
    }

    public function testDeleteWishlistFromRequestDelegates(): void
    {
        $wishlist = new Wishlist();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($wishlist);

        $manager = $this->createManager(entityManager: $entityManager);

        $manager->deleteWishlistFromRequest($wishlist, new WishlistDeleteRequest());
    }

    // ------------------------------------------------------------------
    // bulkAddItems
    // ------------------------------------------------------------------

    public function testBulkAddItemsAddsNewVariants(): void
    {
        $wishlist = new Wishlist();
        $a = $this->createVariant('A_V_1', $this->createProduct('A'));
        $b = $this->createVariant('B_V_1', $this->createProduct('B'));

        $variantRepository = $this->createMock(ProductVariantRepositoryInterface::class);
        $variantRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria) => match ($criteria['code']) {
                'A_V_1' => $a,
                'B_V_1' => $b,
                default => null,
            }
        );

        $productFactory = $this->createMock(WishlistProductFactoryInterface::class);
        $productFactory->method('createNew')->willReturnCallback(fn () => new WishlistProduct());

        $manager = $this->createManager(
            wishlistProductFactory: $productFactory,
            variantRepository: $variantRepository,
        );

        $results = $manager->bulkAddItems($wishlist, [
            ['variantCode' => 'A_V_1', 'quantity' => 2],
            ['variantCode' => 'B_V_1', 'quantity' => 1],
        ]);

        self::assertCount(2, $wishlist->getWishlistProducts());
        self::assertSame('added', $results[0]['status']);
        self::assertSame('added', $results[1]['status']);
    }

    public function testBulkAddItemsSkipsMissingVariant(): void
    {
        $wishlist = new Wishlist();

        $variantRepository = $this->createMock(ProductVariantRepositoryInterface::class);
        $variantRepository->method('findOneBy')->willReturn(null);

        $manager = $this->createManager(variantRepository: $variantRepository);

        $results = $manager->bulkAddItems($wishlist, [
            ['variantCode' => 'MISSING', 'quantity' => 1],
        ]);

        self::assertCount(0, $wishlist->getWishlistProducts());
        self::assertSame('skipped', $results[0]['status']);
        self::assertStringContainsString('MISSING', $results[0]['reason']);
    }

    public function testBulkAddItemsUpdatesQuantityWhenExisting(): void
    {
        $wishlist = new Wishlist();
        $product = $this->createProduct('EXIST');
        $variant = $this->createVariant('EXIST_V', $product);
        $wp = new WishlistProduct();
        $wp->setProduct($product);
        $wp->setVariant($variant);
        $wp->setQuantity(1);
        $wishlist->addWishlistProduct($wp);

        $variantRepository = $this->createMock(ProductVariantRepositoryInterface::class);
        $variantRepository->method('findOneBy')->willReturn($variant);

        $manager = $this->createManager(variantRepository: $variantRepository);

        $results = $manager->bulkAddItems($wishlist, [
            ['variantCode' => 'EXIST_V', 'quantity' => 4],
        ]);

        self::assertCount(1, $wishlist->getWishlistProducts());
        self::assertSame(5, $wp->getQuantity());
        self::assertSame('skipped', $results[0]['status']);
        self::assertStringContainsString('already in wishlist', $results[0]['reason']);
    }

    public function testBulkAddItemsSkipsVariantWithoutProduct(): void
    {
        $wishlist = new Wishlist();
        $variant = $this->createVariant('NOPROD_V'); // no product

        $variantRepository = $this->createMock(ProductVariantRepositoryInterface::class);
        $variantRepository->method('findOneBy')->willReturn($variant);

        $manager = $this->createManager(variantRepository: $variantRepository);

        $results = $manager->bulkAddItems($wishlist, [
            ['variantCode' => 'NOPROD_V', 'quantity' => 1],
        ]);

        self::assertCount(0, $wishlist->getWishlistProducts());
        self::assertSame('skipped', $results[0]['status']);
        self::assertStringContainsString('has no product', $results[0]['reason']);
    }

    public function testBulkAddItemsFromRequestMapsNestedItems(): void
    {
        $wishlist = new Wishlist();
        $variant = $this->createVariant('MAP_V', $this->createProduct('MAP'));

        $variantRepository = $this->createMock(ProductVariantRepositoryInterface::class);
        $variantRepository->method('findOneBy')->willReturn($variant);

        $productFactory = $this->createMock(WishlistProductFactoryInterface::class);
        $productFactory->method('createNew')->willReturnCallback(fn () => new WishlistProduct());

        $manager = $this->createManager(
            wishlistProductFactory: $productFactory,
            variantRepository: $variantRepository,
        );

        $item = new WishlistBulkItem();
        $item->variantCode = 'MAP_V';
        $item->quantity = 3;

        $request = new WishlistBulkAddRequest();
        $request->items = [$item];

        $results = $manager->bulkAddItemsFromRequest($wishlist, $request);

        self::assertCount(1, $wishlist->getWishlistProducts());
        self::assertSame('added', $results[0]['status']);
        self::assertSame(3, $wishlist->getWishlistProducts()->first()->getQuantity());
    }

    // ------------------------------------------------------------------
    // toArray
    // ------------------------------------------------------------------

    public function testToArrayBuildsPayloadWithPricingAndName(): void
    {
        $channel = $this->createChannel('FASHION_WEB');
        $pricing = new ChannelPricing();
        $pricing->setChannelCode('FASHION_WEB');
        $pricing->setPrice(1703);
        $pricing->setOriginalPrice(2000);

        $variant = new ProductVariant();
        $variant->setCode('T_SHIRT_V');
        $pricingProperty = new \ReflectionProperty(ProductVariant::class, 'channelPricings');
        $pricingProperty->setAccessible(true);
        $pricingProperty->setValue($variant, new ArrayCollection(['FASHION_WEB' => $pricing]));

        $translation = $this->createMock(\Sylius\Component\Core\Model\ProductTranslationInterface::class);
        $translation->method('getName')->willReturn('T-Shirt');

        $product = $this->createMock(ProductInterface::class);
        $product->method('getCode')->willReturn('T_SHIRT');
        $product->method('getTranslation')->with('en_US')->willReturn($translation);

        $wp = $this->createMock(WishlistProductInterface::class);
        $wp->method('getId')->willReturn(7);
        $wp->method('getVariant')->willReturn($variant);
        $wp->method('getProduct')->willReturn($product);
        $wp->method('getQuantity')->willReturn(2);

        $wishlist = $this->createMock(WishlistInterface::class);
        $wishlist->method('getId')->willReturn(1);
        $wishlist->method('getName')->willReturn('Gift Wishlist');
        $wishlist->method('getChannel')->willReturn($channel);
        $wishlist->method('getWishlistProducts')->willReturn(new ArrayCollection([$wp]));

        $manager = $this->createManager(
            channelContext: $this->channelContextWith($channel),
        );

        $result = $manager->toArray($wishlist, 'en_US');

        self::assertSame(1, $result['id']);
        self::assertSame('Gift Wishlist', $result['name']);
        self::assertSame('FASHION_WEB', $result['channelCode']);
        self::assertCount(1, $result['items']);
        self::assertSame('T-Shirt', $result['items'][0]['productName']);
        self::assertSame(1703, $result['items'][0]['price']);
        self::assertSame(2000, $result['items'][0]['originalPrice']);
        self::assertSame(2, $result['items'][0]['quantity']);
        self::assertSame(1, $result['itemsCount']);
    }

    public function testToArrayFallsBackToDefaultLocaleWhenUnknown(): void
    {
        $channel = $this->createChannel('FASHION_WEB');
        $variant = new ProductVariant();
        $variant->setCode('TEE_V');

        $translation = $this->createMock(\Sylius\Component\Core\Model\ProductTranslationInterface::class);
        $translation->method('getName')->willReturn('Tee');

        $product = $this->createMock(ProductInterface::class);
        $product->method('getCode')->willReturn('TEE');
        $product->method('getTranslation')->with('en_US')->willReturn($translation);

        $wp = $this->createMock(WishlistProductInterface::class);
        $wp->method('getId')->willReturn(1);
        $wp->method('getVariant')->willReturn($variant);
        $wp->method('getProduct')->willReturn($product);
        $wp->method('getQuantity')->willReturn(1);

        $wishlist = $this->createMock(WishlistInterface::class);
        $wishlist->method('getId')->willReturn(1);
        $wishlist->method('getName')->willReturn(null);
        $wishlist->method('getChannel')->willReturn($channel);
        $wishlist->method('getWishlistProducts')->willReturn(new ArrayCollection([$wp]));

        $manager = $this->createManager(
            channelContext: $this->channelContextWith($channel),
        );

        $result = $manager->toArray($wishlist);

        self::assertSame('Tee', $result['items'][0]['productName']);
    }
}
