<?php

namespace Tests\Concerns;

use App\Models\ExecutorImage;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\FunctionBuildService;
use App\Services\GitService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/**
 * Submits Kaniko builds (FunctionBuildService) against a fake region cluster and
 * reads back the generated Dockerfile and Job. Executor images: python 3.12 / 3.14 (default),
 * nodejs 22.22 / 24.14 (default), php 8.2 / 8.4 (default), go 1.26, pytorch 2.3 — like
 * executor:sync lists them (ghcr.io/runrsh/exec-{runtime}:{version}).
 */
trait FakesBuildCluster
{
    protected const ZOT = '10.43.0.5:5000';

    /** Zot config.json of the region's registry (runr-registry/runr-zot-config); null = none */
    protected ?string $zotConfig = null;

    /** @var list<array{method: string, path: string, body: array}> */
    protected array $calls = [];

    private int $builds = 0;

    protected function setUpBuildCluster(): void
    {
        $images = [
            ['python', '3.12', false], ['python', '3.14', true],
            ['nodejs', '22.22', false], ['nodejs', '24.14', true],
            ['php', '8.2', false], ['php', '8.4', true],
            ['go', '1.26', true],
            ['pytorch', '2.3', true],
        ];
        foreach ($images as $i => [$runtime, $version, $default]) {
            ExecutorImage::create([
                'runtime' => $runtime,
                'version' => $version,
                'docker_image' => "ghcr.io/runrsh/exec-{$runtime}:{$version}",
                'is_default' => $default,
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            $this->calls[] = ['method' => $method, 'path' => $path, 'body' => json_decode($request->body(), true) ?? []];

            if ($method === 'GET' && $path === '/api/v1/namespaces/runr-registry/configmaps/runr-zot-config') {
                return $this->zotConfig === null
                    ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                    : Http::response(['data' => ['config.json' => $this->zotConfig]]);
            }

            return $method === 'GET'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response(['kind' => 'Status'], 200);
        });
    }

    protected function buildVersion(string $runtime, ?string $runtimeVersion, string $registryMode = 'internal', array $files = []): Version
    {
        $n = ++$this->builds;

        $region = KnativeRegion::create([
            'name' => "fn-{$n}",
            'project_namespace' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
            'registry_mode' => $registryMode,
            'registry_status' => 'ready',
            'registry_address' => $registryMode === 'internal' ? self::ZOT : 'registry.example.test',
            'registry_namespace' => 'runr-registry',
        ]);

        $project = Project::create([
            'name' => "demo-{$runtime}-{$n}",
            'service_type' => 'faas',
            'runtime' => $runtime,
            'runtime_version' => $runtimeVersion,
        ]);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http']);

        $this->mock(GitService::class, fn ($mock) => $mock->shouldReceive('getAllFilesAtVersion')->andReturn($files ?: ['main.txt' => 'x']));

        return Version::create(['project_id' => $project->id, 'number' => 1, 'commit_id' => 'abc', 'created_by' => 'dev', 'build_status' => 'pending']);
    }

    protected function submit(Version $version): void
    {
        $this->calls = [];
        app(FunctionBuildService::class)->submitBuild($version);
    }

    /** Body of the first resource of this plural created in the project namespace. */
    protected function created(string $plural): array
    {
        $call = collect($this->calls)->first(fn ($c) => $c['method'] === 'POST' && str_ends_with($c['path'], "/namespaces/fn/{$plural}"));
        $this->assertNotNull($call, "no {$plural} created");

        return $call['body'];
    }

    protected function dockerfile(): string
    {
        return $this->created('configmaps')['data']['Dockerfile'];
    }

    /** @return list<string> the FROM images of the generated Dockerfile */
    protected function fromLines(): array
    {
        preg_match_all('/^FROM (\S+)/m', $this->dockerfile(), $m);

        return $m[1];
    }
}
