<?php

namespace App\Console\Commands\Concerns;

use App\Models\KnativeRegion;
use App\Services\RegistryImageLifecycleService;
use App\Services\Runtimes\RuntimeRegistry;

/**
 * E2E of uploaded images and wasm-exec (test:e2e --suite=images; runtime/ci.sh test-images):
 *  1. uploads a `docker save` archive of a small HTTP server (runtime/nginx-test, built by
 *     prepare_images_e2e) and the Q interpreter compiled with TinyGo to WASI (q.wasm) through
 *     the API in chunks — the receiver Job in the region pushes them into the region registry —
 *     and checks the uploaded images (digest, kind, platforms);
 *  2. installs WebAssembly support (spin shim) on the region;
 *  3. deploys a CaaS project from the uploaded image, a wasm-exec function in C (built in the
 *     region with wasi-sdk) and a function of Q (Wasm) — the platform runtime of
 *     examples/wasm/q-exec whose version is the uploaded interpreter — and calls all three;
 *  4. deletes the projects, the runtime and the uploaded images (their manifests leave the
 *     registry) and uninstalls WebAssembly support.
 *
 * Files: --examples/wasm (examples/wasm) and /tmp/runr-images (echo.tar, q.wasm), copied into the
 * API container by prepare_images_e2e (runtime/lib/test-images.sh).
 */
trait E2eTestImagesTrait
{
    private function runImagesSuite(string $regionId): array
    {
        $examples = rtrim((string) ($this->option('examples') ?: '/tmp/runr-examples'), '/').'/wasm';
        $files = rtrim((string) (getenv('RUNR_E2E_IMAGES_DIR') ?: '/tmp/runr-images'), '/');

        $this->info('');
        $this->info('── Uploaded images + wasm-exec ──');

        foreach (["{$files}/echo.tar", "{$files}/q.wasm", "{$examples}/c-exec/main.c", "{$examples}/q-exec/main.q", "{$examples}/q-exec/runtime.yaml"] as $needed) {
            if (! is_file($needed)) {
                $this->error("{$needed} not found (runtime/lib/test-images.sh prepares it)");

                return [$this->failedEntry('images-files', "missing {$needed}", $regionId)];
            }
        }

        // 1. uploads
        $tar = $this->finishTest($this->imageUploadEntry('images-upload-tar', "{$files}/echo.tar", 'runr-e2e-echo:upload', $regionId, 'image'));
        $wasm = $this->finishTest($this->imageUploadEntry('images-upload-wasm', "{$files}/q.wasm", 'q-interpreter:1.0', $regionId, 'wasm'));
        if ($tar['steps']['verify'] !== true || $wasm['steps']['verify'] !== true) {
            return [$tar, $wasm];
        }

        // 2. WebAssembly support
        $install = $this->finishTest($this->wasmInstall($regionId));
        if ($install['steps']['verify'] !== true) {
            return [$tar, $wasm, $install];
        }

        // 3. a CaaS project from the upload, wasm-exec in C, Q on the uploaded interpreter
        $qRuntime = $this->registerQWasmRuntime("{$examples}/q-exec/runtime.yaml");

        $caas = $this->makeTestEntry('images-caas', ['service_type' => 'caas', 'trigger_type' => 'http', 'image' => $tar['image']['image_ref']], $regionId);
        $tests = [
            $caas,
            $this->runtimeEntry('images-wasm-exec-c', 'wasm-exec', 'http', (string) file_get_contents("{$examples}/c-exec/main.c"), $regionId, ['body' => 'Hello, RunR! (from C, Wasm)']),
        ];
        if ($qRuntime === true) {
            $tests[] = $this->runtimeEntry('images-wasm-q', 'q-wasm', 'http', (string) file_get_contents("{$examples}/q-exec/main.q"), $regionId, ['body' => 'Hello, RunR! (from Q)']);
        } else {
            $tests[] = $this->failedEntry('images-wasm-q', "q-wasm runtime: {$qRuntime}", $regionId);
        }

        $this->runTestsSequentially($tests);

        foreach ($tests as &$test) {
            if ($test['steps']['ready'] !== true) {
                $test['steps']['verify'] ??= 'skipped';

                continue;
            }
            $test['steps']['verify'] = $test['name'] === 'images-caas' ? $this->verifyUploadedCaas($test) : $this->verifyWasmHttp($test);
            $state = $test['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("  {$state} {$test['name']}: ".($test['steps']['verify'] === true ? 'verified' : $test['steps']['verify']));
        }
        unset($test);

        // 4. clean up: projects, the runtime, the images (manifests leave the registry), WebAssembly support
        foreach ($tests as $test) {
            if ($test['projectId']) {
                $this->api()->delete("/api/projects/{$test['projectId']}");
            }
        }
        $cleanup = $this->imagesCleanup([$tar['image'], $wasm['image']], $regionId);

        return array_merge([$tar, $wasm, $install], $tests, [$cleanup, $this->finishTest($this->wasmUninstall($regionId, []))]);
    }

    /**
     * Upload a file through the API (chunks forwarded to the region's receiver) and wait for the
     * push. steps: version = started, deploy = chunks sent, ready = pushed, verify = the record.
     */
    private function imageUploadEntry(string $name, string $path, string $imageName, string $regionId, string $kind): array
    {
        $entry = $this->makeTestEntry($name, ['service_type' => 'caas', 'trigger_type' => 'http'], $regionId);
        $entry['startTime'] = microtime(true);
        $entry['image'] = null;
        $size = (int) filesize($path);

        $res = $this->api()->post('/api/images/uploads', [
            'knative_region_id' => $regionId,
            'filename' => basename($path),
            'size' => $size,
            'sha256' => hash_file('sha256', $path),
            'name' => $imageName,
        ]);
        if ($res->status() !== 201) {
            $entry['steps'] = ['version' => "HTTP {$res->status()}: ".mb_substr((string) $res->json('message'), 0, 200), 'deploy' => 'skipped', 'ready' => 'skipped', 'verify' => 'skipped'];

            return $entry;
        }
        $upload = $res->json('data');
        $entry['steps']['version'] = true;

        // the receiver Job starts in the region
        $deadline = time() + 300;
        while (($upload['status'] ?? null) === 'preparing' && time() < $deadline) {
            sleep(2);
            $upload = $this->api()->get("/api/images/uploads/{$upload['id']}")->json('data');
        }
        if (($upload['status'] ?? null) !== 'ready') {
            $entry['steps']['deploy'] = 'receiver: '.($upload['status'] ?? 'unknown').' '.mb_substr((string) ($upload['message'] ?? ''), 0, 200);
            $entry['steps']['ready'] = $entry['steps']['verify'] = 'skipped';

            return $entry;
        }

        $fh = fopen($path, 'rb');
        $chunk = (int) $upload['chunk_size'];
        for ($offset = 0; $offset < $size; $offset += $chunk) {
            fseek($fh, $offset);
            $bytes = (string) fread($fh, $chunk);
            $put = $this->api()->withBody($bytes, 'application/octet-stream')->timeout(180)
                ->put("/api/images/uploads/{$upload['id']}/chunk?offset={$offset}");
            if (! $put->successful()) {
                fclose($fh);
                $entry['steps']['deploy'] = "chunk at {$offset}: HTTP {$put->status()} ".mb_substr((string) $put->json('message'), 0, 200);
                $entry['steps']['ready'] = $entry['steps']['verify'] = 'skipped';

                return $entry;
            }
        }
        fclose($fh);
        $entry['steps']['deploy'] = true;

        $done = $this->api()->post("/api/images/uploads/{$upload['id']}/complete");
        if ($done->status() !== 202) {
            $entry['steps']['ready'] = "complete: HTTP {$done->status()} ".mb_substr((string) $done->json('message'), 0, 200);
            $entry['steps']['verify'] = 'skipped';

            return $entry;
        }

        $deadline = time() + 600;
        do {
            sleep(3);
            $upload = $this->api()->get("/api/images/uploads/{$upload['id']}")->json('data');
        } while (in_array($upload['status'] ?? null, ['verifying', 'pushing'], true) && time() < $deadline);

        if (($upload['status'] ?? null) !== 'completed') {
            $entry['steps']['ready'] = 'status '.($upload['status'] ?? 'unknown').': '.mb_substr((string) ($upload['message'] ?? ''), 0, 300);
            $entry['steps']['verify'] = 'skipped';

            return $entry;
        }
        $entry['steps']['ready'] = true;
        $image = $upload['image'] ?? [];
        $entry['image'] = $image;

        $listed = collect($this->api()->get('/api/images', ['knative_region_id' => $regionId])->json('data') ?? [])->firstWhere('id', $image['id'] ?? null);
        $entry['steps']['verify'] = match (true) {
            ! $listed => 'the uploaded image is not listed',
            ($image['kind'] ?? null) !== $kind => "kind {$image['kind']}, expected {$kind}",
            ! preg_match('/^sha256:[a-f0-9]{64}$/', (string) ($image['digest'] ?? '')) => 'no digest',
            ! str_ends_with((string) ($image['image_ref'] ?? ''), '@'.$image['digest']) => 'the image reference is not pinned by digest',
            default => true,
        };
        $this->line(sprintf('  %s %s: %s %s (%s, %.0f s)', $entry['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>', $name, $image['name'] ?? '?',
            $image['digest'] ?? '', implode(', ', $image['platforms'] ?? []), microtime(true) - $entry['startTime']));

        return $entry;
    }

    /** POST /api/runtimes with the q-wasm manifest (platform runtime on the uploaded interpreter). */
    private function registerQWasmRuntime(string $manifestPath): bool|string
    {
        $res = $this->api()->post('/api/runtimes', ['manifest' => (string) file_get_contents($manifestPath)]);
        if ($res->status() === 201) {
            $this->call('executor:sync');
            app(RuntimeRegistry::class)->forget();

            return true;
        }

        return "HTTP {$res->status()}: ".mb_substr(implode('; ', (array) ($res->json('errors.manifest') ?? [$res->json('message')])), 0, 300);
    }

    private function verifyUploadedCaas(array $test): bool|string
    {
        $result = $this->curlFunction($test['slug'], '/hello');
        if (! is_array($result)) {
            return $result;
        }
        [$status, , $body] = $result;

        return $status === '200' && str_contains($body, '"status":"ok"') ? true : "HTTP {$status}: ".mb_substr($body, 0, 200);
    }

    /** Delete the runtime and the uploaded images; their manifests must leave the registry. */
    private function imagesCleanup(array $images, string $regionId): array
    {
        $entry = $this->makeTestEntry('images-delete', ['service_type' => 'caas', 'trigger_type' => 'http'], $regionId);
        $entry['startTime'] = microtime(true);
        $entry['steps'] = ['version' => true, 'deploy' => null, 'ready' => null, 'verify' => null];

        $this->api()->delete('/api/runtimes/q-wasm');

        $failures = [];
        foreach ($images as $image) {
            $res = $this->api()->delete("/api/images/{$image['id']}");
            if (! $res->successful() || $res->json('registry_removed') !== true) {
                $failures[] = "{$image['name']}: HTTP {$res->status()} ".mb_substr((string) $res->json('message'), 0, 160);
            }
        }
        $entry['steps']['deploy'] = $failures === [] ? true : implode('; ', $failures);

        $left = $this->api()->get('/api/images', ['knative_region_id' => $regionId])->json('data') ?? [];
        $entry['steps']['ready'] = $left === [] ? true : count($left).' image(s) left';

        // the manifests are gone from the registry: the tag no longer resolves
        $region = KnativeRegion::find($regionId);
        $missing = [];
        foreach ($images as $image) {
            $tags = app(RegistryImageLifecycleService::class)->listTags($region, 'runr-uploads/platform/'.$image['repository']);
            if (in_array($image['tag'], $tags, true)) {
                $missing[] = $image['name'];
            }
        }
        $entry['steps']['verify'] = $missing === [] ? true : 'still in the registry: '.implode(', ', $missing);

        $state = $entry['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line("  {$state} images-delete: ".($entry['steps']['verify'] === true ? 'images and manifests removed' : $entry['steps']['verify']));

        return $this->finishTest($entry);
    }
}
