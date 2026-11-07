<?php

namespace Tests\Feature\Runtimes;

use App\Models\ExecutorImage;
use App\Models\RuntimeSource;
use App\Services\KubernetesClient;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\ZotRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * The node pre-pull at registry setup (docs/runtimes.md, Built-in catalog) pulls the image the
 * functions of each active runtime version run on: the version image, or for a runtime with a
 * build its run image (build.runImage) — not the SDK or toolchain the build compiles with, which
 * Kaniko fetches through the region registry and no function runs on (.NET's 1.3 GB SDK, Go's).
 * A private run image is pulled with its source's credentials.
 */
class RuntimePrepullTest extends TestCase
{
    use RefreshDatabase, RegistersRuntimes;

    private function image(string $runtime, string $version, bool $active = true): void
    {
        ExecutorImage::create([
            'runtime' => $runtime, 'version' => $version, 'docker_image' => "ghcr.io/brf-tech/runr/exec-{$runtime}:{$version}",
            'is_default' => false, 'is_active' => $active, 'category' => 'general',
        ]);
    }

    public function test_the_pre_pull_takes_the_image_a_function_runs_on_not_the_sdk(): void
    {
        $this->image('python', '3.14');
        $this->image('go', '1.26');
        $this->image('go', '1.27');
        $this->image('dotnet', '10.0');
        $this->image('dotnet', '11.0');
        $this->image('dotnet', '9.0', active: false);
        $this->image('java', '25.0');
        $this->image('bun', '1.4');
        $registry = app(RuntimeRegistry::class);

        $images = $registry->prepullRunImages();

        // .NET: the chiseled runtime-deps image of each version, not the SDK image (exec-dotnet).
        // Built from the version, as the manifest's runImage is: 11.0 and 9.0 are fixtures, not
        // images of the reqs mirror (a literal reference would fail ci/check-reqs-mirror.sh)
        $runtimeDeps = fn (string $version): string => "ghcr.io/brf-tech/runr/reqs/dotnet-runtime-deps:{$version}-chiseled-extra";
        $this->assertSame('dotnet', $images[$runtimeDeps('10.0')] ?? null);
        $this->assertSame('dotnet', $images[$runtimeDeps('11.0')] ?? null);
        $this->assertArrayNotHasKey('ghcr.io/brf-tech/runr/exec-dotnet:10.0', $images);
        $this->assertArrayNotHasKey($runtimeDeps('9.0'), $images, 'an inactive version');
        // Go: its run image once for both versions, not the toolchain images
        $goRun = (string) $registry->manifest('go')->build()['runImage'];
        $this->assertStringNotContainsString('exec-go', $goRun);
        $this->assertSame('go', $images[$goRun] ?? null);
        $this->assertSame([$goRun], array_keys(array_filter($images, fn (string $runtime) => $runtime === 'go')));
        // Java runs on its version image (runImage "{image}"); Python and Bun have no build
        $this->assertSame('java', $images['ghcr.io/brf-tech/runr/exec-java:25.0'] ?? null);
        $this->assertSame('python', $images['ghcr.io/brf-tech/runr/exec-python:3.14'] ?? null);
        $this->assertSame('bun', $images['ghcr.io/brf-tech/runr/exec-bun:1.4'] ?? null);
        $this->assertCount(6, $images);

        $this->assertSame(array_keys($images), $registry->prepullImages());
    }

    public function test_a_platform_runtime_with_a_build_is_pre_pulled_by_its_run_image(): void
    {
        $this->registerRuntime($this->cManifest([
            'versions' => [['version' => '17', 'image' => 'ghcr.io/acme/gcc:17', 'default' => true]],
            'build' => ['runImage' => 'ghcr.io/acme/c-run:{version}'],
        ]));
        $this->registerRuntime($this->bashManifest());

        $images = app(RuntimeRegistry::class)->prepullRunImages();

        $this->assertSame('c', $images['ghcr.io/acme/c-run:17'] ?? null);
        $this->assertArrayNotHasKey('ghcr.io/acme/gcc:17', $images, 'the build image');
        $this->assertSame('bash', $images['docker.io/library/bash:5.2'] ?? null, 'a runtime without a build runs on its version image');
    }

    public function test_a_private_run_image_is_pulled_with_its_sources_credentials(): void
    {
        $source = RuntimeSource::query()->create([
            'name' => 'acme', 'kind' => RuntimeSource::KIND_OCI, 'scope' => RuntimeSource::SCOPE_PLATFORM,
            'url' => 'registry.acme.test/runtimes/catalog:stable',
            'credentials' => ['registry' => ['username' => 'bot', 'password' => 'reg-Secret-Pa55word-xyz']],
        ]);
        $runtime = $this->registerRuntime($this->cManifest([
            'versions' => [['version' => '17', 'image' => 'registry.acme.test/runtimes/gcc:17', 'default' => true]],
            'build' => ['runImage' => 'registry.acme.test/runtimes/c-run:{version}'],
        ]));
        $runtime->forceFill(['source_id' => $source->id])->save();
        app(RuntimeRegistry::class)->forget();
        $this->image('python', '3.14');

        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $calls[] = ['method' => $request->method(), 'path' => $path, 'body' => json_decode($request->body(), true) ?? []];
            if ($request->method() === 'GET' && str_ends_with($path, '/daemonsets/runr-image-prepull')) {
                return Http::response(['status' => ['desiredNumberScheduled' => 1, 'numberReady' => 1]]);
            }

            return Http::response(['kind' => 'Status'], 200);
        });

        app(ZotRegistryService::class)->prepullImages(new KubernetesClient($this->kubeconfig()), 'runr-registry');

        $daemonSet = collect($calls)->first(fn ($c) => $c['method'] === 'PATCH' && str_ends_with($c['path'], '/daemonsets/runr-image-prepull'))['body'] ?? null;
        $this->assertNotNull($daemonSet, 'the pre-pull DaemonSet was applied');
        $pull = collect($daemonSet['spec']['template']['spec']['initContainers'])->firstWhere('name', 'pull-all');
        $script = end($pull['command']);
        $key = 'RUNR_PULL_CREDS_S'.strtoupper($source->id);
        $this->assertStringContainsString("crictl pull --creds \"\${$key}\" 'registry.acme.test/runtimes/c-run:17'", $script);
        $this->assertStringContainsString("crictl pull 'ghcr.io/brf-tech/runr/exec-python:3.14'", $script);
        $this->assertStringNotContainsString('registry.acme.test/runtimes/gcc:17', $script, 'the build image is not pulled onto the nodes');

        $secret = collect($calls)->first(fn ($c) => $c['method'] === 'PATCH' && str_ends_with($c['path'], '/secrets/runr-image-prepull-creds'))['body'] ?? null;
        $this->assertSame(base64_encode('bot:reg-Secret-Pa55word-xyz'), $secret['data']['s'.strtolower($source->id)] ?? null);
        $this->assertStringNotContainsString('reg-Secret-Pa55word-xyz', json_encode($daemonSet), 'the password stays in the Secret');
    }

    private function kubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }
}
