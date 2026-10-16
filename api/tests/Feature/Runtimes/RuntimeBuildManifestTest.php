<?php

namespace Tests\Feature\Runtimes;

use App\Jobs\BuildVersionJob;
use App\Jobs\CheckRuntimeImageBuildJob;
use App\Models\Runtime;
use App\Models\RuntimeBuild;
use App\Services\FunctionBuildService;
use App\Services\Manifests\ZotManifests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\FakesBuildCluster;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Function builds per runtime manifest (FunctionBuildService): the generated Dockerfile and
 * Kaniko Job for compiled exec runtimes (C), http runtimes with their own server, the built-in
 * Go and dependency builds, and a version built from a Dockerfile that waits for its image.
 */
class RuntimeBuildManifestTest extends TestCase
{
    use FakesBuildCluster, RefreshDatabase, RegistersRuntimes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBuildCluster();
        // Regions of this release: ghcr.io images come through the Zot pull-through cache
        $this->zotConfig = json_encode(ZotManifests::mirrorConfig());
    }

    private function job(): array
    {
        return $this->created('jobs');
    }

    public function test_a_compiled_exec_runtime_builds_with_the_wrapper_baked_in(): void
    {
        $this->registerRuntime($this->cManifest());
        $this->submit($this->buildVersion('c', null, 'internal', ['main.c' => 'int main(void) { return 0; }']));

        $dockerfile = $this->dockerfile();
        $this->assertSame([self::ZOT.'/ghcr.io/brf-tech/runr/reqs/alpine:3.21', self::ZOT.'/ghcr.io/brf-tech/runr/reqs/alpine:3.21'], $this->fromLines());
        $this->assertStringContainsString('RUN mkdir -p /build/out && apk add --no-cache gcc musl-dev >/dev/null && gcc -O2 -static -o /build/out/handler main.c', $dockerfile);
        $this->assertStringContainsString('COPY --from=build /build/out/ /app/', $dockerfile);
        $this->assertStringNotContainsString('COPY code/ /app/code/', $dockerfile); // build.source: false
        $this->assertStringContainsString('COPY .runr/runr-wrapper /runr/runr-wrapper', $dockerfile);
        $this->assertStringContainsString('RUNR_WRAPPER_MODE=exec', $dockerfile);
        $this->assertStringContainsString('RUNR_COMMAND="[\"/app/handler\"]"', $dockerfile);
        $this->assertStringContainsString('RUNR_INPUT=raw', $dockerfile);
        $this->assertStringEndsWith('ENTRYPOINT ["/runr/runr-wrapper"]', $dockerfile);

        // the wrapper reaches the build context through an init container of the Kaniko Job
        $init = $this->job()['spec']['template']['spec']['initContainers'];
        $this->assertSame(['copy-src', 'runr-wrapper'], array_column($init, 'name'));
        $this->assertSame('ghcr.io/brf-tech/runr/wrapper:1.1.0', $init[1]['image']);
        $this->assertSame(['/runr-wrapper', 'install', '/workspace/.runr/runr-wrapper'], $init[1]['command']);
        $this->assertStringContainsString('cp "/src/code---main.c" "/workspace/code/main.c"', $init[0]['command'][2]);
    }

    public function test_an_http_runtime_keeps_its_server_as_entrypoint(): void
    {
        // run.command: the nulls drop the base manifest's further items (overrides merge lists by index)
        $this->registerRuntime($this->serverManifest(['build' => [
            'image' => 'docker.io/library/rust:1.83-alpine',
            'command' => 'rustc -O -o /build/out/server {file}',
            'runImage' => 'ghcr.io/brf-tech/runr/reqs/alpine:3.21',
            'source' => false,
        ], 'run' => ['command' => ['/app/server', null, null]]]));
        $this->submit($this->buildVersion('srv', null, 'internal', ['main.txt' => 'fn main() {}']));

        $dockerfile = $this->dockerfile();
        $this->assertSame(['docker.io/library/rust:1.83-alpine', self::ZOT.'/ghcr.io/brf-tech/runr/reqs/alpine:3.21'], $this->fromLines());
        $this->assertStringContainsString('rustc -O -o /build/out/server main.txt', $dockerfile);
        $this->assertStringEndsWith('ENTRYPOINT ["/app/server"]', $dockerfile);
        // its schedule trigger runs through the wrapper, so the image carries it
        $this->assertStringContainsString('COPY .runr/runr-wrapper /runr/runr-wrapper', $dockerfile);
        $this->assertStringNotContainsString('RUNR_WRAPPER_MODE', $dockerfile);
    }

    public function test_the_go_built_in_builds_like_before(): void
    {
        $this->submit($this->buildVersion('go', null, 'internal', ['main.go' => 'package main']));

        $dockerfile = $this->dockerfile();
        $data = $this->created('configmaps')['data'];

        $this->assertSame([self::ZOT.'/ghcr.io/brf-tech/runr/exec-go:1.26', self::ZOT.'/ghcr.io/brf-tech/runr/reqs/alpine:3.20'], $this->fromLines());
        $this->assertStringContainsString('go mod init runr-function && go mod tidy && CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags="-s -w" -o /build/out/handler .', $dockerfile);
        $this->assertStringContainsString('ENV RUNR_MODE=http', $dockerfile);
        $this->assertStringEndsWith('ENTRYPOINT ["/app/handler"]', $dockerfile);
        $this->assertStringNotContainsString('runr-wrapper', $dockerfile);
        // the http wrapper source of the trigger sits next to the code
        $this->assertStringContainsString('result, err := Handler(event, context)', $data['code---runr_wrapper_.go']);
        $this->assertSame(['copy-src'], array_column($this->job()['spec']['template']['spec']['initContainers'], 'name'));
    }

    public function test_dependency_builds_of_the_built_ins_are_unchanged(): void
    {
        $this->submit($this->buildVersion('python', null, 'internal', ['main.py' => 'x', 'requirements.txt' => "redis\n"]));

        $this->assertSame(implode("\n", [
            'FROM '.self::ZOT.'/ghcr.io/brf-tech/runr/exec-python:3.14 AS build',
            'WORKDIR /build/code',
            'COPY code/ /build/code/',
            'RUN pip install --no-cache-dir --target=/build/vendor -r /build/code/requirements.txt',
            '',
            'FROM '.self::ZOT.'/ghcr.io/brf-tech/runr/exec-python:3.14',
            'WORKDIR /app',
            'COPY code/ /app/code/',
            'COPY --from=build /build/vendor /app/vendor',
            'ENV RUNR_MODE=http RUNR_HANDLER=handler RUNR_FILENAME=main.py PYTHONPATH=/app/vendor',
            'EXPOSE 8080',
        ]), $this->dockerfile());
    }

    public function test_a_version_built_from_a_dockerfile_waits_for_its_region_image(): void
    {
        Bus::fake([CheckRuntimeImageBuildJob::class, BuildVersionJob::class]);
        $runtime = $this->registerRuntime($this->qManifest(['build' => ['command' => 'q -check {file}', 'runImage' => '{image}']]), $this->qFiles());
        $version = $this->buildVersion('q', null, 'internal', ['main.q' => 'print "hi"']);

        // no region image yet: the runtime image build starts, the function build waits
        (new BuildVersionJob($version))->handle(app(FunctionBuildService::class));

        $build = RuntimeBuild::sole();
        $this->assertSame(['q', '1.0', 'building'], [$build->runtime_id, $build->version, $build->status]);
        $this->assertStringStartsWith(self::ZOT.'/runr-runtimes/q:1.0-', $build->image);
        $this->assertSame('pending', $version->fresh()->build_status);
        Bus::assertDispatched(BuildVersionJob::class, fn (BuildVersionJob $job) => $job->waited === 1);
        Bus::assertDispatched(CheckRuntimeImageBuildJob::class);

        // once the region image is ready, the function builds FROM it
        $build->update(['status' => RuntimeBuild::STATUS_READY]);
        $this->submit($version->fresh());
        $this->assertSame([$build->image, $build->image], $this->fromLines());
        $this->assertSame('building', $version->fresh()->build_status);
    }
}
