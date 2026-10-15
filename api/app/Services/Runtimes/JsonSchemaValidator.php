<?php

namespace App\Services\Runtimes;

/**
 * Validates decoded JSON (associative arrays) against a JSON Schema (draft 2020-12) using the
 * keywords the runtime manifest schema needs: type, const, enum, pattern, minLength, maxLength,
 * minimum, maximum, minItems, maxItems, uniqueItems, items, properties, required,
 * additionalProperties, propertyNames, minProperties, maxProperties, oneOf, $ref (local
 * "#/$defs/..." pointers) and format "date". Annotations ($schema, $id, title, description) are
 * ignored; any other keyword is rejected by supportedKeywords() so the schema cannot silently use
 * one this validator does not check (asserted by the test suite).
 *
 * A JSON object is a PHP array with string keys; a list is an array with sequential int keys. An
 * empty array is accepted as both (YAML "{}" and "[]" decode the same way).
 */
class JsonSchemaValidator
{
    public const KEYWORDS = [
        '$schema', '$id', '$defs', 'title', 'description',
        'type', 'const', 'enum', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum',
        'minItems', 'maxItems', 'uniqueItems', 'items', 'properties', 'required',
        'additionalProperties', 'propertyNames', 'minProperties', 'maxProperties', 'oneOf', '$ref', 'format',
    ];

    /** @var list<array{path: string, message: string}> */
    private array $problems = [];

    public function __construct(private array $root) {}

    /**
     * @return list<array{path: string, message: string}> empty when $data is valid
     */
    public function validate(mixed $data): array
    {
        $this->problems = [];
        $this->check($data, $this->root, '');

        return $this->problems;
    }

    /**
     * Keywords of $schema (recursively) this validator does not implement.
     *
     * @return list<string>
     */
    public static function unsupportedKeywords(array $schema): array
    {
        $found = [];
        $walk = function (array $node) use (&$walk, &$found) {
            foreach ($node as $key => $value) {
                if (! in_array($key, self::KEYWORDS, true)) {
                    $found[] = (string) $key;
                }
                if (in_array($key, ['properties', '$defs'], true) && is_array($value)) {
                    foreach ($value as $sub) {
                        if (is_array($sub)) {
                            $walk($sub);
                        }
                    }
                } elseif (in_array($key, ['items', 'additionalProperties', 'propertyNames'], true) && is_array($value)) {
                    $walk($value);
                } elseif ($key === 'oneOf' && is_array($value)) {
                    foreach ($value as $sub) {
                        $walk($sub);
                    }
                }
            }
        };
        $walk($schema);

        return array_values(array_unique($found));
    }

    private function check(mixed $data, array $schema, string $path): bool
    {
        $before = count($this->problems);

        if (isset($schema['$ref'])) {
            $this->check($data, $this->resolve($schema['$ref']), $path);
        }

        if (array_key_exists('const', $schema) && $data !== $schema['const']) {
            $this->fail($path, 'must be '.json_encode($schema['const'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $this->fail($path, 'must be one of: '.implode(', ', array_map(fn ($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $schema['enum'])));
        }

        if (isset($schema['type']) && ! $this->hasType($data, (array) $schema['type'])) {
            $this->fail($path, 'must be of type '.implode(' or ', (array) $schema['type']));

            return false;
        }

        if (is_string($data)) {
            $this->checkString($data, $schema, $path);
        } elseif (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $this->fail($path, "must be >= {$schema['minimum']}");
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $this->fail($path, "must be <= {$schema['maximum']}");
            }
        } elseif (is_array($data)) {
            if (array_is_list($data) && ($data !== [] || ! $this->wantsObject($schema))) {
                $this->checkList($data, $schema, $path);
            }
            if (! array_is_list($data) || ($data === [] && $this->wantsObject($schema))) {
                $this->checkObject($data, $schema, $path);
            }
        }

        if (isset($schema['oneOf'])) {
            $this->checkOneOf($data, $schema['oneOf'], $path);
        }

        return count($this->problems) === $before;
    }

    private function checkString(string $data, array $schema, string $path): void
    {
        $length = mb_strlen($data);
        if (isset($schema['minLength']) && $length < $schema['minLength']) {
            $this->fail($path, $schema['minLength'] === 1 ? 'must not be empty' : "must be at least {$schema['minLength']} characters");
        }
        if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
            $this->fail($path, "must be at most {$schema['maxLength']} characters");
        }
        if (isset($schema['pattern']) && ! preg_match('~'.str_replace('~', '\~', $schema['pattern']).'~u', $data)) {
            $this->fail($path, "must match {$schema['pattern']}");
        }
        if (($schema['format'] ?? null) === 'date') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
            if (! $date || $date->format('Y-m-d') !== $data) {
                $this->fail($path, 'must be a date (YYYY-MM-DD)');
            }
        }
    }

    private function checkList(array $data, array $schema, string $path): void
    {
        $count = count($data);
        if (isset($schema['minItems']) && $count < $schema['minItems']) {
            $this->fail($path, "must have at least {$schema['minItems']} item(s)");
        }
        if (isset($schema['maxItems']) && $count > $schema['maxItems']) {
            $this->fail($path, "must have at most {$schema['maxItems']} items");
        }
        if (! empty($schema['uniqueItems']) && count(array_unique(array_map('serialize', $data))) !== $count) {
            $this->fail($path, 'must not contain duplicates');
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($data as $i => $item) {
                $this->check($item, $schema['items'], "{$path}/{$i}");
            }
        }
    }

    private function checkObject(array $data, array $schema, string $path): void
    {
        $count = count($data);
        if (isset($schema['minProperties']) && $count < $schema['minProperties']) {
            $this->fail($path, "must have at least {$schema['minProperties']} entr".($schema['minProperties'] === 1 ? 'y' : 'ies'));
        }
        if (isset($schema['maxProperties']) && $count > $schema['maxProperties']) {
            $this->fail($path, "must have at most {$schema['maxProperties']} entries");
        }

        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $data)) {
                $this->fail($this->join($path, $key), 'is required');
            }
        }

        $properties = $schema['properties'] ?? [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            $childPath = $this->join($path, $key);

            if (isset($schema['propertyNames']) && ! $this->checkDetached($key, $schema['propertyNames'])) {
                $this->fail($childPath, 'is not an allowed name');

                continue;
            }

            if (array_key_exists($key, $properties)) {
                $this->check($value, $properties[$key], $childPath);
            } elseif (array_key_exists('additionalProperties', $schema)) {
                $additional = $schema['additionalProperties'];
                if ($additional === false) {
                    $this->fail($childPath, 'is not a known field');
                } elseif (is_array($additional)) {
                    $this->check($value, $additional, $childPath);
                }
            }
        }
    }

    private function checkOneOf(mixed $data, array $options, string $path): void
    {
        $matches = 0;
        foreach ($options as $option) {
            if ($this->checkDetached($data, $option)) {
                $matches++;
            }
        }

        if ($matches !== 1) {
            $this->fail($path, $matches === 0 ? 'does not match any allowed form' : 'matches more than one allowed form');
        }
    }

    /** Whether $data is valid against $schema, without recording problems. */
    private function checkDetached(mixed $data, array $schema): bool
    {
        $saved = $this->problems;
        $valid = $this->check($data, $schema, '');
        $this->problems = $saved;

        return $valid;
    }

    private function hasType(mixed $data, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'string' => is_string($data),
                'integer' => is_int($data),
                'number' => is_int($data) || is_float($data),
                'boolean' => is_bool($data),
                'null' => $data === null,
                'array' => is_array($data) && array_is_list($data),
                'object' => is_array($data) && (! array_is_list($data) || $data === []),
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private function wantsObject(array $schema): bool
    {
        $schema = isset($schema['$ref']) ? $this->resolve($schema['$ref']) + $schema : $schema;

        return in_array('object', (array) ($schema['type'] ?? []), true)
            || isset($schema['properties']) || isset($schema['additionalProperties']);
    }

    private function resolve(string $ref): array
    {
        if (! str_starts_with($ref, '#/')) {
            throw new \InvalidArgumentException("Only local \$ref pointers are supported: {$ref}");
        }

        $node = $this->root;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                throw new \InvalidArgumentException("Unresolvable \$ref: {$ref}");
            }
            $node = $node[$segment];
        }

        return $node;
    }

    private function join(string $path, string $key): string
    {
        return $path.'/'.str_replace(['~', '/'], ['~0', '~1'], $key);
    }

    private function fail(string $path, string $message): void
    {
        $this->problems[] = ['path' => $path === '' ? '/' : $path, 'message' => $message];
    }
}
