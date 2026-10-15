<?php

namespace App\Mcp\Hosting;

use App\Services\Runtimes\InvalidManifestException;
use App\Services\Runtimes\ManifestValidator;
use App\Services\Runtimes\RuntimeManifest;

/**
 * The MCP server starters (docs/mcp.md, "Hosting MCP servers"): runtime plugins whose template is
 * a ready MCP server — examples/mcp/<template>/runtime.yaml with the starter files next to it.
 * template.files values and dependencies.template may be "@<file>" references, inlined here.
 * The API image does not contain examples/, so the starters also ship as
 * resources/mcp/templates.json (`php artisan mcp:templates-bundle`; a test keeps both identical);
 * where examples/mcp is present (repository checkout) it is read directly.
 */
final class McpTemplates
{
    /** Template id => directory under examples/mcp, in the order the console shows them. */
    public const TEMPLATES = ['python' => 'python', 'node' => 'node'];

    /** @var array<string, array>|null */
    private static ?array $loaded = null;

    public function __construct(private readonly ManifestValidator $validator) {}

    public static function bundlePath(): string
    {
        return resource_path('mcp/templates.json');
    }

    public static function sourceDir(): ?string
    {
        $dir = base_path('../examples/mcp');

        return is_dir($dir) && glob($dir.'/*/runtime.yaml') ? $dir : null;
    }

    public static function flush(): void
    {
        self::$loaded = null;
    }

    /**
     * @return array<string, array{id: string, runtime: string, name: string, description: ?string, manifest: array}>
     */
    public function all(): array
    {
        if (self::$loaded !== null) {
            return self::$loaded;
        }

        $raw = self::sourceDir() ? $this->readSources(self::sourceDir()) : $this->readBundle();

        $templates = [];
        foreach ($raw as $id => $manifest) {
            $templates[$id] = [
                'id' => $id,
                'runtime' => (string) ($manifest['id'] ?? ''),
                'name' => (string) ($manifest['name'] ?? $id),
                'description' => isset($manifest['description']) ? (string) $manifest['description'] : null,
                'manifest' => $manifest,
            ];
        }

        return self::$loaded = $templates;
    }

    public function get(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /** The validated manifest of a template (template files rendered by it). */
    public function manifest(string $id): ?RuntimeManifest
    {
        $template = $this->get($id);

        return $template ? $this->validator->validate($template['manifest']) : null;
    }

    /** The files a new project of the template starts with: entry file and dependency file. */
    public function files(string $id): array
    {
        $manifest = $this->manifest($id);

        return $manifest ? $manifest->templateFiles($manifest->defaultFilename(), $manifest->handlerFunction(), true) : [];
    }

    /** The manifest as JSON text, for POST /api/runtimes {manifest}. */
    public function manifestText(string $id): ?string
    {
        $template = $this->get($id);

        return $template ? (string) json_encode($template['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * Manifests read from examples/mcp/<dir>/runtime.yaml with "@file" references inlined.
     *
     * @return array<string, array>
     */
    public function readSources(string $dir): array
    {
        $out = [];
        foreach (self::TEMPLATES as $id => $sub) {
            $file = "{$dir}/{$sub}/runtime.yaml";
            if (! is_file($file)) {
                continue;
            }
            $data = $this->validator->decode(self::normalizeNewlines((string) file_get_contents($file)));

            foreach ($data['template']['files'] ?? [] as $name => $content) {
                $data['template']['files'][$name] = $this->inline((string) $content, dirname($file), '/template/files');
            }
            if (isset($data['dependencies']['template']) && is_string($data['dependencies']['template'])) {
                $data['dependencies']['template'] = $this->inline($data['dependencies']['template'], dirname($file), '/dependencies/template');
            }

            $this->validator->validate($data);
            $out[$id] = $data;
        }

        return $out;
    }

    /** JSON text of resources/mcp/templates.json for the given source directory. */
    public function bundleJson(string $dir): string
    {
        return json_encode([
            '_comment' => 'Generated from examples/mcp/*/runtime.yaml by `php artisan mcp:templates-bundle` — edit the sources, then re-run it.',
            'templates' => $this->readSources($dir),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /** @return array<string, array> */
    private function readBundle(): array
    {
        $path = self::bundlePath();
        if (! is_file($path)) {
            return [];
        }

        $bundle = json_decode((string) file_get_contents($path), true);

        return is_array($bundle['templates'] ?? null) ? $bundle['templates'] : [];
    }

    private function inline(string $value, string $dir, string $path): string
    {
        if (! str_starts_with($value, '@')) {
            return $value;
        }

        $file = $dir.'/'.substr($value, 1);
        if (! is_file($file)) {
            throw new InvalidManifestException([['path' => $path, 'message' => "references {$value}, which does not exist next to the manifest"]]);
        }

        return self::normalizeNewlines((string) file_get_contents($file));
    }

    private static function normalizeNewlines(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }
}
