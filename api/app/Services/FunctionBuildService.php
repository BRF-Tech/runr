<?php

namespace App\Services;

use App\Models\ExecutorImage;
use App\Models\Project;
use App\Models\Version;
use App\Services\Manifests\BuildManifests;
use App\Services\Runtimes\Placeholders;
use App\Services\Runtimes\ProjectEntrypoint;
use App\Services\Runtimes\RuntimeContainerSpec;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Wasm\WasmBuildService;
use App\Services\Wasm\WasmRuntimes;
use Illuminate\Support\Facades\Log;

/**
 * Builds a function version into an image with Kaniko in the region (project namespace) and
 * pushes it to the region registry — for every runtime, driven by its manifest:
 *  - dependencies: a FaaS project that contains the dependency file (requirements.txt, ...)
 *    installs them in the install image and copies the result into the runtime image;
 *  - build: compiled runtimes (Go, C, Rust, ...) run the build command on every version; its
 *    outputs (/build/out) land in /app of the run image;
 *  - runtimes that run through the universal wrapper get it baked in at /runr/runr-wrapper.
 *
 * Stage 1 runs in /build/code (the function's files), stage 2 is the image the function runs on.
 */
class FunctionBuildService
{
    public function __construct(
        private GitService $git,
        private RuntimeRegistry $runtimes,
    ) {}

    public function submitBuild(Version $version): void
    {
        $project = $version->project;
        $region = $project->knativeRegion;

        if (! $region || ! $region->kubeconfig) {
            throw new \RuntimeException('Region kubeconfig not configured');
        }

        if (! $region->has_registry) {
            throw new \RuntimeException('Builds require a container registry on the region');
        }

        $manifest = $this->runtimes->forProject($project);
        $executor = $this->runtimes->executorFor($project);
        // Before anything is marked: a version image still building in the region throws here
        $versionImage = $this->runtimes->versionImage($manifest, $executor, $region);

        // WebAssembly runtimes: toolchain Job → Spin OCI artifact in the region registry (docs/wasm.md)
        if (WasmRuntimes::isWasm($manifest)) {
            app(WasmBuildService::class)->submitBuild($version, $manifest, $executor, $versionImage);

            return;
        }

        $version->update(['build_status' => 'building']);

        $k8s = new KubernetesClient($region->kubeconfig);
        $namespace = $region->project_namespace ?? $region->name;
        $mode = $this->resolveTrigger($project) === 'schedule' ? 'schedule' : 'http';

        $files = $this->git->getAllFilesAtVersion($project, $version->commit_id);
        $files = array_merge($files, $this->buildFiles($manifest, $mode));
        $imageRef = $this->buildImageRef($region, $project, $version);

        $cmName = "build-{$project->slug}-v{$version->number}";
        $jobName = $cmName;

        // Clean up stale resources from previous builds (Job spec.template is immutable)
        $k8s->deleteResource('batch/v1', 'Job', $jobName, $namespace);
        $k8s->deleteResource('v1', 'ConfigMap', $cmName, $namespace);

        // Source files under code/ (ConfigMap keys code---<name>) plus the Dockerfile
        $cmData = [];
        foreach ($files as $name => $content) {
            $cmData["code---{$name}"] = $content;
        }
        // Base images through the internal registry's ghcr.io pull-through cache when it has one
        $ghcrSynced = app(ZotRegistryService::class)->syncsGhcr($region, $k8s);
        $cmData['Dockerfile'] = $this->dockerfile($project, $manifest, $executor, $versionImage, $files, $mode, $ghcrSynced);

        $k8s->createResource($this->sourceConfigMap($cmName, $namespace, $project, $cmData), $namespace);
        $k8s->createResource($this->kanikoJob($jobName, $namespace, $cmName, $imageRef, $region, $project, array_keys($files), $manifest->usesWrapper()), $namespace);

        $version->update(['build_image' => $imageRef]);

        Log::info('Function build submitted', [
            'project' => $project->slug,
            'runtime' => $manifest->id(),
            'version' => $version->number,
            'image' => $imageRef,
        ]);
    }

    public function buildImageRef($region, Project $project, Version $version): string
    {
        return "{$region->registry_address}/runr-builds/{$project->slug}:v{$version->number}";
    }

    /**
     * Dockerfile of a function build.
     *
     * @param  array<string, string>  $files  the function's files (build.files included)
     */
    public function dockerfile(Project $project, RuntimeManifest $manifest, ExecutorImage $executor, string $versionImage, array $files, string $mode, bool $ghcrSynced = false): string
    {
        $region = $project->knativeRegion;
        $entrypoint = ProjectEntrypoint::for($project, $manifest);
        $values = [
            'version' => $executor->version,
            'image' => $versionImage,
            'file' => $entrypoint->filename,
            'handler' => $entrypoint->handler,
            'mode' => $mode,
            'trigger' => $this->resolveTrigger($project),
        ];
        $render = fn (string $s) => Placeholders::render($s, $values);
        $from = fn (string $image) => $region ? $region->buildBaseImageRef($image, $ghcrSynced) : $image;

        // Without a compile step the build exists for the dependencies (needsBuild()); a compiled
        // runtime installs them when the project has the dependency file
        $deps = $manifest->dependencies();
        $build = $manifest->build();
        $hasDeps = $deps !== null && (! $build || array_key_exists($deps['file'], $files));

        $steps = [];
        if ($build) {
            $steps[] = 'mkdir -p /build/out';
        }
        if ($hasDeps) {
            $steps[] = $render($deps['install']);
        }
        if ($build) {
            $steps[] = $render($build['command']);
        }

        $stageImage = $render($build['image'] ?? $deps['image'] ?? '{image}');
        $runImage = $build ? $render($build['runImage']) : $versionImage;

        $lines = [
            "FROM {$from($stageImage)} AS build",
            'WORKDIR /build/code',
            'COPY code/ /build/code/',
            'RUN '.implode(' && ', $steps ?: ['true']),
            '',
            "FROM {$from($runImage)}",
            'WORKDIR /app',
        ];

        if (! $build || $build['source']) {
            $lines[] = 'COPY code/ /app/code/';
        }
        if ($hasDeps) {
            foreach ($deps['copy'] as $copy) {
                $lines[] = "COPY --from=build {$copy['from']} {$copy['to']}";
            }
        }
        if ($build) {
            $lines[] = 'COPY --from=build /build/out/ /app/';
        }
        if ($manifest->usesWrapper()) {
            $lines[] = 'COPY .runr/runr-wrapper '.RuntimeManifest::WRAPPER_PATH;
        }

        $env = [
            'RUNR_MODE' => $mode,
            'RUNR_HANDLER' => $entrypoint->handler,
            'RUNR_FILENAME' => $entrypoint->filename,
        ];
        if ($hasDeps) {
            $env += array_map($render, $deps['env']);
        }
        $env += array_map($render, $manifest->runEnv());

        $command = $manifest->renderedCommand($values);
        if ($manifest->runMode() !== 'http') {
            $env += RuntimeContainerSpec::wrapperEnv($manifest, $command);
        }
        $lines[] = 'ENV '.implode(' ', array_map(fn ($k, $v) => "{$k}=".self::envValue($v), array_keys($env), $env));
        $lines[] = 'EXPOSE 8080';

        $entry = $manifest->runMode() === 'http' ? $command : [RuntimeManifest::WRAPPER_PATH];
        if ($entry) {
            $lines[] = 'ENTRYPOINT '.json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", $lines);
    }

    /**
     * Install command of a runtime's dependencies for an executor version, e.g. composer with the
     * platform pinned to the runtime's PHP version (only a plain X.Y[.Z] version pins).
     */
    public static function installCommand(string $runtime, ?string $version = null): string
    {
        $deps = app(RuntimeRegistry::class)->require($runtime)->dependencies();

        return $deps ? Placeholders::render($deps['install'], ['version' => $version]) : 'true';
    }

    /**
     * Image the dependencies are installed in for an executor record: the runtime image itself
     * (compiled wheels / native modules match its interpreter and libc), or the manifest's
     * install image (PHP: composer).
     */
    public static function builderImage(string $runtime, ExecutorImage $executor): string
    {
        $manifest = app(RuntimeRegistry::class)->require($runtime);
        $image = $manifest->build()['image'] ?? $manifest->dependencies()['image'] ?? '{image}';

        return Placeholders::render($image, ['version' => $executor->version, 'image' => $executor->docker_image]);
    }

    /**
     * Extra files a runtime adds next to the code (Go: the wrapper source of the trigger).
     *
     * @return array<string, string>
     */
    private function buildFiles(RuntimeManifest $manifest, string $mode): array
    {
        $files = [];
        foreach ($manifest->build()['files'] ?? [] as $name => $content) {
            $files[$name] = is_array($content) ? $content[$mode] : $content;
        }

        return $files;
    }

    /** Dockerfile ENV value, quoted when it holds anything but plain characters. */
    private static function envValue(string $value): string
    {
        if (preg_match('#^[A-Za-z0-9_./:,=@+-]+$#', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    private function resolveTrigger(Project $project): string
    {
        $config = match ($project->service_type) {
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            default => null,
        };

        return $config->trigger_type ?? 'http';
    }

    private function buildRuntimeClassSpec($region): array
    {
        $runtimeClass = $region?->resolveBuildRuntimeClass();

        return $runtimeClass ? ['runtimeClassName' => $runtimeClass] : [];
    }

    private function sourceConfigMap(string $name, string $ns, Project $project, array $data): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => [
                'name' => $name,
                'namespace' => $ns,
                'labels' => [
                    'runr/build' => 'true',
                    'runr/project-id' => $project->id,
                ],
                'annotations' => [
                    'runr.sh/project-id' => $project->id,
                ],
            ],
            'data' => $data,
        ];
    }

    /**
     * @param  list<string>  $fileNames
     */
    private function kanikoJob(string $name, string $ns, string $cmName, string $imageRef, $region, Project $project, array $fileNames, bool $withWrapper): array
    {
        $registryAddr = $region->registry_address ?? '';

        // Reconstruct code/ from the flat ConfigMap keys (code---filename → code/filename)
        $copyLines = ['mkdir -p /workspace/code'];
        foreach ($fileNames as $f) {
            $copyLines[] = "cp \"/src/code---{$f}\" \"/workspace/code/{$f}\"";
        }
        $copyLines[] = 'cp /src/Dockerfile /workspace/Dockerfile';

        $initContainers = [[
            'name' => 'copy-src',
            'image' => BuildManifests::INIT_IMAGE,
            'command' => ['sh', '-c', implode(' && ', $copyLines)],
            'volumeMounts' => [
                ['name' => 'src', 'mountPath' => '/src', 'readOnly' => true],
                ['name' => 'workspace', 'mountPath' => '/workspace'],
            ],
            'resources' => BuildManifests::initResources(),
        ]];

        if ($withWrapper) {
            // The wrapper binary into the build context (.runr/runr-wrapper → /runr/runr-wrapper)
            $initContainers[] = [
                'name' => 'runr-wrapper',
                'image' => RuntimeContainerSpec::wrapperImage($region),
                'command' => ['/runr-wrapper', 'install', '/workspace/.runr/runr-wrapper'],
                'volumeMounts' => [['name' => 'workspace', 'mountPath' => '/workspace']],
                'resources' => BuildManifests::initResources(),
            ];
        }

        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => [
                'name' => $name,
                'namespace' => $ns,
                'labels' => BuildManifests::labels($project),
                'annotations' => ['runr.sh/project-id' => $project->id],
            ],
            'spec' => [
                'activeDeadlineSeconds' => 300,
                'backoffLimit' => 0,
                'ttlSecondsAfterFinished' => 300,
                'template' => [
                    'metadata' => ['labels' => BuildManifests::labels($project)],
                    'spec' => [
                        'restartPolicy' => 'Never',
                        'dnsPolicy' => 'ClusterFirst',
                        'dnsConfig' => [
                            'searches' => ['svc.cluster.local', 'cluster.local'],
                            'options' => [['name' => 'ndots', 'value' => '2']],
                        ],
                        ...$this->buildRuntimeClassSpec($region),
                        'initContainers' => $initContainers,
                        'containers' => [[
                            'name' => 'kaniko',
                            'image' => BuildManifests::kanikoImage($region),
                            'args' => [
                                '--dockerfile=/workspace/Dockerfile',
                                '--context=dir:///workspace',
                                "--destination={$imageRef}",
                                '--insecure',
                                '--insecure-pull',
                                '--custom-platform=linux/amd64',
                                '--ignore-path=/product_uuid',
                                ...($registryAddr ? ["--insecure-registry={$registryAddr}"] : []),
                            ],
                            'volumeMounts' => [
                                ['name' => 'workspace', 'mountPath' => '/workspace'],
                            ],
                            'resources' => BuildManifests::kanikoResources(),
                        ]],
                        'volumes' => [
                            ['name' => 'src', 'configMap' => ['name' => $cmName]],
                            ['name' => 'workspace', 'emptyDir' => new \stdClass],
                        ],
                    ],
                ],
            ],
        ];
    }
}
