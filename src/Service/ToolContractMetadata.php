<?php

declare(strict_types=1);

namespace BitExpert\SyliusWishlistConciergePlugin\Service;

use BitExpert\SyliusWishlistConciergePlugin\Dto\BudgetOptimizeRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\MoveToCartRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistAddItemRequest;
use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistCreateRequest;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface;
use Symfony\Component\Validator\Mapping\PropertyMetadataInterface;

/**
 * Exposes the machine-readable input contract (JSON-Schema) of each WebMCP
 * tool by introspecting the constraint metadata declared on its input DTO.
 * This makes the "structured contract" discoverable by API consumers and
 * mirrors the inputSchema the front-end registers with the WebMCP runtime.
 */
final class ToolContractMetadata
{
    /**
     * Tool name (as registered in assets/shop/webmcp/registry.js) => DTO.
     *
     * @var array<string, class-string>
     */
    public const TOOLS = [
        'wishlist.create_themed' => WishlistCreateRequest::class,
        'wishlist.add_item' => WishlistAddItemRequest::class,
        'wishlist.optimize_for_budget' => BudgetOptimizeRequest::class,
        'wishlist.move_to_cart' => MoveToCartRequest::class,
    ];

    public function __construct(
        private readonly MetadataFactoryInterface $metadataFactory,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function contract(string $toolName): ?array
    {
        if (!isset(self::TOOLS[$toolName])) {
            return null;
        }

        $dtoClass = self::TOOLS[$toolName];
        $metadata = $this->metadataFactory->getMetadataFor($dtoClass);

        if (!$metadata instanceof ClassMetadata) {
            return null;
        }

        $properties = [];
        $required = [];

        foreach ($metadata->getConstrainedProperties() as $property) {
            $propertyMetadata = $metadata->getPropertyMetadata($property);
            $schema = $this->propertyToSchema($propertyMetadata, $property);

            if (null !== $schema) {
                $properties[$property] = $schema;
                if ($this->isRequired($propertyMetadata)) {
                    $required[] = $property;
                }
            }
        }

        return [
            'name' => $toolName,
            'dto' => $dtoClass,
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
                ...([] === $required ? [] : ['required' => $required]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $contracts = [];
        foreach (array_keys(self::TOOLS) as $tool) {
            if (null !== ($contract = $this->contract($tool))) {
                $contracts[] = $contract;
            }
        }

        return ['tools' => $contracts];
    }

    /**
     * @param PropertyMetadataInterface[] $propertyMetadata
     *
     * @return array<string, mixed>|null
     */
    private function propertyToSchema(array $propertyMetadata, string $property): ?array
    {
        $constraints = [];
        foreach ($propertyMetadata as $pm) {
            foreach ($pm->getConstraints() as $constraint) {
                $constraints[] = $constraint;
            }
        }

        $schema = [];
        if ([] === $constraints) {
            return ['type' => 'string'];
        }

        foreach ($constraints as $constraint) {
            $this->applyConstraint($schema, $constraint);
        }

        if ([] === $schema) {
            return null;
        }

        // If no explicit type constraint exists but the property is a collection,
        // infer an array schema from the All/Count constraints.
        if (!isset($schema['type']) && $this->hasArrayConstraint($constraints)) {
            $schema['type'] = 'array';
        }

        $schema['type'] ??= 'string';

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function applyConstraint(array &$schema, object $constraint): void
    {
        match (true) {
            $constraint instanceof Constraints\NotBlank,
            $constraint instanceof Constraints\NotNull => null,

            $constraint instanceof Constraints\Type
                || $constraint instanceof Constraints\Regex => $this->applyTypeConstraint($schema, $constraint),

            $constraint instanceof Constraints\Positive => $this->applyPositive($schema, false),
            $constraint instanceof Constraints\PositiveOrZero => $this->applyPositive($schema, true),

            $constraint instanceof Constraints\LessThan => $schema['maximum'] = $constraint->value - 1,
            $constraint instanceof Constraints\LessThanOrEqual => $schema['maximum'] = $constraint->value,
            $constraint instanceof Constraints\GreaterThan => $schema['minimum'] = $constraint->value + 1,
            $constraint instanceof Constraints\GreaterThanOrEqual => $schema['minimum'] = $constraint->value,

            $constraint instanceof Constraints\Length => $this->applyLength($schema, $constraint),
            $constraint instanceof Constraints\All => $this->applyAll($schema, $constraint),
            $constraint instanceof Constraints\Count => $this->applyCount($schema, $constraint),

            default => null,
        };
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function applyPositive(array &$schema, bool $includeZero): void
    {
        $schema['type'] = 'integer';
        $schema['minimum'] = $includeZero ? 0 : 1;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function applyAll(array &$schema, Constraints\All $constraint): void
    {
        $schema['type'] = 'array';
        $itemsType = null;
        foreach ($constraint->constraints as $itemConstraint) {
            if ($itemConstraint instanceof Constraints\Type || $itemConstraint instanceof Constraints\Regex) {
                $fake = [];
                $this->applyTypeConstraint($fake, $itemConstraint);
                $itemsType = $fake['type'] ?? 'string';
            }
        }
        $schema['items'] = ['type' => $itemsType ?? 'string'];
    }

    /**
     * @param array<object> $constraints
     */
    private function hasArrayConstraint(array $constraints): bool
    {
        foreach ($constraints as $constraint) {
            if ($constraint instanceof Constraints\All || $constraint instanceof Constraints\Count) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function applyTypeConstraint(array &$schema, Constraints\Type|Constraints\Regex $constraint): void
    {
        $type = match (true) {
            $constraint instanceof Constraints\Regex => 'string',
            default => strtolower((string) $constraint->type),
        };

        if ('boolean' === $type || 'bool' === $type) {
            $schema['type'] = 'boolean';
        } elseif ('integer' === $type || 'int' === $type) {
            $schema['type'] = 'integer';
        } elseif ('array' === $type) {
            $schema['type'] = 'array';
        } elseif ('float' === $type || 'double' === $type) {
            $schema['type'] = 'number';
        } else {
            $schema['type'] = 'string';
        }

        if ($constraint instanceof Constraints\Regex) {
            $schema['pattern'] = $constraint->pattern;
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function applyLength(array &$schema, Constraints\Length $constraint): void
    {
        if (null !== $constraint->min) {
            $schema['minLength'] = $constraint->min;
        }
        if (null !== $constraint->max) {
            $schema['maxLength'] = $constraint->max;
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function applyCount(array &$schema, Constraints\Count $constraint): void
    {
        if (null !== $constraint->min) {
            $schema['minItems'] = $constraint->min;
        }
        if (null !== $constraint->max) {
            $schema['maxItems'] = $constraint->max;
        }
    }

    /**
     * @param PropertyMetadataInterface[] $propertyMetadata
     */
    private function isRequired(array $propertyMetadata): bool
    {
        foreach ($propertyMetadata as $pm) {
            foreach ($pm->getConstraints() as $constraint) {
                if ($constraint instanceof Constraints\NotBlank || $constraint instanceof Constraints\NotNull) {
                    return true;
                }
            }
        }

        return false;
    }
}
