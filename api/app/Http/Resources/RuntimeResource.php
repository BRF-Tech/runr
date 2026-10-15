<?php

namespace App\Http\Resources;

use App\Models\ExecutorImage;
use App\Models\Project;
use App\Models\Runtime;
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
                    'gpu_required' => (bool) $v->gpu_required,
                    'image' => $v->docker_image !== '' ? $v->docker_image : null,
                    // an uploaded image of the runtime's owner (docs/images.md), resolved per region
                    'upload' => $spec['upload'] ?? null,
                    'built' => $spec !== null && $spec['image'] === null && ($spec['upload'] ?? null) === null,
                ];
            })->values(),
            'regions' => $runtime->regions,
            'is_enabled' => (bool) $runtime->is_enabled,
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
}
