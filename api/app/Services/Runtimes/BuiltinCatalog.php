<?php

namespace App\Services\Runtimes;

use App\Models\Runtime;
use App\Models\RuntimeSource;

/**
 * The built-in runtime catalog: the runtimes of RunR's default source (docs/runtimes.md#runtime-sources),
 * BRF-Tech/runr-runtimes' signed catalog ghcr.io/brf-tech/runr/runtime-catalog:stable, read
 * periodically by RuntimeSourceSync into the runtimes table (source builtin, the manifest on each
 * row). Until that catalog has been read once — an air-gapped installation, RUNR_RUNTIME_CATALOG_REF
 * set empty, the default source disabled — the catalog the API image ships is in use:
 * resources/runtimes/builtin.json (php artisan runtimes:bundle from runtimes/<id>/runtime.yaml of
 * a runr-runtimes checkout, RUNR_RUNTIME_CATALOG), or those sources directly where
 * executor.catalog_path names them (development). mode() says which.
 *
 * Bound scoped: the default source's manifests are read once per request / job; the parsed bundle
 * is shared by the process (flush() forgets both).
 */
class BuiltinCatalog
{
    public const MODE_SOURCE = 'source';

    public const MODE_BUNDLE = 'bundle';

    /** Catalog order of the first runtimes; the rest follow by id */
    private const ORDER = ['python', 'nodejs', 'go', 'php', 'tensorflow', 'pytorch'];

    /** @var array<string, RuntimeManifest>|null the bundle (or the source directory), parsed */
    private static ?array $bundled = null;

    /** Bumped by flush(): every instance reads anew */
    private static int $generation = 0;

    /** @var array<string, RuntimeManifest>|null */
    private ?array $loaded = null;

    private string $mode = self::MODE_BUNDLE;

    private int $loadedGeneration = -1;

    public function __construct(private ManifestValidator $validator) {}

    public static function bundlePath(): string
    {
        return resource_path('runtimes/builtin.json');
    }

    /** runtime/ of the repository, when this checkout has it. */
    public static function sourceDir(): ?string
    {
        $dir = config('executor.catalog_path') ?: base_path('../runtime');

        return is_dir($dir) && glob(rtrim($dir, '/').'/*/runtime.yaml') ? rtrim($dir, '/') : null;
    }

    /**
     * Built-in manifests by id, in catalog order: the default source's once it has been read, else
     * the bundle.
     *
     * @return array<string, RuntimeManifest>
     */
    public function all(): array
    {
        if ($this->loaded !== null && $this->loadedGeneration === self::$generation) {
            return $this->loaded;
        }
        $this->loadedGeneration = self::$generation;

        $fromSource = $this->fromDefaultSource();
        if ($fromSource !== null) {
            $this->mode = self::MODE_SOURCE;

            return $this->loaded = $fromSource;
        }

        $this->mode = self::MODE_BUNDLE;

        return $this->loaded = $this->bundled();
    }

    /** Which catalog is in use: source (RunR's catalog as last read) or bundle (the API image's). */
    public function mode(): string
    {
        $this->all();

        return $this->mode;
    }

    /**
     * The catalog the API image ships (or the source directory), whatever is in use: the offline
     * fallback, and the ids a runtime source cannot take.
     *
     * @return array<string, RuntimeManifest>
     */
    public function bundled(): array
    {
        if (self::$bundled !== null) {
            return self::$bundled;
        }

        $raw = self::sourceDir() ? $this->readSources(self::sourceDir()) : $this->readBundle();

        $manifests = [];
        foreach ($raw as $data) {
            $manifest = $this->validator->validate($data, [], true);
            $manifests[$manifest->id()] = $manifest;
        }

        return self::$bundled = $manifests;
    }

    /**
     * The manifests of the default source as last read, null while it has never been read (or it is
     * switched off): built-in rows of that source with a manifest that are still in it.
     *
     * @return array<string, RuntimeManifest>|null
     */
    private function fromDefaultSource(): ?array
    {
        try {
            $source = RuntimeSource::existingDefault();
            if (! $source || ! $source->is_enabled || ! $source->configured() || $source->synced_at === null) {
                return null;
            }

            $rows = Runtime::query()
                ->where('source', Runtime::SOURCE_BUILTIN)
                ->where('source_id', $source->id)
                ->whereNull('orphaned_at')
                ->whereNotNull('manifest')
                ->get(['id', 'manifest']);
        } catch (\Throwable) {
            return null;    // not migrated yet
        }
        if ($rows->isEmpty()) {
            return null;
        }

        $manifests = [];
        foreach ($rows->sortBy(fn (Runtime $r) => [array_search($r->id, self::ORDER, true) === false ? PHP_INT_MAX : array_search($r->id, self::ORDER, true), $r->id]) as $row) {
            $manifests[$row->id] = new RuntimeManifest((array) $row->manifest);
        }

        return $manifests;
    }

    public function get(string $id): ?RuntimeManifest
    {
        return $this->all()[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    /** Forget the loaded catalog everywhere in the process (tests, runtimes:bundle, a sync of the default source). */
    public static function flush(): void
    {
        self::$bundled = null;
        self::$generation++;
    }

    /**
     * The catalog as raw manifests read from <dir>/<id>/runtime.yaml, "@file" references of
     * build.files and of the versions' build.files inlined (paths relative to the manifest's
     * directory).
     *
     * @return list<array>
     */
    public function readSources(string $dir): array
    {
        $order = self::ORDER;
        $files = glob("{$dir}/*/runtime.yaml");
        usort($files, function ($a, $b) use ($order) {
            $ia = array_search(basename(dirname($a)), $order, true);
            $ib = array_search(basename(dirname($b)), $order, true);

            return [$ia === false ? PHP_INT_MAX : $ia, $a] <=> [$ib === false ? PHP_INT_MAX : $ib, $b];
        });

        $manifests = [];
        foreach ($files as $file) {
            $data = $this->validator->decode(self::normalizeNewlines(file_get_contents($file)));

            $inline = fn ($content) => is_array($content)
                ? array_map(fn ($c) => $this->inline($c, dirname($file)), $content)
                : $this->inline($content, dirname($file));
            foreach ($data['build']['files'] ?? [] as $name => $content) {
                $data['build']['files'][$name] = $inline($content);
            }
            foreach ($data['versions'] ?? [] as $i => $version) {
                foreach ($version['build']['files'] ?? [] as $name => $content) {
                    $data['versions'][$i]['build']['files'][$name] = $inline($content);
                }
            }

            $this->validator->validate($data, [], true);
            $manifests[] = $data;
        }

        return $manifests;
    }

    /** @return list<array> */
    private function readBundle(): array
    {
        $bundle = json_decode(file_get_contents(self::bundlePath()), true, 512, JSON_THROW_ON_ERROR);

        return $bundle['runtimes'];
    }

    /** JSON text of resources/runtimes/builtin.json for the given source directory. */
    public function bundleJson(string $dir): string
    {
        return json_encode([
            '_comment' => 'Generated from runtimes/*/runtime.yaml of BRF-Tech/runr-runtimes by `php artisan runtimes:bundle` (RUNR_RUNTIME_CATALOG) — edit the YAML there, then re-run it.',
            'runtimes' => $this->readSources($dir),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private function inline(string $value, string $dir): string
    {
        if (! str_starts_with($value, '@')) {
            return $value;
        }

        $path = $dir.'/'.substr($value, 1);
        if (! is_file($path)) {
            throw new InvalidManifestException([['path' => '/build/files', 'message' => "references {$value}, which does not exist next to the manifest"]]);
        }

        return self::normalizeNewlines(file_get_contents($path));
    }

    private static function normalizeNewlines(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }
}
