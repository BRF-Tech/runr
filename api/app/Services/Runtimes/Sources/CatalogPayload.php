<?php

namespace App\Services\Runtimes\Sources;

/**
 * Schema 1 of a runtime catalog (docs/runtimes.md#oci-catalogs) — the layer of an OCI artifact of
 * media type application/vnd.runr.runtime-catalog.v1+json, as BRF-Tech/runr-runtimes publishes
 * RunR's default catalog (ci.sh catalog) and any source can publish its own:
 *
 *   {"schema": 1, "generated_at": "…",
 *    "source": {"repository": "…", "revision": "…"},
 *    "runtimes": [{"path": "runtimes/<id>/runtime.yaml", "manifest": {…}, "files": {"name": "content"}}],
 *    "patches": [patch-feed v1 entries of the catalog's own runtimes]}
 *
 * A catalog whose frame is wrong is refused as a whole (the last good read stays); a runtime entry
 * that is wrong is a problem of that runtime only.
 */
final class CatalogPayload
{
    public const MEDIA_TYPE = 'application/vnd.runr.runtime-catalog.v1+json';

    public const VERSION = 1;

    /**
     * The entries of a valid catalog, or the reason it is refused.
     *
     * @return list<array{path: string, data: array|null, files: array<string, string>, problem: string|null}>
     *
     * @throws RuntimeSourceException
     */
    public static function entries(mixed $data): array
    {
        if (! is_array($data) || array_is_list($data)) {
            throw RuntimeSourceException::invalid('The catalog is not a JSON object');
        }
        if (($data['schema'] ?? null) !== self::VERSION) {
            throw RuntimeSourceException::invalid('The catalog is not schema '.self::VERSION.' ("schema": '.json_encode($data['schema'] ?? null).')');
        }
        if (! is_string($data['generated_at'] ?? null) || strtotime($data['generated_at']) === false) {
            throw RuntimeSourceException::invalid('The catalog has no generated_at date');
        }
        if (! is_array($data['runtimes'] ?? null) || ! array_is_list($data['runtimes'])) {
            throw RuntimeSourceException::invalid('The catalog has no runtimes list');
        }
        $max = (int) config('runtime_sources.max_runtimes', 200);
        if (count($data['runtimes']) > $max) {
            throw RuntimeSourceException::invalid("The catalog lists more than {$max} runtimes");
        }
        if (isset($data['patches']) && (! is_array($data['patches']) || ! array_is_list($data['patches']))) {
            throw RuntimeSourceException::invalid('The catalog\'s patches are not a list');
        }

        $entries = [];
        foreach ($data['runtimes'] as $i => $entry) {
            $path = is_array($entry) && is_string($entry['path'] ?? null) && $entry['path'] !== '' ? mb_substr($entry['path'], 0, 255) : "runtimes[{$i}]";
            $manifest = is_array($entry) ? ($entry['manifest'] ?? null) : null;
            $files = is_array($entry) ? ($entry['files'] ?? []) : [];

            $problem = match (true) {
                ! is_array($entry) => 'is not an object',
                ! is_array($manifest) || ($manifest !== [] && array_is_list($manifest)) => 'has no manifest object',
                ! is_array($files) || ($files !== [] && array_is_list($files)) => 'files must be an object of file contents',
                default => null,
            };
            if ($problem === null) {
                foreach ($files as $name => $content) {
                    if (! is_string($content) || ! preg_match('/^[A-Za-z0-9._-]{1,128}$/', (string) $name)) {
                        $problem = 'files must map file names to their contents';
                        break;
                    }
                }
            }

            $entries[] = [
                'path' => $path,
                'data' => $problem === null ? $manifest : null,
                'files' => $problem === null ? array_map('strval', $files) : [],
                'problem' => $problem,
            ];
        }

        return $entries;
    }

    /** @return list<array>|null the catalog's patch entries (validated as a patch feed later) */
    public static function patches(array $data): ?array
    {
        return isset($data['patches']) && is_array($data['patches']) ? array_values($data['patches']) : null;
    }
}
