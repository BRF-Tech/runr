<?php

namespace App\Console\Commands;

use App\Models\ExecutorImage;
use App\Models\Runtime;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\Placeholders;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Runtime versions into executor_images: built-in runtimes from Docker Hub tags of their
 * manifest's versionDiscovery repository (EOL dates from endoflife.date), platform and custom
 * runtimes from the versions their manifest lists.
 */
class SyncExecutorImagesCommand extends Command
{
    protected $signature = 'executor:sync {--force : Re-check all runtimes even if recently synced}';

    protected $description = 'Sync runtime versions (built-in catalog from Docker Hub, registered runtimes from their manifests) into executor_images';

    public function handle(RuntimeRegistry $registry, BuiltinCatalog $catalog): int
    {
        // Built-in runtime rows (state: enabled / regions) for the release catalog
        $registry->syncBuiltins();

        // Built-ins: versions discovered from Docker Hub (manifest versionDiscovery), offered
        // ones only (EXECUTOR_RUNTIMES)
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
        $this->info("── {$runtime} ({$discovery['repository']}) ──");

        $result = $this->discoverVersions($discovery['repository']);

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
            $this->warn('  Could not fetch tags from Docker Hub, skipping.');

            return;
        }

        // Default = the highest version (last after sorting)
        $defaultVersion = end($result['versions']);

        $sortOrder = 0;
        $discoveredVersions = [];

        foreach ($result['versions'] as $ver) {
            $sortOrder++;
            $discoveredVersions[] = $ver;

            $image = ExecutorImage::updateOrCreate(
                ['runtime' => $runtime, 'version' => $ver],
                [
                    'docker_image' => Placeholders::render($discovery['image'], ['version' => $ver]),
                    'is_default' => ($ver === $defaultVersion),
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                ],
            );

            $eolDate = $discovery['eol'] ? $this->fetchEolDate($discovery['eol'], $ver) : null;
            $image->update(['eol_at' => $eolDate]);

            $status = $image->wasRecentlyCreated ? 'NEW' : 'ok';
            $default = $ver === $defaultVersion ? ' (default)' : '';
            $eolLabel = $eolDate ? " [EOL: {$eolDate}]" : '';
            $this->line("  {$status}: {$ver}{$default}{$eolLabel}");
        }

        // Versions no longer on Docker Hub are deactivated (soft)
        if ($result['complete']) {
            $deactivated = ExecutorImage::where('runtime', $runtime)
                ->whereNotIn('version', $discoveredVersions)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            if ($deactivated > 0) {
                $this->warn("  Deactivated {$deactivated} version(s) no longer on Docker Hub.");
            }
        } else {
            $this->warn('  Partial data from Docker Hub, skipping cleanup.');
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
     * Discover available versions (tags) from Docker Hub for a repo.
     * Only accepts clean semver tags (X.Y or X.Y.Z). Skips "latest", suffixed, and pre-release tags.
     *
     * @return array{versions: string[], complete: bool}
     */
    private function discoverVersions(string $repo): array
    {
        $result = $this->fetchTags($repo);

        if (empty($result['tags'])) {
            return ['versions' => [], 'complete' => false];
        }

        $versions = [];

        foreach ($result['tags'] as $tag) {
            // Only accept clean numeric versions: X.Y or X.Y.Z
            if (! preg_match('/^\d+\.\d+(\.\d+)?$/', $tag)) {
                continue;
            }

            $versions[] = $tag;
        }

        // Natural version order
        usort($versions, 'version_compare');

        return ['versions' => array_values(array_unique($versions)), 'complete' => $result['complete']];
    }

    /**
     * Fetch all tag names from Docker Hub for a repository (paginated).
     *
     * @return array{tags: string[], complete: bool}
     */
    private function fetchTags(string $repo): array
    {
        $allTags = [];
        $page = 1;
        $complete = true;

        while ($page <= 10) {
            try {
                $response = Http::timeout(30)
                    ->get("https://hub.docker.com/v2/repositories/{$repo}/tags/", [
                        'page' => $page,
                        'page_size' => 100,
                    ]);

                if (! $response->successful()) {
                    $complete = false;
                    break;
                }

                $data = $response->json();
                $results = $data['results'] ?? [];

                if (empty($results)) {
                    break;
                }

                foreach ($results as $entry) {
                    $allTags[] = $entry['name'];
                }

                // Last page?
                if (empty($data['next'])) {
                    break;
                }

                $page++;
            } catch (\Throwable $e) {
                $this->warn("Docker Hub API error (page {$page}): ".$e->getMessage());
                $complete = false;
                break;
            }
        }

        return ['tags' => $allTags, 'complete' => $complete];
    }
}
