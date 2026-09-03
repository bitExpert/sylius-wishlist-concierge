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

namespace BitExpert\SyliusWishlistConciergePlugin\Attribute;

/**
 * Marks a controller method as an imperatively registered ModelContext tool.
 *
 * The ModelContextToolCollector reads this attribute and resolves the tool's
 * Symfony route via the routeName property (falling back to a #[Route]
 * attribute on the method) to build a machine-readable tool manifest
 * (name, description, inputSchema, annotations, route URL).
 */
#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_METHOD)]
final class ModelContextTool
{
    public function __construct(
        /**
         * Unique tool identifier, e.g. "wishlist.create".
         */
        public readonly string $name,
        /**
         * Human-readable description shown to AI agents.
         */
        public readonly string $description,
        /**
         * DTO class whose validation constraints drive the inputSchema. Mutually exclusive with manualInputSchema.
         */
        public readonly ?string $dtoClass = null,
        /**
         * Complete JSON-Schema input object. When non-empty it takes precedence over dtoClass.
         *
         * @var array<string, mixed>
         */
        public readonly array $manualInputSchema = [],
        public readonly bool $readOnlyHint = false,
        public readonly bool $destructiveHint = false,
        public readonly bool $idempotentHint = false,
        /**
         * Static confirmation prompt shown before execution. Empty string = no confirmation.
         */
        public readonly string $confirmMessage = '',
        /**
         * DOM CustomEvent names dispatched after a successful execution.
         *
         * @var string[]
         */
        public readonly array $emitsEvents = [],
        /**
         * When true the JS executor skips the locale prefix (for external APIs).
         */
        public readonly bool $skipBaseUrl = false,
        /**
         * Maps an input key to the route path placeholder it fills, e.g. ['wishlistId' => 'id']. When empty, placeholders are matched by identical key name.
         *
         * @var array<string, string>
         */
        public readonly array $pathParams = [],
        /**
         * Static query-string parameters appended to every request (e.g. channelCode).
         *
         * @var array<string, string>
         */
        public readonly array $queryParams = [],
        /**
         * Extra HTTP headers sent with every request.
         *
         * @var array<string, string>
         */
        public readonly array $headers = [],
        /**
         * Name of a Symfony route that this tool should use. When provided, the collector will look up the route by name instead of reading a #[Route] attribute.
         */
        public readonly ?string $routeName = null,
    ) {
    }
}
