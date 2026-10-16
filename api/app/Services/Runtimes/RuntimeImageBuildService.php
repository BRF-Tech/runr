<?php

namespace App\Services\Runtimes;

use App\Jobs\CheckRuntimeImageBuildJob;
use App\Models\KnativeRegion;
use App\Models\Runtime;
use App\Models\RuntimeBuild;
use App\Services\KubernetesClient;
use App\Services\Manifests\BuildManifests;
use Illuminate\Support\Facades\Log;

/**
 * Runtime versions declared with a dockerfile (uploaded with the runtime) or a git source are
 * built by RunR in each region that runs them — a Kaniko Job in the region's project namespace,
 * pushed to the region registry as runr-runtimes/<id>:<version>-<digest>. The build is cached
 * (runtime_builds): a function build or deploy uses it as the version image until the version
 * source or the uploaded files change (digest).
 */
class RuntimeImageBuildService
{
    public const JOB_DEADLINE_SECONDS = 900;

    /** The ready build of a version in a region, null when there is none for the current source. */
    public function readyBuild(Runtime $runtime, array $spec, KnativeRegion $region): ?RuntimeBuild
    {
        return RuntimeBuild::where('runtime_id', $runtime->id)
            ->where('version', $spec['version'])
            ->where('knative_region_id', $region->id)
            ->where('status', RuntimeBuild::STATUS_READY)
            ->where('digest', $this->digest($runtime, $spec))
            ->first();
    }

    /** Hash of what a version build consumes: its source spec and the uploaded files. */
    public function digest(Runtime $runtime, array $spec): string
    {
        $files = $spec['dockerfile'] !== null ? ($runtime->files ?? []) : [];
        ksort($files);

        return hash('sha256', json_encode([
            'version' => $spec['version'],
            'dockerfile' => $spec['dockerfile'],
            'git' => $spec['git'],
            'buildArgs' => $spec['buildArgs'],
            'files' => $files,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Versions of a manifest RunR builds from source. */
    public static function sourceVersions(RuntimeManifest $manifest): array
    {
        return array_values(array_filter($manifest->versions(), fn ($v) => $v['dockerfile'] !== null || $v['git'] !== null));
    }

    /** Build every source version in every region the runtime is available in (with a registry). */
    public function ensureAll(Runtime $runtime, ?RuntimeManifest $manifest = null): void
    {
        $manifest ??= new RuntimeManifest($runtime->manifest ?? []);
        $versions = self::sourceVersions($manifest);
        if (! $versions || ! $runtime->is_enabled) {
            return;
        }

        KnativeRegion::query()->get()
            ->filter(fn (KnativeRegion $r) => $r->is_active && ! $r->is_demo && $r->kubeconfig && $r->has_registry && $runtime->availableIn($r->id))
            ->each(function (KnativeRegion $region) use ($runtime, $versions) {
                foreach ($versions as $spec) {
                    try {
                        $this->ensure($runtime, $spec, $region);
                    } catch (\Throwable $e) {
                        Log::warning('Runtime image build could not start', [
                            'runtime' => $runtime->id, 'version' => $spec['version'], 'region' => $region->name, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    /**
     * The build of a version in a region: the ready or running one for the current source, else a
     * new Kaniko Job ($force: rebuild even a ready one).
     */
    public function ensure(Runtime $runtime, array $spec, KnativeRegion $region, bool $force = false): RuntimeBuild
    {
        $digest = $this->digest($runtime, $spec);
        $build = RuntimeBuild::firstOrNew([
            'runtime_id' => $runtime->id,
            'version' => $spec['version'],
            'knative_region_id' => $region->id,
        ]);

        $current = $build->exists && $build->digest === $digest;
        if (! $force && $current && in_array($build->status, [RuntimeBuild::STATUS_READY, RuntimeBuild::STATUS_BUILDING], true)) {
            return $build;
        }

        if (! $region->kubeconfig || ! $region->has_registry) {
            $build->fill(['status' => RuntimeBuild::STATUS_FAILED, 'digest' => $digest, 'error' => 'The region has no container registry to build runtime images into.'])->save();

            return $build;
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $namespace = $region->project_namespace ?? $region->name;
        $jobName = self::jobName($runtime->id, $spec['version'], $digest);
        $image = self::imageRef($region, $runtime->id, $spec['version'], $digest);

        $k8s->deleteResource('batch/v1', 'Job', $jobName, $namespace);
        $k8s->deleteResource('v1', 'ConfigMap', $jobName, $namespace);

        if ($spec['dockerfile'] !== null) {
            $k8s->createResource($this->contextConfigMap($jobName, $namespace, $runtime), $namespace);
        }
        $k8s->createResource($this->kanikoJob($jobName, $namespace, $runtime, $spec, $image, $region), $namespace);

        $build->fill([
            'status' => RuntimeBuild::STATUS_BUILDING,
            'digest' => $digest,
            'image' => $image,
            'job_name' => $jobName,
            'error' => null,
        ])->save();

        CheckRuntimeImageBuildJob::start($build);

        Log::info('Runtime image build submitted', ['runtime' => $runtime->id, 'version' => $spec['version'], 'region' => $region->name, 'image' => $image]);

        return $build;
    }

    public static function imageRef(KnativeRegion $region, string $id, string $version, string $digest): string
    {
        return rtrim((string) $region->registry_address, '/').'/'.self::repository($id).":{$version}-".substr($digest, 0, 12);
    }

    /** Registry repository of a runtime's builds: runr-runtimes/<id>. */
    public static function repository(string $id): string
    {
        return "runr-runtimes/{$id}";
    }

    public static function jobName(string $id, string $version, string $digest): string
    {
        $slug = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower("{$id}-{$version}")), '-');

        return 'rt-'.substr($slug, 0, 40).'-'.substr($digest, 0, 8);
    }

    private function contextConfigMap(string $name, string $namespace, Runtime $runtime): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => ['runr/build' => 'true', 'runr/runtime' => $runtime->id],
            ],
            'data' => $runtime->files ?? [],
        ];
    }

    private function kanikoJob(string $name, string $namespace, Runtime $runtime, array $spec, string $image, KnativeRegion $region): array
    {
        $registry = (string) $region->registry_address;
        $labels = ['runr/build' => 'true', 'runr/runtime' => $runtime->id];

        if ($spec['git'] !== null) {
            $git = $spec['git'];
            $ref = $git['ref'] ? (str_starts_with($git['ref'], 'refs/') ? $git['ref'] : "refs/heads/{$git['ref']}") : null;
            $context = 'git://'.preg_replace('#^https://#', '', $git['url']).($ref ? "#{$ref}" : '');
            $args = [
                "--context={$context}",
                '--dockerfile='.($git['dockerfile'] ?? 'Dockerfile'),
            ];
            if (trim((string) $git['path'], '/') !== '') {
                $args[] = '--context-sub-path='.trim($git['path'], '/');
            }
        } else {
            $args = ['--context=dir:///workspace', "--dockerfile=/workspace/{$spec['dockerfile']}"];
        }

        foreach ($spec['buildArgs'] as $key => $value) {
            $args[] = "--build-arg={$key}={$value}";
        }

        $args = array_merge($args, array_filter([
            "--destination={$image}",
            // No upload before the build: a failed build leaves no empty repository behind
            BuildManifests::SKIP_PUSH_PERMISSION_CHECK,
            '--insecure',
            '--insecure-pull',
            '--custom-platform=linux/amd64',
            '--ignore-path=/product_uuid',
            $registry ? "--insecure-registry={$registry}" : null,
        ]));

        $podSpec = [
            'restartPolicy' => 'Never',
            'dnsPolicy' => 'ClusterFirst',
            'dnsConfig' => [
                'searches' => ['svc.cluster.local', 'cluster.local'],
                'options' => [['name' => 'ndots', 'value' => '2']],
            ],
            'containers' => [[
                'name' => 'kaniko',
                'image' => BuildManifests::kanikoImage($region),
                'args' => array_values($args),
                'volumeMounts' => [['name' => 'workspace', 'mountPath' => '/workspace']],
                'resources' => BuildManifests::kanikoResources(),
            ]],
            'volumes' => [['name' => 'workspace', 'emptyDir' => new \stdClass]],
        ];

        if ($runtimeClass = $region->resolveBuildRuntimeClass()) {
            $podSpec['runtimeClassName'] = $runtimeClass;
        }

        if ($spec['dockerfile'] !== null) {
            $podSpec['initContainers'] = [[
                'name' => 'copy-src',
                'image' => BuildManifests::INIT_IMAGE,
                'command' => ['sh', '-c', 'cp -L /src/* /workspace/'],
                'volumeMounts' => [
                    ['name' => 'src', 'mountPath' => '/src', 'readOnly' => true],
                    ['name' => 'workspace', 'mountPath' => '/workspace'],
                ],
                'resources' => BuildManifests::initResources(),
            ]];
            $podSpec['volumes'][] = ['name' => 'src', 'configMap' => ['name' => $name]];
        }

        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $labels,
            ],
            'spec' => [
                'activeDeadlineSeconds' => self::JOB_DEADLINE_SECONDS,
                'backoffLimit' => 0,
                'ttlSecondsAfterFinished' => 600,
                'template' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => $podSpec,
                ],
            ],
        ];
    }
}
