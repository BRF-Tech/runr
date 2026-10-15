<?php

namespace Tests\Unit\Runtimes;

use App\Services\Runtimes\JsonSchemaValidator;
use App\Services\Runtimes\ManifestValidator;
use Tests\TestCase;

/**
 * The JSON Schema subset validator behind runtime manifests: each keyword it implements, the
 * problem paths (JSON pointers) it reports, and that the shipped schema uses nothing else.
 */
class JsonSchemaValidatorTest extends TestCase
{
    private function problems(array $schema, mixed $data): array
    {
        return (new JsonSchemaValidator($schema))->validate($data);
    }

    public function test_the_runtime_schema_only_uses_supported_keywords(): void
    {
        $this->assertSame([], JsonSchemaValidator::unsupportedKeywords(ManifestValidator::schema()));
        $this->assertSame(['if'], JsonSchemaValidator::unsupportedKeywords(['type' => 'object', 'if' => ['type' => 'string']]));
    }

    public function test_types(): void
    {
        $this->assertSame([], $this->problems(['type' => 'string'], 'x'));
        $this->assertSame([['path' => '/', 'message' => 'must be of type string']], $this->problems(['type' => 'string'], 1));
        $this->assertSame([], $this->problems(['type' => 'integer'], 3));
        $this->assertNotSame([], $this->problems(['type' => 'integer'], 3.5));
        $this->assertSame([], $this->problems(['type' => 'number'], 3.5));
        $this->assertSame([], $this->problems(['type' => 'boolean'], false));
        $this->assertSame([], $this->problems(['type' => 'array'], ['a', 'b']));
        $this->assertNotSame([], $this->problems(['type' => 'array'], ['a' => 1]));
        $this->assertSame([], $this->problems(['type' => 'object'], ['a' => 1]));
        $this->assertNotSame([], $this->problems(['type' => 'object'], ['a', 'b']));
        // YAML "{}" and "[]" decode to the same empty array: both types accept it
        $this->assertSame([], $this->problems(['type' => 'object'], []));
        $this->assertSame([], $this->problems(['type' => 'array'], []));
        $this->assertSame([], $this->problems(['type' => ['string', 'null']], null));
    }

    public function test_const_enum_and_strings(): void
    {
        $this->assertSame([['path' => '/', 'message' => 'must be "runr.sh/v1"']], $this->problems(['const' => 'runr.sh/v1'], 'v2'));
        $this->assertSame([['path' => '/', 'message' => 'must be one of: "http", "exec"']], $this->problems(['enum' => ['http', 'exec']], 'cgi'));
        $this->assertSame([['path' => '/', 'message' => 'must not be empty']], $this->problems(['type' => 'string', 'minLength' => 1], ''));
        $this->assertSame([['path' => '/', 'message' => 'must be at most 3 characters']], $this->problems(['type' => 'string', 'maxLength' => 3], 'abcd'));
        $this->assertSame([['path' => '/', 'message' => 'must match ^[a-z]+$']], $this->problems(['type' => 'string', 'pattern' => '^[a-z]+$'], 'A1'));
        // multibyte length counts characters
        $this->assertSame([], $this->problems(['type' => 'string', 'maxLength' => 2], 'çş'));
    }

    public function test_numbers_and_dates(): void
    {
        $this->assertSame([['path' => '/', 'message' => 'must be >= 1']], $this->problems(['type' => 'integer', 'minimum' => 1], 0));
        $this->assertSame([['path' => '/', 'message' => 'must be <= 16']], $this->problems(['type' => 'integer', 'maximum' => 16], 17));
        $this->assertSame([], $this->problems(['type' => 'string', 'format' => 'date'], '2027-01-31'));
        $this->assertSame([['path' => '/', 'message' => 'must be a date (YYYY-MM-DD)']], $this->problems(['type' => 'string', 'format' => 'date'], '2027-02-30'));
        $this->assertNotSame([], $this->problems(['type' => 'string', 'format' => 'date'], '31.01.2027'));
    }

    public function test_arrays(): void
    {
        $schema = ['type' => 'array', 'minItems' => 1, 'maxItems' => 2, 'uniqueItems' => true, 'items' => ['type' => 'string']];

        $this->assertSame([['path' => '/', 'message' => 'must have at least 1 item(s)']], $this->problems($schema, []));
        $this->assertSame([['path' => '/', 'message' => 'must have at most 2 items']], $this->problems($schema, ['a', 'b', 'c']));
        $this->assertSame([['path' => '/', 'message' => 'must not contain duplicates']], $this->problems($schema, ['a', 'a']));
        $this->assertSame([['path' => '/1', 'message' => 'must be of type string']], $this->problems($schema, ['a', 5]));
    }

    public function test_objects_report_nested_json_pointer_paths(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['run'],
            'properties' => [
                'run' => [
                    'type' => 'object',
                    'required' => ['mode'],
                    'properties' => ['mode' => ['enum' => ['http', 'exec']]],
                ],
            ],
        ];

        $this->assertSame([['path' => '/run', 'message' => 'is required']], $this->problems($schema, []));
        $this->assertSame([['path' => '/run/mode', 'message' => 'is required']], $this->problems($schema, ['run' => ['other' => 1]]));
        $this->assertSame([['path' => '/run/mode', 'message' => 'must be one of: "http", "exec"']], $this->problems($schema, ['run' => ['mode' => 'cgi']]));
        $this->assertSame([['path' => '/extra', 'message' => 'is not a known field']], $this->problems($schema, ['run' => ['mode' => 'exec'], 'extra' => true]));
    }

    public function test_additional_properties_schema_property_names_and_counts(): void
    {
        $schema = [
            'type' => 'object',
            'minProperties' => 1,
            'maxProperties' => 2,
            'propertyNames' => ['type' => 'string', 'pattern' => '^[A-Z_]+$'],
            'additionalProperties' => ['type' => 'string'],
        ];

        $this->assertSame([], $this->problems($schema, ['A' => 'x']));
        $this->assertSame([['path' => '/', 'message' => 'must have at least 1 entry']], $this->problems($schema, []));
        $this->assertSame([['path' => '/', 'message' => 'must have at most 2 entries']], $this->problems($schema, ['A' => 'x', 'B' => 'y', 'C' => 'z']));
        $this->assertSame([['path' => '/lower', 'message' => 'is not an allowed name']], $this->problems($schema, ['lower' => 'x']));
        $this->assertSame([['path' => '/A', 'message' => 'must be of type string']], $this->problems($schema, ['A' => 1]));
    }

    public function test_one_of_and_refs(): void
    {
        $schema = [
            '$defs' => ['name' => ['type' => 'string', 'pattern' => '^[a-z]+$']],
            'type' => 'object',
            'properties' => [
                'file' => ['$ref' => '#/$defs/name'],
                'value' => ['oneOf' => [['type' => 'string'], ['type' => 'object', 'required' => ['http']]]],
            ],
        ];

        $this->assertSame([], $this->problems($schema, ['file' => 'main', 'value' => 'x']));
        $this->assertSame([], $this->problems($schema, ['value' => ['http' => 'x']]));
        $this->assertSame([['path' => '/file', 'message' => 'must match ^[a-z]+$']], $this->problems($schema, ['file' => 'Main']));
        $this->assertSame([['path' => '/value', 'message' => 'does not match any allowed form']], $this->problems($schema, ['value' => 5]));
    }

    public function test_property_names_with_slashes_are_escaped_in_paths(): void
    {
        $schema = ['type' => 'object', 'additionalProperties' => ['type' => 'string']];

        $this->assertSame([['path' => '/a~1b', 'message' => 'must be of type string']], $this->problems($schema, ['a/b' => 1]));
    }
}
