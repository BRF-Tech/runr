<?php

namespace Tests\Concerns;

use App\Jobs\BuildVersionJob;
use App\Models\KnativeRegion;
use App\Models\Version;
use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\KnativeInstallService;
use App\Services\KnativeNodeService;
use App\Services\KnativeRedisService;
use App\Services\RegionMigrateService;
use App\Services\SandboxInstallService;
use App\Services\Wasm\WasmInstallService;
use App\Services\ZotRegistryService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Testing\Fakes\BusFake;
use Mockery;
use Symfony\Component\Yaml\Yaml;

/**
 * The old and the new cluster of a region migration, faked over HTTP with state: a Knative
 * Service exists once applied and until deleted, and reads back ready on its current revision;
 * lists are empty, other reads find nothing, writes succeed. Every call is recorded, and the
 * last body applied to each Knative Service.
 */
trait FakesRegionMigrationClusters
{
    private const OLD = 'https://old.cluster.test:6443';

    private const NEW = 'https://new.cluster.test:6443';

    /** @var array<string, list<string>> host => Knative Services that exist there */
    private array $services = ['old.cluster.test' => [], 'new.cluster.test' => []];

    /** @var list<string> "METHOD host path" of the cluster calls */
    private array $calls = [];

    /** @var array<string, array<string, mixed>> "host service" => the Knative Service last applied */
    private array $applied = [];

    private function kubeconfig(string $server): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => $server, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    /**
     * The whole move of $region to the cluster at $server (RegionMigrateService::migrate) at 10:00:
     * Knative already runs there, its Zot serves at $newRegistry and none of the region's selected
     * workers is a node there; the other installs do nothing. The builds the move submits on the
     * new cluster are only queued (BuildVersionJob faked, unless the test faked the bus itself):
     * builtOnTheNewCluster() finishes one.
     */
    private function migrateRegion(KnativeRegion $region, string $newRegistry, string $server = self::NEW): void
    {
        if (! Bus::getFacadeRoot() instanceof BusFake) {
            Bus::fake([BuildVersionJob::class]);
        }
        $installer = Mockery::mock(KnativeInstallService::class);
        $installer->shouldReceive('runningKnative')->andReturn(['operator' => true, 'serving' => true]);
        $zot = Mockery::mock(ZotRegistryService::class);
        $zot->shouldReceive('detectExisting')->andReturn(null);
        $zot->shouldReceive('install')->andReturn(['address' => $newRegistry]);
        $nodes = Mockery::mock(KnativeNodeService::class);
        $nodes->shouldReceive('applyWorkerSelection')->andReturnUsing(fn (KnativeRegion $region, array $keep) => $region->update(['selected_workers' => $keep]));
        $this->app->instance(KnativeInstallService::class, $installer);
        $this->app->instance(ZotRegistryService::class, $zot);
        $this->app->instance(KnativeNodeService::class, $nodes);
        foreach ([KnativeRedisService::class, SandboxInstallService::class, WasmInstallService::class, GpuInstallService::class] as $class) {
            $this->app->instance($class, Mockery::mock($class)->shouldIgnoreMissing());
        }

        Carbon::setTestNow('2026-10-19 10:00:00');
        app(RegionMigrateService::class)->migrate($region, $this->kubeconfig($server));
    }

    /**
     * The build the move submitted for $version on the new cluster ends: pushed to $image (ready),
     * or failed with $error.
     */
    private function builtOnTheNewCluster(Version $version, string $image, ?string $error = null): void
    {
        $version->forceFill(['build_status' => 'building', 'build_image' => $image])->save();
        $version->forceFill($error === null
            ? ['build_status' => 'ready', 'build_error' => null]
            : ['build_status' => 'failed', 'build_error' => $error])->save();
    }

    /** The image of the Knative Service last applied to $service on the cluster at $host. */
    private function appliedImage(string $host, string $service): ?string
    {
        return $this->applied["{$host} {$service}"]['spec']['template']['spec']['containers'][0]['image'] ?? null;
    }

    private function fakeClusters(): void
    {
        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = $request->method().' '.$host.' '.$path;

            $service = preg_match('#^/apis/serving\.knative\.dev/v1/namespaces/[^/]+/services/([^/]+)$#', $path, $m) ? $m[1] : null;
            if ($service !== null && in_array($request->method(), ['PATCH', 'POST', 'PUT'], true)) {
                $this->services[$host][] = $service;
                $this->applied["{$host} {$service}"] = (array) json_decode($request->body(), true);
            }
            if ($service !== null && $request->method() === 'DELETE') {
                $this->services[$host] = array_values(array_diff($this->services[$host] ?? [], [$service]));
            }
            if ($service !== null && $request->method() === 'GET') {
                return in_array($service, $this->services[$host] ?? [], true)
                    ? Http::response([
                        'metadata' => ['name' => $service, 'generation' => 1],
                        'status' => [
                            'observedGeneration' => 1,
                            'latestCreatedRevisionName' => "{$service}-00001",
                            'latestReadyRevisionName' => "{$service}-00001",
                            'conditions' => [['type' => 'Ready', 'status' => 'True']],
                        ],
                    ])
                    : Http::response(['kind' => 'Status', 'code' => 404], 404);
            }
            if ($path === '/version') {
                return Http::response(['major' => '1', 'minor' => '33', 'gitVersion' => 'v1.33.0']);
            }
            if ($request->method() === 'GET') {
                return str_contains($request->url(), 'labelSelector')
                    ? Http::response(['items' => []])
                    : Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            return Http::response(['kind' => 'Status', 'status' => 'Success']);
        });
    }
}
