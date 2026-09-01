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

use BitExpert\SyliusWishlistConciergePlugin\Attribute\ModelContextTool;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface;
use Symfony\Component\Validator\Mapping\PropertyMetadataInterface;

/**
     * Collects ModelContext tool definitions from controller methods by reading
     * the #[ModelContextTool] and #[Route] attributes via PHP reflection.
 *
 * The resulting manifest is the single source of truth consumed by the
 * JS frontend and the contract endpoints.
 */
final class ModelContextToolCollector
{
    /*     * @var list<array{tool: ModelContextTool, route: array{path: string, name: string, methods: list<string>}, method: \ReflectionMethod}>|null */
    private ?array $cache = null;

    /**
     * @param list<class-string>       $controllerClasses
     * @param MetadataFactoryInterface $metadataFactory
     */
    public function __construct(
        private readonly array $controllerClasses,
        private readonly MetadataFactoryInterface $metadataFactory,
    ) {
    }

    /**
     * Build the full tool manifest.
     *
     * @return list<array{
     *     name: string,
     *     description: string,
     *     inputSchema: array<string, mixed>,
     *     annotations: array<string, bool>,
     *     route: array{path: string, name: string, methods: list<string>},
     *     confirmMessage: string,
     *     emitsEvents: list<string>,
     *     skipBaseUrl: bool,
     *     pathParams: array<string, string>,
     *     queryParams: array<string, string>,
     *     headers: array<string, string>,
     * }>
     */
    public function collect(): array
    {
        $tools = [];

        foreach ($this->resolveAll() as $entry) {
            $tool = $entry['tool'];
            $route = $entry['route'];

            $inputSchema = $tool->manualInputSchema !== []
                ? $tool->manualInputSchema
                : $this->resolveInputSchema($tool);

            $inputSchema = $this->mergePathParamsIntoSchema($inputSchema, $tool->pathParams, $route['path'], $entry['method']);

            $tools[] = [
                'name' => $tool->name,
                'description' => $tool->description,
                'inputSchema' => $inputSchema,
                'annotations' => $this->annotations($tool),
                'route' => $route,
                'confirmMessage' => $tool->confirmMessage,
                'emitsEvents' => $tool->emitsEvents,
                'skipBaseUrl' => $tool->skipBaseUrl,
                'pathParams' => $tool->pathParams,
                'queryParams' => $tool->queryParams,
                'headers' => $tool->headers,
            ];
        }

        return $tools;
    }

    /**
     * Return a map of route-name => DTO class for all tools that use a DTO.
     *
     * @return array<string, class-string>
     */
    public function routeDtoMap(): array
    {
        $map = [];

        foreach ($this->resolveAll() as $entry) {
            $tool = $entry['tool'];
            if (null !== $tool->dtoClass) {
                $map[$entry['route']['name']] = $tool->dtoClass;
            }
        }

        return $map;
    }

    /**
     * Find a single tool definition by its route name.
     *
     * @return array<string, mixed>|null
     */
    public function findByRouteName(string $routeName): ?array
    {
        foreach ($this->collect() as $tool) {
            if ($tool['route']['name'] === $routeName) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @return list<array{tool: ModelContextTool, route: array{path: string, name: string, methods: list<string>}, method: \ReflectionMethod}>
     */
    private function resolveAll(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $entries = [];

        foreach ($this->controllerClasses as $controllerClass) {
            if (!class_exists($controllerClass)) {
                continue;
            }

            $reflection = new \ReflectionClass($controllerClass);
            $classPrefix = $this->resolveClassPrefix($reflection);

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(ModelContextTool::class) as $attr) {
                    /** @var ModelContextTool $tool */
                    $tool = $attr->newInstance();
                    $route = $this->resolveRoute($method, $classPrefix);

                    if (null !== $route) {
                        $entries[] = ['tool' => $tool, 'route' => $route, 'method' => $method];
                    }
                }
            }
        }

        $this->cache = $entries;

        return $this->cache;
    }

    private function resolveClassPrefix(\ReflectionClass $reflection): string
    {
        $attrs = $reflection->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF);

        if ([] === $attrs) {
            return '';
        }

        /** @var Route $route */
        $route = $attrs[0]->newInstance();

        return $route->path ?? '';
    }

    /**
     * @return array{path: string, name: string, methods: list<string>}|null
     */
    private function resolveRoute(\ReflectionMethod $method, string $classPrefix): ?array
    {
        $attrs = $method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF);

        if ([] === $attrs) {
            return null;
        }

        /** @var Route $route */
        $route = $attrs[0]->newInstance();

        $path = $classPrefix . ($route->path ?? '');
        $methods = $route->methods ?? [];
        $name = $route->name ?? $this->generateRouteName($method);

        return [
            'path' => $path,
            'name' => $name,
            'methods' => $methods,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function annotations(ModelContextTool $tool): array
    {
        $annotations = [];
        if ($tool->readOnlyHint) {
            $annotations['readOnlyHint'] = true;
        }
        if ($tool->destructiveHint) {
            $annotations['destructiveHint'] = true;
        }
        if ($tool->idempotentHint) {
            $annotations['idempotentHint'] = true;
        }

        return $annotations;
    }

    private function generateRouteName(\ReflectionMethod $method): string
    {
        return strtolower($method->getDeclaringClass()->getShortName() . '_' . $method->getName());
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveInputSchema(ModelContextTool $tool): array
    {
        if (null === $tool->dtoClass) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }

        $metadata = $this->metadataFactory->getMetadataFor($tool->dtoClass);

        if (!$metadata instanceof ClassMetadata) {
            return ['type' => 'object', 'properties' => new \stdClass()];
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

        $result = [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
        ];

        if ([] !== $required) {
            $result['required'] = $required;
        }

        return $result;
    }

    /**
     * Augment the inputSchema with properties for every route path placeholder
     * that is not already covered by the DTO-derived (or manual) schema.
     *
     * Supplies the input fields (e.g. wishlistId, itemId, productCode) that the
     * JS toolbox renders and the generic executor needs to fill the URL.
     *
     * @param array<string, mixed>  $schema
     * @param array<string, string> $pathParams
     *
     * @return array<string, mixed>
     */
    private function mergePathParamsIntoSchema(array $schema, array $pathParams, string $routePath, \ReflectionMethod $method): array
    {
        $placeholders = [];
        if (preg_match_all('/\{(\w+)\}/', $routePath, $matches)) {
            $placeholders = $matches[1];
        }

        if ([] === $placeholders) {
            return $schema;
        }

        $properties = $schema['properties'] ?? [];
        if ($properties instanceof \stdClass) {
            $properties = [];
        }
        $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];
        $reversePathParams = array_flip($pathParams);

        foreach ($placeholders as $placeholder) {
            if ('_locale' === $placeholder) {
                continue;
            }

            $inputKey = $reversePathParams[$placeholder] ?? $placeholder;

            if (isset($properties[$inputKey])) {
                continue;
            }

            $properties[$inputKey] = ['type' => $this->resolvePlaceholderType($method, $placeholder)];
            if (!in_array($inputKey, $required, true)) {
                $required[] = $inputKey;
            }
        }

        if ([] === $properties) {
            return $schema;
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * Resolve the JSON-Schema type for a route placeholder from the controller
     * method parameter with the same name (e.g. get(int $id) => "integer").
     */
    private function resolvePlaceholderType(\ReflectionMethod $method, string $placeholder): string
    {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getName() !== $placeholder) {
                continue;
            }

            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
                return match ($type->getName()) {
                    'int' => 'integer',
                    'float', 'double' => 'number',
                    'bool' => 'boolean',
                    default => 'string',
                };
            }

            return 'string';
        }

        return 'string';
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
