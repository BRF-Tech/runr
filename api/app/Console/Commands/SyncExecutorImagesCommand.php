<?php

namespace App\Console\Commands;

use App\Models\ExecutorImage;
use App\Models\Runtime;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\Placeholders;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\SupplyChain\ImageRef;
use App\Support\Images\OciRegistryClient;
use App\Support\Images\RegistryMove;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Runtime versions into executor_images: built-in runtimes from the tags of their manifest's
 * versionDiscovery repository, read from that repository's own registry (OCI distribution API;
 * EOL dates from endoflife.date; a "<version>-gpu" tag next to a version is its GPU build),
 * platform and custom runtimes from the versions their manifest lists.
 */
class SyncExecutorImagesCommand extends Command
{
    /** Tag suffix of a version's GPU build (exec-pytorch:2.14-gpu): executor_images.gpu_required. */
    private const GPU_SUFFIX = '-gpu';

    /** Pages of a repository's tag list read at most (1000 tags each); more leave the list partial. */
    private const MAX_TAG_PAGES = 10;

    protected $signature = 'executor:sync {--force : Re-check all runtimes even if recently synced}';

    protected $description = 'Sync runtime versions (built-in catalog from its image registry, registered runtimes from their manifests) into executor_images';

    public function handle(RuntimeRegistry $registry, BuiltinCatalog $catalog): int
    {
        // Built-in runtime rows (state: enabled / regions) for the release catalog
        $registry->syncBuiltins();

        // Built-ins: versions discovered in the image registry (manifest versionDiscovery),
        // offered ones only (EXECUTOR_RUNTIMES)
        $builtins = array_filter(
            $catalog->all(),
            fn (RuntimeManifest $m) => $m->versionDiscovery() !== null && in_array($m->id(), $registry->builtinIds(), true),
        );

        $this->info('Discovered '.count($builtins).' runtime(s): '.implode(', ', array_keys($builtins)));

        foreach ($builtins as $manifest) {
            $this->syncRuntime($manifest);
        }

        // Built-ins with a static versions list (e.g. the WebAssembly runtimes): from their manifest
        foreach ($catalog->all() as $manifest) {
            if ($manifest->versionDiscovery() === null && in_array($manifest->id(), $registry->builtinIds(), true)
                && ($row = $registry->row($manifest->id()))) {
                $registry->syncVersions($row, $manifest);
                $this->line("  {$manifest->id()}: ".count($manifest->versions()).' version(s) from its manifest');
            }
        }

        // Platform and custom runtimes: versions straight from their manifest
        Runtime::where('source', '!=', Runtime::SOURCE_BUILTIN)->get()->each(function (Runtime $runtime) use ($registry) {
            if ($manifest = $registry->manifest($runtime->id)) {
                $registry->syncVersions($runtime, $manifest);
                $this->line("  {$runtime->id}: ".count($manifest->versions()).' version(s) from its manifest');
            }
        });

        $total = ExecutorImage::where('is_active', true)->count();
        $this->info("Sync complete. {$total} active executor images.");

        return 0;
    }

    private function syncRuntime(RuntimeManifest $manifest): void
    {
        $discovery = $manifest->versionDiscovery();
        $runtime = $manifest->id();
        // Repository and images in RunR's image namespace (RegistryMove), as every stored reference
        $repository = (string) RegistryMove::ref($discovery['repository']);
        $this->info("── {$runtime} ({$repository}) ──");

        $result = $this->discoverVersions($repository);

        // lts-even-major (Node.js): even majors only, the highest minor of each
        if ($discovery['filter'] === 'lts-even-major') {
            $grouped = [];
            foreach ($result['versions'] as $ver) {
                $major = (int) explode('.', $ver)[0];
                if ($major % 2 !== 0) {
                    continue;
                }
                // Versions are sorted: the last one is the highest
                $grouped[$major] = $ver;
            }
            $result['versions'] = array_values($grouped);
        }

        if (empty($result['versions'])) {
            $this->warn("  Could not fetch tags from {$repository}, skipping.");

            return;
        }

        // Default = the highest version (last after sorting); a GPU build never is
        $defaultVersion = end($result['versions']);

        $sortOrder = 0;
        $discoveredVersions = [];

        foreach ($result['versions'] as $base) {
            // Each version, then its GPU build when the repository has the <version>-gpu tag
            $variants = in_array($base.self::GPU_SUFFIX, $result['gpu'], true) ? [$base, $base.self::GPU_SUFFIX] : [$base];
            $eolDate = $discovery['eol'] ? $this->fetchEolDate($discovery['eol'], $base) : null;

            foreach ($variants as $ver) {
                $sortOrder++;
                $discoveredVersions[] = $ver;

                $image = ExecutorImage::updateOrCreate(
                    ['runtime' => $runtime, 'version' => $ver],
                    [
                        'docker_image' => RegistryMove::ref(Placeholders::render($discovery['image'], ['version' => $ver])),
                        'is_default' => ($ver === $defaultVersion),
                        'is_active' => true,
                        'sort_order' => $sortOrder,
                        'category' => $manifest->category(),
                        'gpu_required' => $ver !== $base,
                        'eol_at' => $eolDate,
                    ],
                );

                $status = $image->wasRecentlyCreated ? 'NEW' : 'ok';
                $default = $ver === $defaultVersion ? ' (default)' : '';
                $eolLabel = $eolDate ? " [EOL: {$eolDate}]" : '';
                $this->line("  {$status}: {$ver}{$default}{$eolLabel}");
            }
        }

        // Versions no longer in the repository are deactivated (soft); a retired default stops being one
        if ($result['complete']) {
            $deactivated = ExecutorImage::where('runtime', $runtime)
                ->whereNotIn('version', $discoveredVersions)
                ->where('is_active', true)
                ->update(['is_active' => false, 'is_default' => false]);

            if ($deactivated > 0) {
                $this->warn("  Deactivated {$deactivated} version(s) no longer in {$repository}.");
            }
        } else {
            $this->warn("  Partial tag list from {$repository}, skipping cleanup.");
        }
    }

    private function fetchEolDate(string $runtime, string $version): ?string
    {
        $cycle = implode('.', array_slice(explode('.', $version), 0, 2));
        $eol = $this->queryEolApi($runtime, $cycle);

        if ($eol === null && str_contains($version, '.')) {
            $eol = $this->queryEolApi($runtime, explode('.', $version)[0]);
        }

        return $eol;
    }

    private function queryEolApi(string $runtime, string $cycle): ?string
    {
        try {
            $response = Http::timeout(10)
                ->get("https://endoflife.date/api/{$runtime}/{$cycle}.json");

            if (! $response->successful()) {
                return null;
            }

            $eol = $response->json('eol');
            if ($eol === true) {
                return '1970-01-01';
            }
            if ($eol === false) {
                return null;
            }
            if (is_string($eol)) {
                return $eol;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Discover available versions (tags) of an image repository.
     * Only accepts clean semver tags (X.Y or X.Y.Z) and their GPU builds (X.Y-gpu, X.Y.Z-gpu: the
     * ML runtimes publish one per version). Skips "latest", other suffixes and pre-release tags.
     *
     * @return array{versions: string[], gpu: string[], complete: bool}
     */
    private function discoverVersions(string $repository): array
    {
        $result = $this->fetchTags($repository);

        if (empty($result['tags'])) {
            return ['versions' => [], 'gpu' => [], 'complete' => false];
        }

        $versions = [];
        $gpu = [];

        foreach ($result['tags'] as $tag) {
            // Only accept clean numeric versions: X.Y or X.Y.Z, or one of them + "-gpu"
            if (! preg_match('/^(\d+\.\d+(\.\d+)?)('.preg_quote(self::GPU_SUFFIX, '/').')?$/', $tag, $m)) {
                continue;
            }

            if (isset($m[3])) {
                $gpu[] = $tag;
            } else {
                $versions[] = $tag;
            }
        }

        // Natural version order
        usort($versions, 'version_compare');

        return ['versions' => array_values(array_unique($versions)), 'gpu' => array_values(array_unique($gpu)), 'complete' => $result['complete']];
    }

    /**
     * All tag names of an image repository ("ghcr.io/brf-tech/runr/exec-python") from its registry:
     * GET /v2/<name>/tags/list, following the Link header to the next page. Read anonymously — a
     * registry that answers 401 with a Bearer challenge gets a pull token from its token service
     * first. A repository without a registry host ("runrsh/exec-python") is on Docker Hub.
     *
     * @return array{tags: string[], complete: bool}
     */
    private function fetchTags(string $repository): array
    {
        $ref = ImageRef::parse($repository);
        $host = $ref->registry === 'docker.io' ? 'registry-1.docker.io' : $ref->registry;
        $url = "https://{$host}/v2/{$ref->repository}/tags/list?n=1000";
        $token = null;
        $allTags = [];

        for ($page = 1; $page <= self::MAX_TAG_PAGES; $page++) {
            try {
                $response = $this->registryGet($url, $token);

                if ($response->status() === 401 && $token === null) {
                    $token = OciRegistryClient::bearerToken((string) $response->header('WWW-Authenticate'), $ref->repository);
                    if ($token !== null) {
                        $response = $this->registryGet($url, $token);
                    }
                }

                // Unknown repository, DENIED, no token: what was read so far is partial
                if (! $response->successful()) {
                    return ['tags' => $allTags, 'complete' => false];
                }

                foreach ((array) $response->json('tags') as $tag) {
                    if (is_string($tag)) {
                        $allTags[] = $tag;
                    }
                }

                $url = $this->nextPage((string) $response->header('Link'), $host);
                if ($url === null) {
                    return ['tags' => $allTags, 'complete' => true];
                }
            } catch (\Throwable $e) {
                $this->warn("Registry API error ({$host}, page {$page}): ".$e->getMessage());

                return ['tags' => $allTags, 'complete' => false];
            }
        }

        // More pages than MAX_TAG_PAGES
        return ['tags' => $allTags, 'complete' => false];
    }

    private function registryGet(string $url, ?string $token): Response
    {
        return Http::timeout(30)
            ->accept('application/json')
            ->withHeaders($token === null ? [] : ['Authorization' => "Bearer {$token}"])
            ->get($url);
    }

    /** The next page a tags/list answer links (Link: </v2/…/tags/list?last=…&n=…>; rel="next"), null on the last. */
    private function nextPage(string $link, string $host): ?string
    {
        if (! preg_match('/<([^>]+)>\s*;\s*rel="?next"?/i', $link, $m)) {
            return null;
        }

        return str_starts_with($m[1], '/') ? "https://{$host}{$m[1]}" : $m[1];
    }
}
