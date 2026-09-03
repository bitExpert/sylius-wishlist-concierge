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

namespace BitExpert\SyliusWishlistConciergePlugin\Service;

use Doctrine\Common\Collections\Collection;
use Sylius\Bundle\ProductBundle\Doctrine\ORM\ProductRepository;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ThemedProductFinder
{
    private const int DEFAULT_LIMIT = 12;

    private const string TAGS_ATTRIBUTE_CODE = 'concierge_tags';

    /**
     * @phpstan-param ProductRepository<ProductInterface> $productRepository
     * @phpstan-param ChannelRepositoryInterface<ChannelInterface> $channelRepository
     */
    public function __construct(
        private ProductRepository $productRepository,
        private ChannelRepositoryInterface $channelRepository,
        private ChannelContextInterface $channelContext,
    ) {
    }

    /**
     * @param string[]|null $taxonCodes
     *
     * @return array<int, array{code:string, name:string, slug:string, price:int, originalPrice:int, taxonCodes:string[], image:string|null, variantCode:string}>
     */
    public function find(?string $theme, ?string $channelCode, ?int $priceMin, ?int $priceMax, ?array $taxonCodes = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $channel = $this->resolveChannel($channelCode);
        $localeCode = $channel->getDefaultLocale()?->getCode() ?? 'en_US';

        $products = $this->findEnabledProductsForChannel($channel, $taxonCodes);

        $results = $this->filterAndMap($products, $channel, $localeCode, $theme, $priceMin, $priceMax, $limit);

        if ([] === $results && null !== $theme) {
            $results = $this->fallback($channel, $localeCode, $limit);
        }

        return $results;
    }

    /**
     * @param string[]|null $taxonCodes
     *
     * @return array<int, ProductInterface>
     */
    private function findEnabledProductsForChannel(ChannelInterface $channel, ?array $taxonCodes): array
    {
        $qb = $this->productRepository->createQueryBuilder('p')
            ->innerJoin('p.channels', 'ch')
            ->andWhere('p.enabled = :enabled')
            ->andWhere('ch.code = :channelCode')
            ->setParameter('enabled', true)
            ->setParameter('channelCode', $channel->getCode());

        if (null !== $taxonCodes && [] !== $taxonCodes) {
            $qb->innerJoin('p.productTaxons', 'pt')
                ->innerJoin('pt.taxon', 't')
                ->andWhere('t.code IN (:taxonCodes)')
                ->setParameter('taxonCodes', $taxonCodes);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param  ProductInterface[] $products
     *
     * @return array<int, array{code:string, name:string, slug:string, price:int, originalPrice:int, taxonCodes:string[], image:string|null, variantCode:string}>
     */
    private function filterAndMap(array $products, ChannelInterface $channel, string $localeCode, ?string $theme, ?int $priceMin, ?int $priceMax, int $limit): array
    {
        $results = [];
        foreach ($products as $product) {
            if (null !== $theme && !$this->matchesTheme($product, $theme, $localeCode)) {
                continue;
            }

            $mapped = $this->mapProduct($product, $channel, $localeCode);
            if (null === $mapped) {
                continue;
            }

            if (null !== $priceMin && $mapped['price'] < $priceMin) {
                continue;
            }
            if (null !== $priceMax && $mapped['price'] > $priceMax) {
                continue;
            }

            $results[] = $mapped;

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * @return array<int, array{code:string, name:string, slug:string, price:int, originalPrice:int, taxonCodes:string[], image:string|null, variantCode:string}>
     */
    private function fallback(ChannelInterface $channel, string $localeCode, int $limit): array
    {
        $products = $this->findEnabledProductsForChannel($channel, null);
        $results = [];
        foreach ($products as $product) {
            $mapped = $this->mapProduct($product, $channel, $localeCode);
            if (null === $mapped) {
                continue;
            }
            $results[] = $mapped;
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function matchesTheme(ProductInterface $product, string $theme, string $localeCode): bool
    {
        $lowerTheme = strtolower($theme);

        // Hard match: concierge_tags attribute (select: value is an array of tag keys,
        // but keep the legacy comma-separated string form working too).
        foreach ($product->getAttributes() as $av) {
            if ($av->getAttribute()?->getCode() !== self::TAGS_ATTRIBUTE_CODE) {
                continue;
            }
            $value = $av->getValue();
            $tags = is_array($value)
                ? array_map(static fn ($t): string => (string) $t, $value)
                : (is_string($value)
                    ? explode(',', $value)
                    : [(string) $value]);
            foreach ($tags as $tag) {
                if ($lowerTheme === strtolower(trim($tag))) {
                    return true;
                }
            }
        }

        // Soft match: name contains
        $name = (string) $product->getTranslation($localeCode)->getName();
        if ('' !== $name && str_contains(strtolower($name), $lowerTheme)) {
            return true;
        }

        return false;
    }

    /**
     * @return array{code:string, name:string, slug:string, price:int, originalPrice:int, taxonCodes:string[], image:string|null, variantCode:string}|null
     */
    private function mapProduct(ProductInterface $product, ChannelInterface $channel, string $localeCode): ?array
    {
        /** @var Collection<int, ProductVariantInterface> $enabledVariants */
        $enabledVariants = $product->getEnabledVariants();
        /** @var Collection<int, ProductVariantInterface> $variants */
        $variants = $product->getVariants();

        $variant = $enabledVariants->first();
        if (false === $variant) {
            $variant = $variants->first();
        }
        if (false === $variant) {
            return null;
        }

        $channelPricing = $variant->getChannelPricingForChannel($channel);
        if (null === $channelPricing) {
            return null;
        }

        $price = $channelPricing->getPrice() ?? 0;
        $original = $channelPricing->getOriginalPrice() ?? $price;

        return [
            'code' => (string) $product->getCode(),
            'name' => (string) $product->getTranslation($localeCode)->getName(),
            'slug' => (string) $product->getTranslation($localeCode)->getSlug(),
            'price' => $price,
            'originalPrice' => $original,
            'taxonCodes' => $this->getTaxonCodes($product),
            'image' => $this->getImage($product),
            'variantCode' => (string) $variant->getCode(),
        ];
    }

    private function resolveChannel(?string $code): ChannelInterface
    {
        if (null !== $code) {
            /**
             * @var ChannelInterface|null $channel
             */
            $channel = $this->channelRepository->findOneBy(['code' => $code]);
            if (null === $channel) {
                throw new NotFoundHttpException(sprintf('Channel "%s" not found.', $code));
            }

            return $channel;
        }

        /** @var ChannelInterface $channel */
        $channel = $this->channelContext->getChannel();

        return $channel;
    }

    public function getDefaultChannelCode(): string
    {
        $channel = $this->channelContext->getChannel();

        /** @phpstan-ignore-next-line */
        return $channel ? (string) $channel->getCode() : '';
    }

    /**
     * Fetch a single product by code for the given channel.
     *
     * @return array{code:string, name:string, slug:string, price:int, originalPrice:int, taxonCodes:string[], image:string|null, variantCode:string}|null
     */
    public function findByCode(string $productCode, ?string $channelCode = null): ?array
    {
        $channel = $this->resolveChannel($channelCode);
        $localeCode = $channel->getDefaultLocale()?->getCode() ?? 'en_US';

        /**
         * @var ProductInterface|null $product
         */
        $product = $this->productRepository->findOneBy(['code' => $productCode, 'enabled' => true]);

        if (null === $product) {
            return null;
        }

        return $this->mapProduct($product, $channel, $localeCode);
    }

    /**
     * @return string[]
     */
    private function getTaxonCodes(ProductInterface $product): array
    {
        $codes = [];
        foreach ($product->getProductTaxons() as $pt) {
            $code = $pt->getTaxon()?->getCode();
            if (null !== $code) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function getImage(ProductInterface $product): ?string
    {
        $image = $product->getImages()->first();
        if (false === $image) {
            return null;
        }

        return $image->getPath();
    }
}
