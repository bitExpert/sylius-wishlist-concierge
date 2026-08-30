<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Service;

use BitExpert\SyliusWishlistConciergePlugin\Service\ThemedProductFinder;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Attribute\Model\Attribute;
use Sylius\Component\Product\Model\ProductAttributeValue;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductRepository;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Core\Model\ProductTranslation;

/**
 * @covers \BitExpert\SyliusWishlistConciergePlugin\Service\ThemedProductFinder
 */
final class ThemedProductFinderTest extends TestCase
{
    public function testMatchesThemeByConciergeTagAttribute(): void
    {
        $tagged = $this->createProduct('A', 'Tagged T-Shirt', ['dino']);
        $notTagged = $this->createProduct('B', 'Plain Tee', []);

        $finder = $this->createFinder([$tagged, $notTagged]);

        $results = $finder->find('dino', 'FASHION_WEB', null, null, null);

        self::assertCount(1, $results);
        self::assertSame('A', $results[0]['code']);
    }

    public function testFallsBackToNameMatchWhenNoAttributes(): void
    {
        $product = $this->createProduct('C', 'Dino Party Tee', []);

        $finder = $this->createFinder([$product]);

        $results = $finder->find('dino', 'FASHION_WEB', null, null, null);

        self::assertCount(1, $results);
        self::assertSame('C', $results[0]['code']);
    }

    public function testReturnsAllWhenThemeMatchesNothing(): void
    {
        $a = $this->createProduct('A', 'Tee A', []);
        $b = $this->createProduct('B', 'Tee B', []);

        $finder = $this->createFinder([$a, $b]);

        $results = $finder->find('nonexistent', 'FASHION_WEB', null, null, null);

        self::assertCount(2, $results);
    }

    public function testRespectsPriceFilters(): void
    {
        $cheap = $this->createProduct('CHEAP', 'Cheap', [], 1000);
        $expensive = $this->createProduct('EXP', 'Expensive', [], 5000);

        $finder = $this->createFinder([$cheap, $expensive]);

        $results = $finder->find(null, 'FASHION_WEB', 2000, 4000, null);

        self::assertCount(0, $results);

        $results = $finder->find(null, 'FASHION_WEB', 500, 6000, null);

        self::assertCount(2, $results);
    }

    public function testRespectsLimit(): void
    {
        $products = [
            $this->createProduct('A', 'A', ['tag']),
            $this->createProduct('B', 'B', ['tag']),
            $this->createProduct('C', 'C', ['tag']),
        ];

        $finder = $this->createFinder($products);

        $results = $finder->find('tag', 'FASHION_WEB', null, null, null, 2);

        self::assertCount(2, $results);
    }

    private function createFinder(array $products): ThemedProductFinder
    {
        $locale = $this->createMock(LocaleInterface::class);
        $locale->method('getCode')->willReturn('en_US');

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('FASHION_WEB');
        $channel->method('getDefaultLocale')->willReturn($locale);

        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('findOneBy')->willReturn($channel);

        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($channel);

        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn($products);

        $queryBuilder = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['innerJoin', 'andWhere', 'setParameter', 'getQuery'])
            ->getMock();
        $queryBuilder->method('getQuery')->willReturn($query);
        $queryBuilder->method('innerJoin')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();

        $productRepository = $this->getMockBuilder(ProductRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getMock();
        $productRepository->method('createQueryBuilder')->willReturn($queryBuilder);

        return new ThemedProductFinder($productRepository, $channelRepository, $channelContext);
    }

    /**
     * @param string[] $tags
     */
    private function createProduct(string $code, string $name, array $tags, int $price = 1999): ProductInterface
    {
        $product = new Product();

        $translation = new ProductTranslation();
        $translation->setName($name);
        $translation->setSlug(strtolower(str_replace(' ', '-', $name)));
        $translation->setLocale('en_US');
        $product->addTranslation($translation);

        $productCode = new \ReflectionProperty(Product::class, 'code');
        $productCode->setAccessible(true);
        $productCode->setValue($product, $code);

        foreach ($tags as $tag) {
            $attribute = new Attribute();
            $attribute->setCode('concierge_tags');
            $attribute->setStorageType('Text');
            $attribute->setType('text');

            $value = new ProductAttributeValue();
            $value->setAttribute($attribute);
            $value->setValue($tag);
            $product->addAttribute($value);
        }

        $pricing = new ChannelPricing();
        $pricing->setChannelCode('FASHION_WEB');
        $pricing->setPrice($price);
        $pricing->setOriginalPrice($price);

        $variant = new ProductVariant();
        $variant->setCode($code . '_V');
        $variant->addChannelPricing($pricing);
        $product->addVariant($variant);
        $product->setVariantSelectionMethod(ProductInterface::VARIANT_SELECTION_CHOICE);

        return $product;
    }
}
