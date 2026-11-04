<?php

namespace App\Support;

use App\Http\Middleware\NormalizeApiTags;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API's OpenAPI document: what GET /api/docs.json answers and the API reference (/api/docs)
 * shows.
 *
 * Generating it (Scramble analyses the controller, form requests and resources of every API route)
 * takes longer than a request may run: in 0.6.4 /api/docs.json ended in a 500 at the 30 s
 * max_execution_time. The API image builds it once instead (`php artisan runr:api-docs`, a stage of
 * api/Dockerfile.prod) into path(), and the route serves that file. Without the file (a development
 * checkout: the compose stack mounts api/) the route generates the document on each request.
 */
final class ApiDocument
{
    /** The longest summary of an operation: the reference shows it on one line */
    public const SUMMARY_MAX_LENGTH = 80;

    /** The keys of an OpenAPI path item that hold an operation */
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** The built document (config scramble.document_path) */
    public static function path(): string
    {
        return (string) config('scramble.document_path');
    }

    /**
     * The document, generated now: Scramble's output (with the afterOpenApiGenerated hook of
     * AppServiceProvider) and finish().
     *
     * @return array<string, mixed>
     */
    public static function generate(): array
    {
        $generator = app(Generator::class);
        $spec = $generator(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));

        // As JSON and back, as the route's response was: an empty schema object becomes an empty
        // array, which finish() replaces
        return self::finish(json_decode(json_encode($spec, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    /** generate() as the bytes the route answers with */
    public static function json(): string
    {
        return json_encode(self::generate(), JSON_THROW_ON_ERROR);
    }

    /** GET /api/docs.json: the built document, or one generated for this request without it */
    public static function respond(Request $request): Response
    {
        $path = self::path();
        if (is_file($path)) {
            $response = new BinaryFileResponse($path, 200, ['Content-Type' => 'application/json'], true, null, true, true);
            // It changes only with the image: browsers keep it for 5 minutes, then ask with its ETag
            $response->setPublic();
            $response->setMaxAge(300);
            $response->isNotModified($request);

            return $response;
        }

        // A checkout without the built document (development): generated for this request and not
        // kept, so it always shows the code as it is now. A request may run out of
        // max_execution_time here; `php artisan runr:api-docs` builds the file (docs/development.md).
        return response(self::json(), 200, ['Content-Type' => 'application/json']);
    }

    /**
     * What RunR changes in Scramble's document:
     *  1. operation tags in their groups (NormalizeApiTags) and the tag list of the used ones
     *  2. empty schemas ({} decoded as []), which break the Scalar viewer, as strings
     *  3. schema names grouped: FooRequest → Request.Foo, FooResource → Response.Foo
     *  4. the server relative to the host the document is read from (/api), not the URL the
     *     document was generated at (the image's build has no host)
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public static function finish(array $spec): array
    {
        NormalizeApiTags::normalizeSpecArray($spec);
        self::walkSchema($spec);
        self::groupSchemas($spec);
        $spec['servers'] = [['url' => '/'.trim((string) config('scramble.api_path', 'api'), '/')]];

        return $spec;
    }

    /**
     * What breaks the reference's rules in a document (none: an empty list). Every operation has
     *  - a summary (the first paragraph of its controller method's PHPDoc): one line of at most
     *    SUMMARY_MAX_LENGTH characters, without an HTTP method, a path or a trailing period;
     *  - an operationId no other operation has (OpenAPI requires it; SDK generators name their
     *    methods after it): a method that serves two routes gets one per route from the routes'
     *    names, Scramble's `_0` suffixes are not unique;
     *  - one group of the reference, one of NormalizeApiTags::groups().
     * `runr:api-docs --check` applies them to the document the image builds with every module
     * (api/docker/api-docs.sh: api/Dockerfile.prod and CI), ApiDocsTest to the test app's.
     *
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    public static function problems(array $spec): array
    {
        $groups = NormalizeApiTags::groups();
        $problems = [];
        $operationIds = [];

        foreach ((array) ($spec['paths'] ?? []) as $path => $item) {
            foreach (array_intersect_key((array) $item, array_flip(self::METHODS)) as $method => $operation) {
                $operation = (array) $operation;
                $at = strtoupper($method).' '.$path;

                $summary = $operation['summary'] ?? null;
                if (! is_string($summary) || trim($summary) === '') {
                    $problems[] = "{$at}: no summary";
                } else {
                    if (preg_match('/[\r\n]/', $summary)) {
                        $problems[] = "{$at}: the summary is more than one line";
                    }
                    if (mb_strlen($summary) > self::SUMMARY_MAX_LENGTH) {
                        $problems[] = sprintf('%s: the summary is longer than %d characters (%d)', $at, self::SUMMARY_MAX_LENGTH, mb_strlen($summary));
                    }
                    if (preg_match('/\b(GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS)\b/', $summary)) {
                        $problems[] = "{$at}: the summary names an HTTP method";
                    }
                    // "/api/…", "…/plan", "{environment}"; not "Forgejo/Gitea" or "stdout / stderr"
                    if (preg_match('~(^|[\s…])/[\w{]|\{[\w.-]+\}~u', $summary)) {
                        $problems[] = "{$at}: the summary names a path";
                    }
                    if (str_ends_with(rtrim($summary), '.')) {
                        $problems[] = "{$at}: the summary ends with a period";
                    }
                }

                $operationId = $operation['operationId'] ?? null;
                if (! is_string($operationId) || $operationId === '') {
                    $problems[] = "{$at}: no operationId";
                } elseif (isset($operationIds[$operationId])) {
                    $problems[] = "{$at}: the operationId {$operationId} is {$operationIds[$operationId]}'s too";
                } else {
                    $operationIds[$operationId] = $at;
                }

                $tags = array_values((array) ($operation['tags'] ?? []));
                if (count($tags) !== 1) {
                    $problems[] = sprintf('%s: in %d groups of the reference, not one (%s)', $at, count($tags), implode(' | ', $tags));
                } elseif (! in_array($tags[0], $groups, true)) {
                    $problems[] = "{$at}: in the group {$tags[0]}, not one of NormalizeApiTags::groups()";
                }
            }
        }

        return $problems;
    }

    /** @param array<mixed> $node */
    private static function walkSchema(array &$node): void
    {
        // Fix empty property schemas: "prop": {} (decoded as []) → {"type":"string"}
        if (isset($node['properties']) && is_array($node['properties'])) {
            foreach ($node['properties'] as &$propSchema) {
                if (is_array($propSchema) && empty($propSchema)) {
                    $propSchema = ['type' => 'string'];
                }
            }
            unset($propSchema);
        }

        // Simplify anyOf/oneOf containing empty {} branches
        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (isset($node[$keyword]) && is_array($node[$keyword])) {
                $node[$keyword] = array_values(array_filter(
                    $node[$keyword],
                    fn ($branch) => ! is_array($branch) || count($branch) > 0,
                ));
                if (count($node[$keyword]) === 1) {
                    $single = $node[$keyword][0];
                    unset($node[$keyword]);
                    $node = array_merge($node, $single);
                }
            }
        }

        // Fix empty "items": {} in arrays
        if (isset($node['items']) && is_array($node['items']) && empty($node['items'])) {
            $node['items'] = ['type' => 'string'];
        }

        // Recurse into sub-nodes
        foreach ($node as &$value) {
            if (is_array($value) && ! empty($value)) {
                self::walkSchema($value);
            }
        }
    }

    /** @param array<string, mixed> $spec */
    private static function groupSchemas(array &$spec): void
    {
        if (empty($spec['components']['schemas'])) {
            return;
        }

        $renameMap = [];
        foreach (array_keys($spec['components']['schemas']) as $key) {
            if (str_ends_with($key, 'Request')) {
                $renameMap[$key] = 'Request.'.preg_replace('/Request$/', '', $key);
            } elseif (str_ends_with($key, 'Resource')) {
                $renameMap[$key] = 'Response.'.preg_replace('/Resource$/', '', $key);
            }
        }

        if (empty($renameMap)) {
            return;
        }

        $json = json_encode($spec);
        foreach ($renameMap as $old => $new) {
            $json = str_replace(
                ['"#\/components\/schemas\/'.$old.'"', '"#/components/schemas/'.$old.'"'],
                ['"#\/components\/schemas\/'.$new.'"', '"#/components/schemas/'.$new.'"'],
                $json
            );
        }

        $spec = json_decode($json, true);

        $newSchemas = [];
        foreach ($spec['components']['schemas'] as $key => $schema) {
            $newKey = $renameMap[$key] ?? $key;
            $schema['title'] = $newKey;
            $newSchemas[$newKey] = $schema;
        }
        ksort($newSchemas);
        $spec['components']['schemas'] = $newSchemas;
    }
}
