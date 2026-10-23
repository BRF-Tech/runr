<?php

namespace App\Services\Wasm;

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\UploadedImage;
use App\Models\Version;
use App\Services\FunctionBuildService;
use App\Services\GitService;
use App\Services\Images\ImageOwner;
use App\Services\Images\UploadedImages;
use App\Services\KubernetesClient;
use App\Services\Manifests\BuildManifests;
use App\Services\Runtimes\Placeholders;
use App\Services\Runtimes\ProjectEntrypoint;
use App\Services\Runtimes\RuntimeManifest;
use Illuminate\Support\Facades\Log;

/**
 * Build of a WebAssembly function version (FunctionBuildService hands Wasm runtimes over here):
 * a Job in the project's region runs the runtime's build image — the language toolchain plus the
 * Spin CLI — on the project's files and pushes the result to the region registry as a Spin OCI
 * artifact (`spin registry push`: the locked app as config layer, every component as an
 * application/vnd.wasm.content.layer.v1+wasm layer), `runr-builds/<project>:v<N>` as for every
 * other build.
 *
 * Why an OCI artifact and not an image FROM scratch with spin.toml: containerd-shim-spin
 * precompiles the Wasm layers of an OCI artifact once per node and keeps the native code in
 * containerd's content store, so a cold start loads compiled code; an image with spin.toml in its
 * filesystem is compiled again on every start.
 *
 * The Job has the name, labels and lifecycle of the Kaniko build Job (CheckBuildReadyJob
 * follows it, registry retention trims the tags): an init container lays out the files from a
 * ConfigMap, the `build` container runs `build.command` in /workspace/code — the manifest's
 * build.files (Cargo.toml, spin.toml, ...) first, the project's own files over them, so a FaaS
 * project can bring its own spin.toml — and pushes to $RUNR_BUILD_IMAGE.
 */
class WasmBuildService
{
    public function __construct(private GitService $git) {}

    public function submitBuild(Version $version, RuntimeManifest $manifest, ExecutorImage $executor, string $versionImage): void
    {
        $project = $version->project;
        $region = $project->knativeRegion;

        $version->update(['build_status' => 'building']);

        $imageRef = $this->submitJob($project, $version, $region, $manifest, $executor, $versionImage, "build-{$project->slug}-v{$version->number}");

        $version->update(['build_image' => $imageRef]);

        Log::info('WebAssembly build submitted', [
            'project' => $project->slug,
            'runtime' => $manifest->id(),
            'version' => $version->number,
            'image' => $imageRef,
        ]);
    }

    /**
     * The build Job of $version named $name in $region's project namespace, pushing to $region's
     * registry; the version row is not touched. $project is the project as $region sees it (its
     * region set to $region): the version's own build, or its build into another region's registry
     * (FunctionBuildService::submitReplicaBuild, docs/environments.md#another-region).
     *
     * @return string the image reference the Job pushes
     */
    public function submitJob(Project $project, Version $version, KnativeRegion $region, RuntimeManifest $manifest, ExecutorImage $executor, string $versionImage, string $name): string
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $namespace = $region->project_namespace ?? $region->name;
        $projectFiles = $this->git->getAllFilesAtVersion($project, $version->commit_id);
        // an uploaded module instead of a compile (docs/wasm.md#wasm-exec), the generated spin.toml of WAGI runtimes
        $module = $this->moduleRef($project, $manifest, $executor, $versionImage, $projectFiles);
        $files = $this->files($manifest, $projectFiles, $project, $executor);
        $imageRef = app(FunctionBuildService::class)->buildImageRef($region, $project, $version);

        // Job spec.template is immutable: stale objects of an earlier attempt go first
        $k8s->deleteResource('batch/v1', 'Job', $name, $namespace);
        $k8s->deleteResource('v1', 'ConfigMap', $name, $namespace);

        $data = [];
        foreach ($files as $file => $content) {
            $data["code---{$file}"] = $content;
        }

        $k8s->createResource($this->sourceConfigMap($name, $namespace, $project, $data), $namespace);
        $k8s->createResource($this->job($name, $namespace, $project, $manifest, $executor, $versionImage, array_keys($files), $imageRef, $module), $namespace);

        return $imageRef;
    }

    /**
     * The files of the build: the manifest's build.files, the project's files over them. A WAGI
     * runtime (`wagi`) gets the generated spin.toml unless the project brings its own.
     *
     * @param  array<string, string>  $projectFiles
     * @return array<string, string>
     */
    public function files(RuntimeManifest $manifest, array $projectFiles, ?Project $project = null, ?ExecutorImage $executor = null): array
    {
        $files = [];
        foreach ($manifest->build()['files'] ?? [] as $name => $content) {
            $files[$name] = is_array($content) ? $content['http'] : $content;
        }

        if (($wagi = $manifest->wagi()) !== null && $project !== null && ! array_key_exists('spin.toml', $projectFiles)) {
            $files['spin.toml'] = $this->wagiSpinToml($project, $manifest, $executor, $wagi, array_keys($projectFiles));
        }

        return array_merge($files, $projectFiles);
    }

    /**
     * spin.toml of a WAGI function (WagiSpinManifest): main.wasm on the WAGI executor, the
     * project's files mapped when the runtime says so, the RUNR_* contract as static environment.
     *
     * @param  array{argv: string, files: bool, environment: array<string, string>}  $wagi
     * @param  list<string>  $fileNames
     */
    public function wagiSpinToml(Project $project, RuntimeManifest $manifest, ?ExecutorImage $executor, array $wagi, array $fileNames): string
    {
        $entrypoint = ProjectEntrypoint::for($project, $manifest);
        $version = (string) ($executor?->version ?? $project->runtime_version ?? '');

        return WagiSpinManifest::render((string) $project->slug, $wagi, $fileNames, [
            'version' => $version,
            'file' => $entrypoint->filename,
            'handler' => $entrypoint->handler,
            'mode' => 'http',
            'trigger' => 'http',
        ], [
            'RUNR_RUNTIME' => $manifest->id(),
            'RUNR_RUNTIME_VERSION' => $version,
            'RUNR_HANDLER' => $entrypoint->handler,
            'RUNR_FILENAME' => $entrypoint->filename,
        ]);
    }

    /**
     * The uploaded WebAssembly module the build packages instead of compiling (RUNR_WASM_MODULE):
     * the version image of an `upload:` version (an interpreter shipped as a runtime), else the
     * module a WAGI project names in its wasm.upload file (repository[:tag] or an upload id of the
     * organization, in the project's region). Null when the build compiles the project.
     *
     * @param  array<string, string>  $projectFiles
     *
     * @throws \RuntimeException wasm.upload names nothing usable
     */
    public function moduleRef(Project $project, RuntimeManifest $manifest, ExecutorImage $executor, string $versionImage, array $projectFiles): ?string
    {
        if (($manifest->version($executor->version)['upload'] ?? null) !== null) {
            return $versionImage;
        }
        if ($manifest->wagi() === null || ! isset($projectFiles['wasm.upload'])) {
            return null;
        }

        // the first line that is not empty or a # comment
        $reference = (string) collect(preg_split('/\R/', (string) $projectFiles['wasm.upload']) ?: [])
            ->map(fn ($l) => trim((string) $l))
            ->first(fn ($l) => $l !== '' && ! str_starts_with($l, '#'), '');
        $region = $project->knativeRegion;
        $image = $region && $reference !== '' ? app(UploadedImages::class)->resolve($reference, ImageOwner::currentKey(), (string) $region->id) : null;

        if (! $image) {
            throw new \RuntimeException(__('images.wasm_upload_unknown', ['reference' => $reference, 'region' => $region?->name ?? '-']));
        }
        if ($image->kind !== UploadedImage::KIND_WASM) {
            throw new \RuntimeException(__('images.wasm_upload_kind', ['reference' => $reference, 'kind' => $image->kind]));
        }

        return $image->imageRef($region);
    }

    /**
     * The build container's script: the dependency install (when the project has the runtime's
     * dependency file), build.command, then the push of the Spin app.
     *
     * @param  list<string>  $fileNames  the files of the build
     */
    public function script(Project $project, RuntimeManifest $manifest, ExecutorImage $executor, string $versionImage, array $fileNames = []): string
    {
        $entrypoint = ProjectEntrypoint::for($project, $manifest);
        $values = [
            'version' => $executor->version,
            'image' => $versionImage,
            'file' => $entrypoint->filename,
            'handler' => $entrypoint->handler,
            'mode' => 'http',
            'trigger' => 'http',
        ];
        $deps = $manifest->dependencies();
        $install = $deps !== null && in_array($deps['file'], $fileNames, true) ? Placeholders::render($deps['install'], $values) : null;

        return implode("\n", array_values(array_filter([
            'set -e',
            'cd /workspace/code',
            'echo "runr: building $RUNR_BUILD_IMAGE"',
            $install,
            Placeholders::render((string) ($manifest->build()['command'] ?? 'spin build'), $values),
            // plain HTTP to the region registry (as Kaniko's --insecure); anonymous push
            'spin registry push --insecure --cache-dir /tmp/spin-cache "$RUNR_BUILD_IMAGE"',
            'echo "runr: pushed $RUNR_BUILD_IMAGE"',
        ])));
    }

    /**
     * @param  list<string>  $fileNames
     */
    public function job(string $name, string $ns, Project $project, RuntimeManifest $manifest, ExecutorImage $executor, string $versionImage, array $fileNames, string $imageRef, ?string $module = null): array
    {
        $region = $project->knativeRegion;
        // build.image (default: the version image); an uploaded module is fetched by the toolchain
        $buildImage = Placeholders::render((string) ($manifest->build()['image'] ?? '{image}'), [
            'version' => $executor->version,
            'image' => $versionImage,
        ]);

        $copy = ['mkdir -p /workspace/code'];
        foreach ($fileNames as $f) {
            $copy[] = "cp \"/src/code---{$f}\" \"/workspace/code/{$f}\"";
        }

        $runtimeClass = $region?->resolveBuildRuntimeClass();

        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => [
                'name' => $name,
                'namespace' => $ns,
                'labels' => BuildManifests::labels($project) + ['runr.dev/build-kind' => 'wasm'],
                'annotations' => ['runr.sh/project-id' => $project->id],
            ],
            'spec' => [
                'activeDeadlineSeconds' => BuildManifests::deadlineSeconds(),
                'backoffLimit' => 0,
                'ttlSecondsAfterFinished' => 300,
                'template' => [
                    'metadata' => ['labels' => BuildManifests::labels($project)],
                    'spec' => array_filter([
                        'restartPolicy' => 'Never',
                        'dnsPolicy' => 'ClusterFirst',
                        'dnsConfig' => [
                            'searches' => ['svc.cluster.local', 'cluster.local'],
                            'options' => [['name' => 'ndots', 'value' => '2']],
                        ],
                        'runtimeClassName' => $runtimeClass,
                        'initContainers' => [[
                            'name' => 'copy-src',
                            'image' => BuildManifests::INIT_IMAGE,
                            'command' => ['sh', '-c', implode(' && ', $copy)],
                            'volumeMounts' => [
                                ['name' => 'src', 'mountPath' => '/src', 'readOnly' => true],
                                ['name' => 'workspace', 'mountPath' => '/workspace'],
                            ],
                            'resources' => BuildManifests::initResources(),
                        ]],
                        'containers' => [[
                            'name' => 'build',
                            'image' => $region ? $region->registryImageRef($buildImage) : $buildImage,
                            'command' => ['sh', '-c', $this->script($project, $manifest, $executor, $versionImage, $fileNames)],
                            'env' => array_values(array_filter([
                                ['name' => 'RUNR_BUILD_IMAGE', 'value' => $imageRef],
                                ['name' => 'HOME', 'value' => '/tmp/home'],
                                ['name' => 'RUNR_RUNTIME', 'value' => $manifest->id()],
                                // the uploaded module (docs/images.md), pulled from the region registry over plain HTTP
                                $module !== null ? ['name' => 'RUNR_WASM_MODULE', 'value' => $module] : null,
                            ])),
                            'volumeMounts' => [['name' => 'workspace', 'mountPath' => '/workspace']],
                            'resources' => BuildManifests::kanikoResources(),
                            // the last lines of a failed build are the Job's error (CheckBuildReadyJob)
                            'terminationMessagePolicy' => 'FallbackToLogsOnError',
                        ]],
                        'volumes' => [
                            ['name' => 'src', 'configMap' => ['name' => $name]],
                            ['name' => 'workspace', 'emptyDir' => new \stdClass],
                        ],
                    ], fn ($v) => $v !== null),
                ],
            ],
        ];
    }

    private function sourceConfigMap(string $name, string $ns, Project $project, array $data): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => [
                'name' => $name,
                'namespace' => $ns,
                'labels' => ['runr/build' => 'true', 'runr/project-id' => $project->id],
                'annotations' => ['runr.sh/project-id' => $project->id],
            ],
            'data' => $data,
        ];
    }
}
