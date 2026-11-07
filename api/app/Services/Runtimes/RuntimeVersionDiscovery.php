<?php

namespace App\Services\Runtimes;

use App\Models\ExecutorImage;
use App\Models\Runtime;
use App\Support\Images\OciRegistryClient;
use App\Support\Images\RegistryMove;
use Illuminate\Support\Facades\Http;

/**
 * Versions of a runtime whose manifest has versionDiscovery (the built-in catalog, runtime
 * sources): the clean X.Y[.Z] tags of the image repository, read from its own registry (OCI
 * distribution API, every page of the Link header) — anonymously, or with a private source's
 * registry credentials ($client). Each becomes an executor_images row (image from the template,
 * the newest the default); a "<version>-gpu" tag next to a version is its GPU build; end-of-life
 * dates come from endoflife.date for the `eol` product unless versionDiscovery.lifecycle names one;
 * `filter: lts-even-major` keeps the newest minor of each even major. Tags that left the
 * repository are deactivated only after a complete tag list.
 */
class RuntimeVersionDiscovery
{
    /** Tag suffix of a version's GPU build (exec-pytorch:2.14-gpu): executor_images.gpu_required. */
    public const GPU_SUFFIX = '-gpu';

    /** Pages of a repository's tag list read at most (1000 tags each); more leave the list partial. */
    private const MAX_TAG_PAGES = 10;

    /**
     * Discover and store the versions; returns what happened as [level, text] lines (info, line, warn).
     *
     * @return list<array{0: string, 1: string}>
     */
    public function sync(RuntimeManifest $manifest, ?OciRegistryClient $client = null): array
    {
        $discovery = (array) $manifest->versionDiscovery();
        $runtime = $manifest->id();
        // Repository and images in RunR's image namespace (RegistryMove), as every stored reference
        $repository = (string) RegistryMove::ref((string) $discovery['repository']);
        $lines = [['info', "── {$runtime} ({$repository}) ──"]];

        $result = $this->discoverVersions($client ?? OciRegistryClient::direct(30), $repository);

        // lts-even-major (Node.js): even majors only, the highest minor of each
        if (($discovery['filter'] ?? null) === 'lts-even-major') {
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
            $lines[] = ['warn', "  Could not fetch tags from {$repository}, skipping."];

            return $lines;
        }

        // Default = the highest version (last after sorting); a GPU build never is
        $defaultVersion = end($result['versions']);

        $sortOrder = 0;
        $discoveredVersions = [];

        foreach ($result['versions'] as $base) {
            // Each version, then its GPU build when the repository has the <version>-gpu tag
            $variants = in_array($base.self::GPU_SUFFIX, $result['gpu'], true) ? [$base, $base.self::GPU_SUFFIX] : [$base];
            $eolDate = $manifest->versionLifecycle($base)['eol']
                ?? (! empty($discovery['eol']) ? $this->fetchEolDate((string) $discovery['eol'], $base) : null);

            foreach ($variants as $ver) {
                $sortOrder++;
                $discoveredVersions[] = $ver;

                $image = ExecutorImage::updateOrCreate(
                    ['runtime' => $runtime, 'version' => $ver],
                    [
                        'docker_image' => RegistryMove::ref(Placeholders::render((string) $discovery['image'], ['version' => $ver])),
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
                $lines[] = ['line', "  {$status}: {$ver}{$default}{$eolLabel}"];
            }
        }

        // Versions no longer in the repository are deactivated (soft); a retired default stops being one
        if ($result['complete']) {
            $deactivated = ExecutorImage::where('runtime', $runtime)
                ->whereNotIn('version', $discoveredVersions)
                ->where('is_active', true)
                ->update(['is_active' => false, 'is_default' => false]);

            if ($deactivated > 0) {
                $lines[] = ['warn', "  Deactivated {$deactivated} version(s) no longer in {$repository}."];
            }
        } else {
            $lines[] = ['warn', "  Partial tag list from {$repository}, skipping cleanup."];
        }

        // deprecated / sunset of the manifest (and of an orphaned runtime) onto the versions
        if ($row = Runtime::query()->find($runtime)) {
            app(RuntimeLifecycle::class)->apply($row, $manifest);
        }

        return $lines;
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
     * Versions (tags) of an image repository: clean X.Y or X.Y.Z tags and their GPU builds
     * (X.Y-gpu); "latest", other suffixes and pre-release tags are skipped.
     *
     * @return array{versions: string[], gpu: string[], complete: bool}
     */
    private function discoverVersions(OciRegistryClient $client, string $repository): array
    {
        $result = $client->tags($repository, self::MAX_TAG_PAGES);

        if (empty($result['tags'])) {
            return ['versions' => [], 'gpu' => [], 'complete' => false];
        }

        $versions = [];
        $gpu = [];

        foreach ($result['tags'] as $tag) {
            if (! preg_match('/^(\d+\.\d+(\.\d+)?)('.preg_quote(self::GPU_SUFFIX, '/').')?$/', $tag, $m)) {
                continue;
            }

            if (isset($m[3])) {
                $gpu[] = $tag;
            } else {
                $versions[] = $tag;
            }
        }

        usort($versions, 'version_compare');

        return ['versions' => array_values(array_unique($versions)), 'gpu' => array_values(array_unique($gpu)), 'complete' => $result['complete']];
    }
}
