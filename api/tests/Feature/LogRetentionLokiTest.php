<?php

namespace Tests\Feature;

use App\Jobs\LogRetentionJob;
use App\Models\KnativeRegion;
use App\Models\LogRetentionConfig;
use App\Models\Project;
use App\Models\Version;
use App\Services\LogRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Log retention deletes from the region's own Loki — RunR's loki-stack (managed)
 * or the Loki the region reuses (loki_url, loki_org_id) — through the region's Kubernetes API
 * service proxy, so a remote region's Loki is reached on that region's cluster.
 */
class LogRetentionLokiTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<Request> */
    private array $sent = [];

    private function kubeconfig(string $server): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => $server, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function fakeLoki(int $status = 204): void
    {
        Http::fake(function (Request $request) use ($status) {
            $this->sent[] = $request;

            return Http::response($status === 204 ? '' : 'deletion is not available for this tenant', $status);
        });
    }

    private function region(string $server, array $attributes = []): KnativeRegion
    {
        $region = KnativeRegion::create([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'kubeconfig' => $this->kubeconfig($server),
            'is_active' => true,
        ]);
        $region->forceFill(array_merge(['loki_status' => 'ready'], $attributes))->save();

        $project = Project::create(['name' => 'orders', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        LogRetentionConfig::create(['region_id' => $region->id, 'retention_days' => 30]);

        return $region->fresh();
    }

    /** @return array{0: string, 1: array<string, string>} path + query of the one request sent */
    private function deleteRequest(): array
    {
        $this->assertCount(1, $this->sent);
        $request = $this->sent[0];
        $this->assertSame('POST', $request->method());
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return [(string) parse_url($request->url(), PHP_URL_PATH), $query];
    }

    public function test_managed_loki_is_runrs_loki_stack_through_the_service_proxy(): void
    {
        $this->freezeTime();
        $this->fakeLoki();
        $region = $this->region('https://region.test:6443', [
            'loki_managed' => true,
            'loki_url' => 'http://loki-stack.observability.svc.cluster.local:3100',
        ]);

        (new LogRetentionJob)->handle(app(LogRetentionService::class));

        [$path, $query] = $this->deleteRequest();
        $this->assertSame('/api/v1/namespaces/observability/services/http:loki-stack:3100/proxy/loki/api/v1/delete', $path);
        $this->assertStringStartsWith('https://region.test:6443/', $this->sent[0]->url());
        $slug = Project::first()->slug;
        $this->assertSame('{namespace="fn",pod=~"'.$slug.'-.*"}', $query['query']);
        $this->assertSame((string) now()->subDays(30)->getTimestamp(), $query['end']);
        $this->assertSame('1', $query['start']);
        $this->assertFalse($this->sent[0]->hasHeader('X-Scope-OrgID'));
        $this->assertSame('Bearer admin', $this->sent[0]->header('Authorization')[0]);

        // a managed region without a stored URL uses the same loki-stack default
        $this->sent = [];
        $region->forceFill(['loki_url' => null])->save();
        app(LogRetentionService::class)->enforceRetention($region->fresh());
        $this->assertSame('/api/v1/namespaces/observability/services/http:loki-stack:3100/proxy/loki/api/v1/delete', $this->deleteRequest()[0]);
    }

    public function test_a_reused_loki_is_addressed_with_its_service_path_and_tenant(): void
    {
        $this->fakeLoki();
        $region = $this->region('https://region.test:6443', [
            'loki_managed' => false,
            'loki_url' => 'https://loki-gateway.logging.svc:8443/loki',
            'loki_org_id' => 'platform-tenant',
        ]);

        app(LogRetentionService::class)->enforceRetention($region);

        [$path] = $this->deleteRequest();
        $this->assertSame('/api/v1/namespaces/logging/services/https:loki-gateway:8443/proxy/loki/loki/api/v1/delete', $path);
        $this->assertSame(['platform-tenant'], $this->sent[0]->header('X-Scope-OrgID'));
    }

    public function test_a_remote_region_is_trimmed_on_its_own_cluster(): void
    {
        $this->fakeLoki();
        $region = $this->region('https://remote-cluster.example:6443', [
            'loki_managed' => true,
            'loki_url' => 'http://loki-stack.observability.svc.cluster.local:3100',
        ]);

        app(LogRetentionService::class)->enforceRetention($region);

        $this->deleteRequest();
        $this->assertSame('remote-cluster.example', parse_url($this->sent[0]->url(), PHP_URL_HOST));
        // never the in-cluster name, which would resolve inside RunR's own cluster
        $this->assertStringNotContainsString('svc.cluster.local', $this->sent[0]->url());
    }

    public function test_the_last_n_deployments_keep_their_logs(): void
    {
        $this->freezeTime();
        $this->fakeLoki();
        $region = $this->region('https://region.test:6443', ['loki_managed' => true]);
        $project = Project::first();
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deploy = function (int $daysAgo) use ($project, $version) {
            $deployment = $project->deployments()->create(['version_id' => $version->id, 'status' => 'active', 'deployed_by' => 'dev']);
            $deployment->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        };
        $deploy(120);
        $deploy(100);
        $deploy(10);
        $end = fn () => (int) $this->deleteRequest()[1]['end'];

        // retention 30 days, keep the last 2: the deployment of 100 days ago is protected → the
        // window ends where it started
        LogRetentionConfig::where('region_id', $region->id)->update(['keep_last_n_deployments' => 2]);
        app(LogRetentionService::class)->enforceRetention($region);
        $this->assertSame(now()->subDays(100)->getTimestamp(), $end());

        // keep the last 5 (all three): nothing after the first deployment's start is deleted
        $this->sent = [];
        LogRetentionConfig::where('region_id', $region->id)->update(['keep_last_n_deployments' => 5]);
        app(LogRetentionService::class)->enforceRetention($region);
        $this->assertSame(now()->subDays(120)->getTimestamp(), $end());

        // keep the last 1 (10 days ago, newer than the cutoff): the plain 30-day cutoff
        $this->sent = [];
        LogRetentionConfig::where('region_id', $region->id)->update(['keep_last_n_deployments' => 1]);
        app(LogRetentionService::class)->enforceRetention($region);
        $this->assertSame(now()->subDays(30)->getTimestamp(), $end());

        // keep none
        $this->sent = [];
        LogRetentionConfig::where('region_id', $region->id)->update(['keep_last_n_deployments' => 0]);
        app(LogRetentionService::class)->enforceRetention($region);
        $this->assertSame(now()->subDays(30)->getTimestamp(), $end());
    }

    public function test_no_request_while_the_regions_loki_is_not_ready(): void
    {
        $this->fakeLoki();
        $region = $this->region('https://region.test:6443', ['loki_status' => 'installing']);

        app(LogRetentionService::class)->enforceRetention($region);

        $this->assertSame([], $this->sent);
    }

    public function test_a_refused_delete_is_logged_as_a_failure(): void
    {
        Log::spy();
        $this->fakeLoki(400);
        $region = $this->region('https://region.test:6443', ['loki_managed' => true]);

        app(LogRetentionService::class)->enforceRetention($region);

        Log::shouldHaveReceived('warning')->with('Log retention enforcement failed', \Mockery::on(
            fn (array $context) => $context['project'] === Project::first()->slug && $context['status'] === 400
                && $context['loki'] === 'observability/loki-stack:3100'
        ))->once();
        Log::shouldNotHaveReceived('info', ['Log retention enforced', \Mockery::any()]);
    }
}
