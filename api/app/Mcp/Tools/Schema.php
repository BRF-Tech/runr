<?php

namespace App\Mcp\Tools;

/**
 * Builders for the JSON Schema (draft 2020-12) of tool inputs. Arguments are validated with
 * App\Services\Runtimes\JsonSchemaValidator before a tool runs, so only its keywords are used
 * (type, enum, const, pattern, minLength, maxLength, minimum, maximum, items, minItems, maxItems,
 * properties, required, additionalProperties) plus the annotations description and default.
 */
final class Schema
{
    /**
     * @param  array<string, array>  $properties
     * @param  list<string>  $required
     */
    public static function object(array $properties = [], array $required = [], bool $additional = false): array
    {
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass : $properties];
        if ($required !== []) {
            $schema['required'] = array_values($required);
        }
        $schema['additionalProperties'] = $additional;

        return $schema;
    }

    public static function string(string $description, array $extra = []): array
    {
        return ['type' => 'string', 'description' => $description] + $extra;
    }

    public static function integer(string $description, ?int $min = null, ?int $max = null, array $extra = []): array
    {
        $schema = ['type' => 'integer', 'description' => $description];
        if ($min !== null) {
            $schema['minimum'] = $min;
        }
        if ($max !== null) {
            $schema['maximum'] = $max;
        }

        return $schema + $extra;
    }

    public static function boolean(string $description, ?bool $default = null): array
    {
        $schema = ['type' => 'boolean', 'description' => $description];
        if ($default !== null) {
            $schema['default'] = $default;
        }

        return $schema;
    }

    /** @param list<string|int> $values */
    public static function enum(array $values, string $description, mixed $default = null): array
    {
        $schema = ['type' => is_int($values[0] ?? null) ? 'integer' : 'string', 'enum' => array_values($values), 'description' => $description];
        if ($default !== null) {
            $schema['default'] = $default;
        }

        return $schema;
    }

    public static function array(array $items, string $description, ?int $maxItems = null): array
    {
        $schema = ['type' => 'array', 'items' => $items, 'description' => $description];
        if ($maxItems !== null) {
            $schema['maxItems'] = $maxItems;
        }

        return $schema;
    }

    /** An object with free keys whose values follow $values (env vars, file maps). */
    public static function map(array $values, string $description): array
    {
        return ['type' => 'object', 'additionalProperties' => $values, 'description' => $description];
    }

    /** The project argument every project tool takes. */
    public static function project(): array
    {
        return self::string('The project: its id, slug or exact name (projects_list shows them).', ['minLength' => 1, 'maxLength' => 255]);
    }

    /** Confirmation of a destructive call: must be true. */
    public static function confirm(string $what): array
    {
        return ['type' => 'boolean', 'const' => true, 'description' => "Must be true: confirms {$what}. Ask the user before setting it."];
    }

    /** The schema as plain arrays (for the validator: empty objects become []). */
    public static function normalize(array $schema): array
    {
        return json_decode(json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true) ?? [];
    }
}
