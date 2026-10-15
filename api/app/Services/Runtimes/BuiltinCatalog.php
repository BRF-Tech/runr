<?php

namespace App\Services\Runtimes;

/**
 * The built-in runtime catalog: runtime/<id>/runtime.yaml next to each runtime's Dockerfile and
 * wrapper. The API image does not contain runtime/, so the catalog is also shipped as
 * resources/runtimes/builtin.json (php artisan runtimes:bundle; a test keeps both identical).
 * Where runtime/ is present (repository checkout, docker compose mounts it at ../runtime) it is
 * read directly.
 */
class BuiltinCatalog
{
    /** @var array<string, RuntimeManifest>|null */
    private static ?array $loaded = null;

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
     * Built-in manifests by id, in catalog order.
     *
     * @return array<string, RuntimeManifest>
     */
    public function all(): array
    {
        if (self::$loaded !== null) {
            return self::$loaded;
        }

        $raw = self::sourceDir() ? $this->readSources(self::sourceDir()) : $this->readBundle();

        $manifests = [];
        foreach ($raw as $data) {
            $manifest = $this->validator->validate($data, [], true);
            $manifests[$manifest->id()] = $manifest;
        }

        return self::$loaded = $manifests;
    }

    public function get(string $id): ?RuntimeManifest
    {
        return $this->all()[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    /** Forget the loaded catalog (tests, runtimes:bundle). */
    public static function flush(): void
    {
        self::$loaded = null;
    }

    /**
     * The catalog as raw manifests read from runtime/<id>/runtime.yaml, "@file" references of
     * build.files inlined (paths relative to the manifest's directory).
     *
     * @return list<array>
     */
    public function readSources(string $dir): array
    {
        $order = ['python', 'nodejs', 'go', 'php', 'tensorflow', 'pytorch'];
        $files = glob("{$dir}/*/runtime.yaml");
        usort($files, function ($a, $b) use ($order) {
            $ia = array_search(basename(dirname($a)), $order, true);
            $ib = array_search(basename(dirname($b)), $order, true);

            return [$ia === false ? PHP_INT_MAX : $ia, $a] <=> [$ib === false ? PHP_INT_MAX : $ib, $b];
        });

        $manifests = [];
        foreach ($files as $file) {
            $data = $this->validator->decode(self::normalizeNewlines(file_get_contents($file)));

            foreach ($data['build']['files'] ?? [] as $name => $content) {
                $data['build']['files'][$name] = is_array($content)
                    ? array_map(fn ($c) => $this->inline($c, dirname($file)), $content)
                    : $this->inline($content, dirname($file));
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
            '_comment' => 'Generated from runtime/*/runtime.yaml by `php artisan runtimes:bundle` — edit the YAML, then re-run it.',
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
