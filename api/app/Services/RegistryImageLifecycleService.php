<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\Manifests\RegistryImageManifests;
use App\Services\Runtimes\RuntimeImageBuildService;
use Illuminate\Support\Facades\Log;

/**
 * Nothing ever deleted old build tags or a deleted project's images from the
 * internal (Zot) registry. Scoped to registry_mode === 'internal' only — an external registry
 * is the customer's own and is never touched.
 *
 * Talks to Zot via the K8s API server proxy (KubernetesClient::serviceProxy*), not a direct
 * HTTP call — the central API process is not necessarily on the same network as the region's
 * cluster, and registry_address is a ClusterIP that may not route from here. The proxy
 * addresses by Kubernetes Service *name*, so this targets the Zot Helm release's Service
 * (ZotRegistryService::ZOT_RELEASE_NAME = 'runr-zot') in $region->registry_namespace on port
 * 5000 — the same Service ZotRegistryService::install()/detectExisting() read clusterIP from.
 */
class RegistryImageLifecycleService
{
    private const ZOT_SERVICE_NAME = 'runr-zot';

    private const ZOT_SERVICE_PORT = 5000;

    private const ZOT_DEFAULT_NS = 'runr-registry';

    /** Deployment statuses after which a deployment runs nothing any more */
    private const FINISHED_DEPLOYMENT_STATUSES = ['shut_down', 'failed'];

    /** Zot error codes of a GET on a tag that does not exist (image already gone) */
    private const ABSENT_CODES = ['MANIFEST_UNKNOWN', 'NAME_UNKNOWN'];

    /** Repositories per /v2/_catalog page */
    private const CATALOG_PAGE = 1000;

    /** Content of the blob that lets Zot's garbage collection remove an empty repository */
    public const GC_SEED = "runr: empty repository, removed by the registry garbage collection\n";

    /**
     * List every tag pushed under a repository (e.g. "runr-builds/my-project").
     * Returns [] when the registry is not internal, unreachable, or the repo has no tags.
     *
     * @return list<string>
     */
    public function listTags(KnativeRegion $region, string $repoName): array
    {
        $k8s = $this->client($region);
        if (! $k8s) {
            return [];
        }

        try {
            $response = $k8s->serviceProxyGet(
                $this->registryNamespace($region),
                self::ZOT_SERVICE_NAME,
                self::ZOT_SERVICE_PORT,
                "v2/{$repoName}/tags/list",
            );

            return $response?->json('tags') ?? [];
        } catch (\Throwable $e) {
            Log::warning('Registry listTags failed', [
                'region' => $region->name, 'repo' => $repoName, 'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Delete one tag. Zot deletes by digest only: resolve the tag's Docker-Content-Digest
     * (HEAD, GET as fallback) accepting every manifest media type a build can be pushed as,
     * then DELETE the manifest by digest.
     *
     * @return bool true when the image is gone afterwards (deleted, or confirmed absent)
     */
    public function deleteImage(KnativeRegion $region, string $repoName, string $tag): bool
    {
        $k8s = $this->client($region);
        if (! $k8s) {
            return false;
        }

        try {
            $digest = $this->resolveDigest($k8s, $region, $repoName, $tag);

            if ($digest === false) {
                return true;
            }

            if ($digest === null) {
                Log::warning('Registry image digest not found', [
                    'region' => $region->name, 'repo' => $repoName, 'tag' => $tag,
                ]);

                return false;
            }

            return $k8s->serviceProxyDelete(
                $this->registryNamespace($region),
                self::ZOT_SERVICE_NAME,
                self::ZOT_SERVICE_PORT,
                "v2/{$repoName}/manifests/{$digest}",
            );
        } catch (\Throwable $e) {
            Log::warning('Registry deleteImage failed', [
                'region' => $region->name, 'repo' => $repoName, 'tag' => $tag, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete one manifest by digest (uploaded images, docs/images.md: deleting an image, or the
     * image an upload of the same repository:tag replaced). Zot answers 202; an unknown manifest
     * counts as deleted.
     *
     * @return bool true when the manifest is gone afterwards
     */
    public function deleteManifest(KnativeRegion $region, string $repoName, string $digest): bool
    {
        $k8s = $this->client($region);
        if (! $k8s) {
            return false;
        }

        try {
            return $k8s->serviceProxyDelete(
                $this->registryNamespace($region),
                self::ZOT_SERVICE_NAME,
                self::ZOT_SERVICE_PORT,
                "v2/{$repoName}/manifests/{$digest}",
            );
        } catch (\Throwable $e) {
            Log::warning('Registry deleteManifest failed', [
                'region' => $region->name, 'repo' => $repoName, 'digest' => $digest, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Retention after a successful build (RetainRegistryImagesJob): keep the newest $keep
     * ready builds of the project, never delete an image that is still in use — referenced by
     * a deployment that is not finished (pending/running/active/shutting_down/…) or by the
     * project's live Knative Service (template and revisions receiving traffic), CronJob or
     * queue consumer — and mark every version whose image was removed as "pruned" (its image
     * reference cleared) so it can no longer be deployed.
     *
     * Repositories come from each version's stored build_image, not from the current slug: a
     * renamed project keeps its older images under the previous slug's repository.
     *
     * @return list<string> ids of the versions pruned
     */
    public function retainForProject(Project $project, mixed $keep = null): array
    {
        $region = $project->knativeRegion;
        if (! $region || ! $this->applies($region)) {
            return [];
        }

        $k8s = $this->client($region);
        if (! $k8s) {
            return [];
        }

        $live = $this->liveImageRefs($k8s, $region, $project);
        if ($live === null) {
            // Can't tell what the cluster still runs → delete nothing this round
            Log::warning('Registry retention skipped: live workloads could not be read', [
                'region' => $region->name, 'project' => $project->slug,
            ]);

            return [];
        }

        $versions = $project->versions()->get(['id', 'project_id', 'number', 'build_status', 'build_image']);

        $protected = $project->deployments()
            ->whereNotIn('status', self::FINISHED_DEPLOYMENT_STATUSES)
            ->pluck('version_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->all();

        $liveKeys = array_filter(array_map(fn (string $ref) => $this->imageKey($ref), $live));
        foreach ($versions as $version) {
            $key = $this->imageKey($version->build_image);
            if ($key !== null && in_array($key, $liveKeys, true)) {
                $protected[] = (string) $version->id;
            }
        }

        $pruneIds = RegistryImageManifests::versionsToPrune(
            $versions->map(fn (Version $v) => [
                'id' => (string) $v->id,
                'number' => (int) $v->number,
                'build_status' => $v->build_status,
                'build_image' => $v->build_image,
            ])->all(),
            RegistryImageManifests::clampRetain($keep ?? config('registry.retain_versions')),
            $protected,
        );

        $pruned = [];

        foreach ($versions->whereIn('id', $pruneIds) as $version) {
            $ref = RegistryImageManifests::parseImageRef($version->build_image);

            if (! $ref || $this->imageSharedWithAnotherProject($version)) {
                continue;
            }

            if (! $this->deleteImage($region, $ref['repo'], $ref['tag'])) {
                continue;
            }

            $version->forceFill([
                'build_status' => $version->build_status === 'ready' ? Version::STATUS_PRUNED : $version->build_status,
                'build_image' => null,
            ])->save();

            $pruned[] = (string) $version->id;
        }

        if ($pruned) {
            Log::info('Registry retention pruned build images', [
                'region' => $region->name, 'project' => $project->slug, 'versions' => count($pruned),
            ]);
        }

        return $pruned;
    }

    /**
     * Delete every image of a project. Called when a project is deleted. The current slug's
     * repository belongs to the project as a whole; repositories of earlier slugs (renamed
     * project) only lose the tags of this project's own versions.
     */
    public function deleteAllForProject(KnativeRegion $region, Project $project): void
    {
        if (! $this->applies($region)) {
            return;
        }

        $repoName = RegistryImageManifests::repositoryName($project->slug);

        try {
            foreach ($this->listTags($region, $repoName) as $tag) {
                $this->deleteImage($region, $repoName, $tag);
            }

            foreach ($project->versions()->whereNotNull('build_image')->get(['id', 'project_id', 'build_image']) as $version) {
                $ref = RegistryImageManifests::parseImageRef($version->build_image);
                if ($ref && $ref['repo'] !== $repoName && ! $this->imageSharedWithAnotherProject($version)) {
                    $this->deleteImage($region, $ref['repo'], $ref['tag']);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Registry deleteAllForProject failed', [
                'region' => $region->name, 'project' => $project->slug, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete every image of a runtime in the region: its repository runr-runtimes/<id> (every
     * version, also the builds of earlier sources). Called when the runtime is deleted — nothing
     * can use its builds any more; Zot frees the blobs and the repository with its garbage
     * collection.
     *
     * @return int tags deleted
     */
    public function deleteRuntimeImages(KnativeRegion $region, string $runtimeId): int
    {
        if (! $this->applies($region)) {
            return 0;
        }

        $repoName = RuntimeImageBuildService::repository($runtimeId);
        $deleted = 0;
        foreach ($this->listTags($region, $repoName) as $tag) {
            if ($this->deleteImage($region, $repoName, (string) $tag)) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            Log::info('Registry runtime images deleted', ['region' => $region->name, 'repo' => $repoName, 'tags' => $deleted]);
        }

        return $deleted;
    }

    /**
     * Build repositories (runr-builds/…, runr-runtimes/…) without a tag. A repository is created by
     * the first upload into it; one whose push never finished (a build that failed after the
     * registry was contacted) stays listed in the catalog. Zot has no API to delete a repository.
     * Zot 2.1.21 (the pinned chart) removes an idle repository in its garbage collection; older
     * releases (2.1.2, checked) remove one only together with its last unreferenced blob, so a
     * repository without any blob stays for good. Each empty one therefore gets one small
     * unreferenced blob: the next garbage collection after Zot's GC delay (1 h by default) deletes
     * the blob and, with it, the repository, on every release. A push into the repository before
     * then keeps it; the blob alone is collected.
     *
     * @return list<string> repositories handed to the garbage collection
     */
    public function pruneEmptyRepositories(KnativeRegion $region): array
    {
        $k8s = $this->client($region);
        if (! $k8s) {
            return [];
        }

        $repos = $this->catalog($k8s, $region);
        if ($repos === null) {
            return [];
        }

        $pruned = [];
        foreach ($repos as $repo) {
            if (! $this->isBuildRepository($repo)) {
                continue;
            }

            try {
                $tags = $k8s->serviceProxyRequest('GET', $this->registryNamespace($region), self::ZOT_SERVICE_NAME, self::ZOT_SERVICE_PORT, "v2/{$repo}/tags/list");
                if (! $tags->successful() || ($tags->json('tags') ?? []) !== []) {
                    continue;
                }

                $seed = $k8s->serviceProxySend(
                    'POST',
                    $this->registryNamespace($region),
                    self::ZOT_SERVICE_NAME,
                    self::ZOT_SERVICE_PORT,
                    "v2/{$repo}/blobs/uploads/?digest=".self::gcSeedDigest(),
                    ['Content-Type' => 'application/octet-stream'],
                    self::GC_SEED,
                );
                if ($seed->status() === 201) {
                    $pruned[] = $repo;
                } else {
                    Log::warning('Registry empty repository could not be handed to the garbage collection', [
                        'region' => $region->name, 'repo' => $repo, 'status' => $seed->status(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Registry empty repository check failed', ['region' => $region->name, 'repo' => $repo, 'error' => $e->getMessage()]);
            }
        }

        if ($pruned) {
            Log::info('Registry empty repositories handed to the garbage collection', ['region' => $region->name, 'repos' => $pruned]);
        }

        return $pruned;
    }

    public static function gcSeedDigest(): string
    {
        return 'sha256:'.hash('sha256', self::GC_SEED);
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /** Repositories RunR's builds push to (function builds, runtime image builds). */
    private function isBuildRepository(string $repo): bool
    {
        return str_starts_with($repo, RegistryImageManifests::repositoryName(''))
            || str_starts_with($repo, RuntimeImageBuildService::repository(''));
    }

    /**
     * Every repository of the registry (/v2/_catalog, paged with n / last). null when the catalog
     * could not be read completely.
     *
     * @return list<string>|null
     */
    private function catalog(KubernetesClient $k8s, KnativeRegion $region): ?array
    {
        $repos = [];
        $last = null;

        try {
            for ($page = 0; $page < 100; $page++) {
                $query = ['n' => self::CATALOG_PAGE] + ($last !== null ? ['last' => $last] : []);
                $response = $k8s->serviceProxyRequest('GET', $this->registryNamespace($region), self::ZOT_SERVICE_NAME, self::ZOT_SERVICE_PORT, 'v2/_catalog', query: $query);
                if (! $response->successful()) {
                    return null;
                }

                $batch = array_values(array_filter((array) ($response->json('repositories') ?? []), 'is_string'));
                $repos = array_merge($repos, $batch);
                if (count($batch) < self::CATALOG_PAGE || ! $response->header('Link')) {
                    return array_values(array_unique($repos));
                }
                $last = end($batch);
            }
        } catch (\Throwable $e) {
            Log::warning('Registry catalog could not be read', ['region' => $region->name, 'error' => $e->getMessage()]);

            return null;
        }

        return array_values(array_unique($repos));
    }

    /**
     * @return string|false|null the digest; false when the registry confirms the tag does not
     *                           exist; null when it could not be resolved
     */
    private function resolveDigest(KubernetesClient $k8s, KnativeRegion $region, string $repoName, string $tag): string|false|null
    {
        $args = [$this->registryNamespace($region), self::ZOT_SERVICE_NAME, self::ZOT_SERVICE_PORT, "v2/{$repoName}/manifests/{$tag}"];
        $headers = ['Accept' => RegistryImageManifests::MANIFEST_ACCEPT];

        $head = $k8s->serviceProxyRequest('HEAD', ...$args, headers: $headers);
        if ($head->successful() && $head->header('Docker-Content-Digest')) {
            return $head->header('Docker-Content-Digest');
        }

        // HEAD has no body: a 404 there can't tell "tag unknown" from "no such Service" → GET
        $get = $k8s->serviceProxyRequest('GET', ...$args, headers: $headers);
        if ($get->successful() && $get->header('Docker-Content-Digest')) {
            return $get->header('Docker-Content-Digest');
        }

        if ($get->status() === 404 && in_array($get->json('errors.0.code'), self::ABSENT_CODES, true)) {
            return false;
        }

        return null;
    }

    /**
     * Images the project's live workloads reference: its Knative Service template and the
     * revisions receiving traffic, its CronJob and its queue consumer. null when the cluster
     * could not be read.
     *
     * @return list<string>|null
     */
    private function liveImageRefs(KubernetesClient $k8s, KnativeRegion $region, Project $project): ?array
    {
        $ns = $region->project_namespace ?? $region->name;
        $names = app(KnativeDeployService::class)->workloadNames($project);

        try {
            $images = [];

            $service = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $names['service'], $ns) ?? [];
            $images = array_merge($images, $this->podImages($service['spec']['template']['spec'] ?? []));

            $revisions = array_filter(array_merge(
                array_column($service['status']['traffic'] ?? [], 'revisionName'),
                [$service['status']['latestReadyRevisionName'] ?? null],
            ));
            foreach (array_unique($revisions) as $revision) {
                $rev = $k8s->getResourceStatus('serving.knative.dev/v1', 'Revision', $revision, $ns) ?? [];
                $images = array_merge($images, $this->podImages($rev['spec'] ?? []));
            }

            $cron = $k8s->getResourceStatus('batch/v1', 'CronJob', $names['cronJob'], $ns) ?? [];
            $images = array_merge($images, $this->podImages($cron['spec']['jobTemplate']['spec']['template']['spec'] ?? []));

            $consumer = $k8s->getResourceStatus('apps/v1', 'Deployment', $names['queueConsumer'], $ns) ?? [];
            $images = array_merge($images, $this->podImages($consumer['spec']['template']['spec'] ?? []));

            return array_values(array_unique($images));
        } catch (\Throwable $e) {
            Log::warning('Registry retention: reading live workloads failed', [
                'region' => $region->name, 'project' => $project->slug, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @return list<string> */
    private function podImages(array $podSpec): array
    {
        $containers = array_merge($podSpec['containers'] ?? [], $podSpec['initContainers'] ?? []);

        return array_values(array_filter(array_column($containers, 'image')));
    }

    /** repo:tag of a build image, independent of the registry host part */
    private function imageKey(?string $imageRef): ?string
    {
        $ref = RegistryImageManifests::parseImageRef($imageRef);

        return $ref ? "{$ref['repo']}:{$ref['tag']}" : null;
    }

    /**
     * A repository named after a slug can be reused: project A renamed away from "api", a new
     * project B named "api" pushes to the same repository. Never delete an image another
     * project's version still points at.
     */
    private function imageSharedWithAnotherProject(Version $version): bool
    {
        return Version::query()
            ->where('build_image', $version->build_image)
            ->where('project_id', '!=', $version->project_id)
            ->exists();
    }

    /**
     * Mirrors ZotRegistryService::uninstall()'s early-return guard: anything not an internal
     * registry with a reachable cluster is a no-op. A demo region has no cluster (its kubeconfig
     * is a placeholder).
     */
    private function applies(KnativeRegion $region): bool
    {
        return $region->registry_mode === 'internal' && (bool) $region->kubeconfig && ! $region->is_demo;
    }

    private function client(KnativeRegion $region): ?KubernetesClient
    {
        if (! $this->applies($region)) {
            return null;
        }

        try {
            return new KubernetesClient($region->kubeconfig);
        } catch (\Throwable $e) {
            Log::warning('Registry K8s client init failed', ['region' => $region->name, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function registryNamespace(KnativeRegion $region): string
    {
        return $region->registry_namespace ?? self::ZOT_DEFAULT_NS;
    }
}
