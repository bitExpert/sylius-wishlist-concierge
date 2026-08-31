<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Service;

use BitExpert\SyliusWishlistConciergePlugin\Service\ToolContractMetadata;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader;
use Symfony\Component\Validator\Mapping\Loader\LoaderInterface;
use Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory;

final class ToolContractMetadataTest extends TestCase
{
    private ToolContractMetadata $metadata;

    protected function setUp(): void
    {
        $loader = new AttributeLoader();
        /** @var LoaderInterface $loader */
        $this->metadata = new ToolContractMetadata(new LazyLoadingMetadataFactory($loader));
    }

    public function testAllExposesEveryTool(): void
    {
        $payload = $this->metadata->all();

        self::assertArrayHasKey('tools', $payload);
        self::assertCount(5, $payload['tools']);
    }

    public function testUnknownToolReturnsNull(): void
    {
        self::assertNull($this->metadata->contract('does.not.exist'));
    }

    public function testCreateContractDeclaresRequiredNameAndTheme(): void
    {
        $contract = $this->metadata->contract('wishlist.create');

        self::assertSame('object', $contract['inputSchema']['type']);
        self::assertSame(['name', 'theme'], $contract['inputSchema']['required']);
        self::assertSame('string', $contract['inputSchema']['properties']['name']['type']);
        self::assertArrayHasKey('pattern', $contract['inputSchema']['properties']['theme']);
    }

    public function testAddItemQuantityIsBoundedPositiveInteger(): void
    {
        $contract = $this->metadata->contract('wishlist.add_item');

        $quantity = $contract['inputSchema']['properties']['quantity'];
        self::assertSame('integer', $quantity['type']);
        self::assertSame(1, $quantity['minimum']);
        self::assertSame(99, $quantity['maximum']);
    }

    public function testMoveToCartDeclaresArrayVariantCodes(): void
    {
        $contract = $this->metadata->contract('wishlist.move_to_cart');

        $variantCodes = $contract['inputSchema']['properties']['variantCodes'];
        self::assertSame('array', $variantCodes['type']);
        self::assertSame('string', $variantCodes['items']['type']);
    }
}
