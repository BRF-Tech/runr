<?php

namespace App\Http\Resources;

use App\Models\ExecutorImage;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\RuntimeSource;
use App\Services\Runtimes\RuntimeLifecycle;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Wasm\WasmRuntimes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\Yaml\Yaml;

/**
 * A runtime of the catalog: what the runtime picker, the editor and Settings → Runtimes need.
 * With detail() the normalized manifest, the manifest as submitted and the uploaded files too.
 *
 * @mixin Runtime
 */
class RuntimeResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Runtime $runtime */
        $runtime = $this->resource;
        $manifest = app(RuntimeRegistry::class)->manifest($runtime->id);
        $versions = ExecutorImage::where('runtime', $runtime->id)->where('is_active', true)->orderBy('sort_order')->get();

        $data = [
            'id' => $runtime->id,
            'name' => $manifest?->name() ?? $runtime->name,
            'description' => $manifest?->description(),
            'icon' => $manifest?->icon(),
            'language' => $manifest?->language() ?? 'plaintext',
            'extensions' => $manifest?->extensions() ?? [],
            'category' => $manifest?->category() ?? 'general',
            'source' => $runtime->source,
            'run_mode' => $manifest?->runMode(),
            'compiled' => (bool) $manifest?->isCompiled(),
            'triggers' => $manifest?->triggers() ?? [],
            'sandbox' => $manifest?->sandbox() ?? [],
            'runtime_class' => $manifest?->runtimeClass(),
            // WebAssembly runtimes: the shim they need in a region (null for every other runtime)
            'wasm' => WasmRuntimes::shimOf($manifest)?->id,
            'gpu' => $manifest?->gpu(),
            'handler' => $manifest ? [
                'file' => $manifest->handlerFile(),
                'function' => $manifest->handlerFunction(),
                'filename' => $manifest->defaultFilename(),
                'pattern' => $manifest->toArray()['handler']['pattern'],
            ] : null,
            'dependency_file' => $manifest?->dependencyFile(),
            'versions' => $versions->map(function (ExecutorImage $v) use ($manifest) {
                $spec = $manifest?->version($v->version);

                return [
                    'version' => $v->version,
                    'is_default' => (bool) $v->is_default,
                    'eol_at' => $v->eol_at?->toDateString(),
                    // Lifecycle (docs/runtimes.md#lifecycle): deprecated, sunset, the date the version ends
                    'deprecated_at' => $v->deprecated_at?->toDateString(),
                    'sunset_at' => $v->sunset_at?->toDateString(),
                    'ends_at' => $v->endsAt()?->toDateString(),
                    'lifecycle' => RuntimeLifecycle::version($v)['state'],
                    'gpu_required' => (bool) $v->gpu_required,
                    'image' => $v->docker_image !== '' ? $v->docker_image : null,
                    // an uploaded image of the runtime's owner (docs/images.md), resolved per region
                    'upload' => $spec['upload'] ?? null,
                    'built' => $spec !== null && $spec['image'] === null && ($spec['upload'] ?? null) === null,
                ];
            })->values(),
            'regions' => $runtime->regions,
            'is_enabled' => (bool) $runtime->is_enabled,
            // Runtime sources (docs/runtimes.md#runtime-sources): where the runtime comes from
            'source_id' => $runtime->source_id,
            'source_name' => $runtime->source_id ? self::sourceName($runtime->source_id) : null,
            'source_path' => $runtime->source_path,
            // synced from a connected source (not RunR's catalog): its manifest changes there
            'managed' => $runtime->isSourceManaged(),
            // active | deprecated | sunset | orphaned, with the dates, replacement and notice
            'lifecycle' => RuntimeLifecycle::runtime($runtime, $manifest),
            'tenant_id' => $runtime->tenant_id,
            'created_by' => $runtime->created_by,
            'projects_count' => Project::where('runtime', $runtime->id)->count(),
            'updated_at' => $runtime->updated_at,
        ];

        if ($this->detail) {
            $data['manifest'] = $manifest?->toArray();
            // Built-ins: the release manifest, rendered back to YAML
            $data['manifest_source'] = $runtime->manifest_source
                ?? ($manifest ? Yaml::dump($manifest->toArray(), 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK) : null);
            $data['files'] = (object) ($runtime->files ?? []);
        }

        return $data;
    }

    /** @var array<string, string|null> */
    private static array $sourceNames = [];

    private static function sourceName(string $id): ?string
    {
        if (! array_key_exists($id, self::$sourceNames)) {
            if (count(self::$sourceNames) > 200) {
                self::$sourceNames = [];
            }
            self::$sourceNames[$id] = RuntimeSource::query()->whereKey($id)->value('name');
        }

        return self::$sourceNames[$id];
    }
}
