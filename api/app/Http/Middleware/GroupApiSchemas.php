<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Post-processes Scramble's JSON spec:
 *  1. Normalize operation tags into consistent groups
 *  2. Group schemas: FooRequest → Request.Foo, FooResource → Response.Foo
 */
class GroupApiSchemas
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $spec = json_decode($response->getContent(), true);
        if (! $spec) {
            return $response;
        }

        NormalizeApiTags::normalizeSpecArray($spec);
        $this->sanitizeEmptySchemas($spec);
        $this->groupSchemas($spec);

        $response->setContent(json_encode($spec));

        return $response;
    }

    /**
     * Fix empty schemas ({} → [] in PHP) that break Scalar UI.
     */
    private function sanitizeEmptySchemas(array &$spec): void
    {
        $this->walkSchema($spec);
    }

    private function walkSchema(array &$node): void
    {
        // Fix empty property schemas: "prop": {} (decoded as []) → {"type":"string"}
        if (isset($node['properties']) && is_array($node['properties'])) {
            foreach ($node['properties'] as &$propSchema) {
                if (is_array($propSchema) && empty($propSchema)) {
                    $propSchema = ['type' => 'string'];
                }
            }
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
                $this->walkSchema($value);
            }
        }
    }

    private function groupSchemas(array &$spec): void
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
