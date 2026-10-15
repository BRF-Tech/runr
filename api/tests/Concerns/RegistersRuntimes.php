<?php

namespace Tests\Concerns;

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Services\GitService;
use App\Services\Runtimes\RuntimeRegistry;
use Symfony\Component\Yaml\Yaml;

/**
 * Runtime plugin fixtures: manifests in the shape of examples/runtimes (C exec + build, Bash exec,
 * Q persistent built from a Dockerfile, an http runtime with its own server), registration
 * through the registry, and an XaaS project of a runtime in a fake region.
 */
trait RegistersRuntimes
{
    protected function cManifest(array $override = []): string
    {
        return $this->yaml(array_replace_recursive([
            'apiVersion' => 'runr.sh/v1',
            'kind' => 'Runtime',
            'id' => 'c',
            'name' => 'C',
            'language' => 'c',
            'extensions' => ['c', 'h'],
            'versions' => [['version' => '17', 'image' => 'ghcr.io/brf-tech/reqs/alpine:3.21', 'default' => true]],
            'handler' => ['file' => 'main', 'pattern' => 'int\s+main\s*\('],
            'template' => ['files' => ['{file}' => "#include <stdio.h>\nint main(void) { puts(\"Hello\"); return 0; }\n"]],
            'build' => [
                'image' => '{image}',
                'command' => 'apk add --no-cache gcc musl-dev >/dev/null && gcc -O2 -static -o /build/out/handler {file}',
                'runImage' => 'ghcr.io/brf-tech/reqs/alpine:3.21',
                'source' => false,
            ],
            'run' => ['mode' => 'exec', 'input' => 'raw', 'command' => ['/app/handler']],
        ], $override));
    }

    protected function bashManifest(array $override = []): string
    {
        return $this->yaml(array_replace_recursive([
            'apiVersion' => 'runr.sh/v1',
            'kind' => 'Runtime',
            'id' => 'bash',
            'name' => 'Bash',
            'language' => 'shell',
            'extensions' => ['sh', 'bash'],
            'versions' => [['version' => '5.2', 'image' => 'docker.io/library/bash:5.2', 'default' => true]],
            'template' => ['files' => ['{file}' => "echo hello\n"]],
            'run' => ['mode' => 'exec', 'input' => 'raw', 'command' => ['bash', '/app/code/{file}']],
        ], $override));
    }

    protected function qManifest(array $override = []): string
    {
        return $this->yaml(array_replace_recursive([
            'apiVersion' => 'runr.sh/v1',
            'kind' => 'Runtime',
            'id' => 'q',
            'name' => 'Q',
            'extensions' => ['q'],
            'versions' => [['version' => '1.0', 'dockerfile' => 'Dockerfile', 'default' => true]],
            'template' => ['files' => ['{file}' => "print \"Hello\"\n"]],
            'run' => ['mode' => 'persistent', 'command' => ['q', '/app/code/{file}'], 'workers' => 2],
        ], $override));
    }

    /** An http runtime whose image serves the contract itself (schedule through the wrapper). */
    protected function serverManifest(array $override = []): string
    {
        return $this->yaml(array_replace_recursive([
            'apiVersion' => 'runr.sh/v1',
            'kind' => 'Runtime',
            'id' => 'srv',
            'name' => 'Server',
            'extensions' => ['txt'],
            'versions' => [['version' => '1', 'image' => 'ghcr.io/acme/srv:1', 'default' => true]],
            'run' => ['mode' => 'http', 'command' => ['/srv', '--code', '/app/code/{file}']],
        ], $override));
    }

    protected function qFiles(): array
    {
        return [
            'Dockerfile' => "FROM ghcr.io/brf-tech/reqs/golang:1.23-alpine AS build\nCOPY go.mod main.go ./\nRUN go build -o /q .\nFROM ghcr.io/brf-tech/reqs/alpine:3.21\nCOPY --from=build /q /usr/local/bin/q\n",
            'go.mod' => "module example.com/q\n\ngo 1.23\n",
            'main.go' => "package main\n\nfunc main() {}\n",
        ];
    }

    /** YAML of a manifest; null values (an override's way to drop a key) are left out. */
    protected function yaml(array $data): string
    {
        $strip = function (array $node) use (&$strip): array {
            $out = [];
            foreach ($node as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $out[$key] = is_array($value) ? $strip($value) : $value;
            }

            return array_is_list($node) ? array_values($out) : $out;
        };

        return Yaml::dump($strip($data), 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    protected function registerRuntime(string $manifest, array $files = [], string $source = Runtime::SOURCE_PLATFORM, ?array $regions = null): Runtime
    {
        return app(RuntimeRegistry::class)->register($source, $manifest, $files, $regions, 'test-user');
    }

    /**
     * An XaaS project on a runtime in a region with namespace "fn" (kubeconfig only when the
     * test talks to a fake cluster). Code files come from a mocked GitService.
     *
     * @return array{0: Project, 1: Version}
     */
    protected function runtimeProject(string $runtime, string $trigger = 'http', array $files = ['main.txt' => 'x'], array $regionAttributes = [], ?string $buildImage = null): array
    {
        static $n = 0;
        $n++;

        $region = KnativeRegion::create(array_merge([
            'name' => "rt-{$n}",
            'project_namespace' => 'fn',
            'is_active' => true,
        ], $regionAttributes));

        $project = Project::create([
            'name' => "fn-{$runtime}-{$n}",
            'service_type' => 'xaas',
            'runtime' => $runtime,
        ]);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        $manifest = app(RuntimeRegistry::class)->require($runtime);
        XaasConfig::create([
            'project_id' => $project->id,
            'filename' => $manifest->defaultFilename(),
            'handler' => $manifest->handlerFunction(),
            'trigger_type' => $trigger,
            'schedule' => $trigger === 'schedule' ? '*/5 * * * *' : null,
            'queue_stream' => $trigger === 'queue' ? 'orders' : null,
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
        ]);

        $this->mock(GitService::class, fn ($mock) => $mock->shouldReceive('getAllFilesAtVersion')->andReturn($files));

        $version = Version::create([
            'project_id' => $project->id,
            'number' => 1,
            'commit_id' => 'abc',
            'created_by' => 'dev',
            'build_status' => 'ready',
            'build_image' => $buildImage,
        ]);

        return [$project->fresh(['xaasConfig', 'knativeRegion']), $version];
    }

    /** Executor versions of the built-in runtimes, as executor:sync lists them. */
    protected function seedBuiltinVersions(): void
    {
        foreach ([['python', '3.14'], ['nodejs', '24.14'], ['go', '1.26'], ['php', '8.4']] as [$runtime, $version]) {
            ExecutorImage::updateOrCreate(['runtime' => $runtime, 'version' => $version], [
                'docker_image' => "ghcr.io/brf-tech/exec-{$runtime}:{$version}",
                'is_default' => true,
                'is_active' => true,
            ]);
        }
    }
}
