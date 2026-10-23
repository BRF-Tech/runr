<?php

namespace Tests\Feature;

use App\Models\AiModelService;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectInternalCaller;
use App\Services\GitDeploy\PreviewManifests;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\Helpers\PrivateIngress;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\InternalNetwork\InternalNetworkManifests as M;
use App\Services\InternalNetwork\InternalNetworkService;
use App\Services\InternalNetwork\RegionInternalNetwork;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\InternalNetworkStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The internal network on a region (docs/internal-network.md): RegionInternalNetwork installs the
 * authz, its keys and modes, the entry-point Middlewares and CoreDNS's runr.internal rewrite;
 * InternalNetworkStep (region:reconcile) brings regions installed before it there — every
 * Knative Service of RunR's its policy metadata (the projects' allow lists from the database)
 * and runr.internal route, then the region Traefik's private entry point the Middlewares, once
 * the authz answers. The region enforces afterwards.
 *
 * The fake cluster keeps its objects ("<resource> <namespace>/<name>"): an apply replaces one, a
 * merge patch merges into it (null removes), a Helm upgrade changes the Traefik args.
 */
class RegionInternalNetworkReconcileTest extends TestCase
{
    use RefreshDatabase;

    private const SERVING = 'knative-serving';

    private const COREFILE = ".:53 {\n    errors\n    kubernetes cluster.local in-addr.arpa ip6.arpa {\n       pods insecure\n    }\n    forward . /etc/resolv.conf\n    cache 30\n}\n";

    /** @var array<string, array> "<resource> <namespace>/<name>" → object ("" namespace: cluster-scoped) */
    private array $objects = [];

    /** @var list<string> "METHOD <resource> <namespace>/<name>" of every write */
    private array $writes = [];

    /** @var list<list<string>> argv of every helm upgrade */
    private array $upgrades = [];

    /** The authz gets ready pods once applied */
    private bool $authzStarts = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['knative.internal_network.authz_ready_timeout_seconds' => 0]);
        RegionReconciler::clearRegionSources();

        $this->putObject('deployments', self::SERVING, [
            'metadata' => ['name' => 'traefik', 'annotations' => ['meta.helm.sh/release-name' => 'traefik', 'meta.helm.sh/release-namespace' => self::SERVING]],
            'spec' => ['template' => ['spec' => ['containers' => [['name' => 'traefik', 'args' => [
                ...PrivateIngress::additionalArguments(self::SERVING), '--entryPoints.internal.address=:8081/tcp',
            ]]]]]],
        ]);
        $this->putObject('configmaps', 'kube-system', ['metadata' => ['name' => 'coredns'], 'data' => ['Corefile' => self::COREFILE]]);

        Http::fake(fn (Request $request) => $this->answer($request));
        $this->app->instance(HelmService::class, new class(fn (array $argv) => $this->helmRun($argv)) extends HelmService
        {
            public function __construct(private \Closure $runner) {}

            protected function run(array $command): HelmResult
            {
                return ($this->runner)($command);
            }
        });
    }

    protected function tearDown(): void
    {
        RegionReconciler::clearRegionSources();
        parent::tearDown();
    }

    // ── Fake cluster ────────────────────────────────────────────────────────

    private function putObject(string $resource, string $ns, array $object): void
    {
        $this->objects["{$resource} {$ns}/{$object['metadata']['name']}"] = $object;
    }

    private function getObject(string $resource, string $ns, string $name): ?array
    {
        return $this->objects["{$resource} {$ns}/{$name}"] ?? null;
    }

    private function answer(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $method = $request->method();
        $ok = fn (array $data = []) => Http::response($data ?: ['kind' => 'Status'], 200);
        $missing = Http::response(['kind' => 'Status', 'code' => 404, 'reason' => 'NotFound'], 404);

        if ($path === '/version') {
            return $ok(['major' => '1', 'minor' => '32']);
        }
        if (! preg_match('#^/(?:api/v1|apis/[^/]+/[^/]+)(?:/namespaces/([^/]+))?/([a-z]+)(?:/([^/]+))?$#', $path, $m)) {
            return $method === 'GET' ? $missing : $ok();
        }
        [$ns, $resource, $name] = [$m[1], $m[2], $m[3] ?? ''];
        $key = "{$resource} {$ns}/{$name}";

        if ($method === 'GET' && $name === '') {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $selector = [];
            foreach (array_filter(explode(',', (string) ($query['labelSelector'] ?? ''))) as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $selector[$k] = $v;
            }
            $items = array_values(array_filter($this->objects, fn ($o, $k) => str_starts_with($k, "{$resource} {$ns}/")
                && array_intersect_assoc($selector, $o['metadata']['labels'] ?? []) === $selector, ARRAY_FILTER_USE_BOTH));

            return $ok(['items' => $items]);
        }
        if ($method === 'GET') {
            return isset($this->objects[$key]) ? $ok($this->objects[$key]) : $missing;
        }
        if ($method === 'DELETE') {
            $this->writes[] = "DELETE {$key}";
            unset($this->objects[$key]);

            return $ok();
        }

        $this->writes[] = "{$method} {$key}";
        $body = json_decode($request->body(), true) ?? [];
        $type = (string) ($request->header('Content-Type')[0] ?? '');
        $this->objects[$key] = str_contains($type, 'merge-patch') ? $this->merge($this->objects[$key] ?? [], $body) : $body;
        if ($resource === 'deployments' && $name === M::AUTHZ && $this->authzStarts) {
            $this->objects[$key]['status'] = ['readyReplicas' => 2];
        }

        return $ok($this->objects[$key]);
    }

    /** JSON merge patch: maps merge, null removes */
    private function merge(array $target, array $patch): array
    {
        foreach ($patch as $k => $v) {
            if ($v === null) {
                unset($target[$k]);
            } elseif (is_array($v) && ! array_is_list($v) && is_array($target[$k] ?? null)) {
                $target[$k] = $this->merge($target[$k], $v);
            } else {
                $target[$k] = $v;
            }
        }

        return $target;
    }

    private function helmRun(array $argv): HelmResult
    {
        if (($argv[1] ?? '') === 'list') {
            return new HelmResult(true, json_encode([['name' => 'traefik', 'namespace' => self::SERVING, 'status' => 'deployed', 'chart' => 'traefik-40.3.0']]), '', 0);
        }
        if (($argv[1] ?? '') === 'upgrade') {
            $this->upgrades[] = $argv;
            $this->writes[] = 'helm upgrade';
            foreach ($argv as $i => $arg) {
                if ($arg === '--set-json' && str_starts_with($argv[$i + 1], 'ports.internal.http.middlewares=')) {
                    // the chart renders the entry point's middlewares as one argument
                    $list = json_decode(substr($argv[$i + 1], strlen('ports.internal.http.middlewares=')), true);
                    $deployment = &$this->objects['deployments '.self::SERVING.'/traefik'];
                    $deployment['spec']['template']['spec']['containers'][0]['args'][] = '--entryPoints.internal.http.middlewares='.implode(',', $list);
                }
            }
        }

        return new HelmResult(true, '', '', 0);
    }

    private function region(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => 'eu',
            'project_namespace' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://cluster.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
            'knative_serving_status' => 'ready',
            'knative_serving_namespace' => self::SERVING,
            'knative_settings' => ['knative_domain' => 'eu.runr.test'],
        ], $attributes));
    }

    /** @param list<KnativeRegion> $regions */
    private function cluster(array $regions = []): ClusterGroup
    {
        $regions = $regions ?: [$this->region()];

        return new ClusterGroup('uid-c', array_map(fn ($r) => new ReconcileTarget($r), $regions), new KubernetesClient($regions[0]->kubeconfig));
    }

    private function ksvc(string $name, array $labels, array $annotations = []): void
    {
        $this->putObject('services', 'fn', ['metadata' => ['name' => $name, 'labels' => $labels, 'annotations' => $annotations]]);
    }

    // ── The region's part ───────────────────────────────────────────────────

    public function test_the_authz_its_keys_and_modes_and_the_coredns_rewrite_are_installed_once(): void
    {
        $eu = $this->region();
        // eu as an earlier release left it (no internal_auth: it enforces), us set to audit
        $us = $this->region(['name' => 'us', 'project_namespace' => 'fn-us', 'knative_settings' => [InternalNetwork::MODE_SETTING => InternalNetwork::MODE_AUDIT]]);
        $k8s = new KubernetesClient($eu->kubeconfig);

        $lines = RegionInternalNetwork::ensure($k8s, self::SERVING, [$eu, $us]);

        $this->assertCount(4, $lines, implode("\n", $lines));
        $this->assertStringStartsWith('wrote the signing key of eu, us', $lines[0]);
        $this->assertSame('set the internal network\'s modes (fn enforce, fn-us audit), cross-organization grants allowed', $lines[1]);
        $this->assertStringStartsWith('installed the internal network\'s authz', $lines[2]);
        $this->assertStringStartsWith('added the runr.internal rewrite to kube-system/coredns', $lines[3]);

        // each region's key, the one the API signs its tokens with
        $secret = $this->getObject('secrets', self::SERVING, M::CONFIG);
        $this->assertSame(base64_encode(InternalNetwork::regionKey((string) $eu->id)), $secret['data']['key.'.$eu->id]);
        $this->assertSame(base64_encode(InternalNetwork::regionKey((string) $us->id)), $secret['data']['key.'.$us->id]);
        $this->assertSame(['cross_tenant' => 'true', 'mode.fn' => 'enforce', 'mode.fn-us' => 'audit'], $this->getObject('configmaps', self::SERVING, M::CONFIG)['data']);

        foreach (['deployments', 'services', 'poddisruptionbudgets', 'serviceaccounts', 'roles', 'rolebindings'] as $resource) {
            $this->assertNotNull($this->getObject($resource, self::SERVING, M::AUTHZ), $resource);
        }
        $this->assertNotNull($this->getObject('clusterroles', '', M::AUTHZ), 'cluster-scoped');
        $this->assertNotNull($this->getObject('middlewares', self::SERVING, M::AUTHZ));
        $this->assertNotNull($this->getObject('middlewares', self::SERVING, M::STRIP));
        $this->assertStringContainsString('traefik-internal.knative-serving.svc.cluster.local.', $this->getObject('configmaps', 'kube-system', 'coredns')['data']['Corefile']);

        // up to date: nothing written again
        $this->writes = [];
        $this->assertSame([], RegionInternalNetwork::ensure($k8s, self::SERVING, [$eu->fresh(), $us->fresh()]));
        $this->assertSame([], $this->writes);

        // a region switched to audit
        $eu->update(['knative_settings' => array_merge($eu->knative_settings, [InternalNetwork::MODE_SETTING => InternalNetwork::MODE_AUDIT])]);
        $this->assertSame(['set the internal network\'s modes (fn audit, fn-us audit), cross-organization grants allowed'], RegionInternalNetwork::ensure($k8s, self::SERVING, [$eu->fresh()]));
    }

    public function test_coredns_that_imports_its_customizations_gets_the_rewrite_there(): void
    {
        $this->putObject('configmaps', 'kube-system', ['metadata' => ['name' => 'coredns'], 'data' => ['Corefile' => ".:53 {\n    kubernetes cluster.local\n    import /etc/coredns/custom/*.override\n}\nimport /etc/coredns/custom/*.server\n"]]);
        $this->putObject('configmaps', 'kube-system', ['metadata' => ['name' => 'coredns-custom'], 'data' => ['other.override' => 'log']]);
        $k8s = new KubernetesClient($this->region()->kubeconfig);

        $line = RegionInternalNetwork::coreDns($k8s, self::SERVING, false);

        $this->assertSame('added the runr.internal rewrite to kube-system/coredns-custom (runr-internal.override)', $line);
        $custom = $this->getObject('configmaps', 'kube-system', 'coredns-custom')['data'];
        $this->assertSame('log', $custom['other.override'], 'the cluster\'s own customizations stay');
        $this->assertStringContainsString('rewrite stop {', $custom[RegionInternalNetwork::COREDNS_OVERRIDE_KEY]);
        $this->assertStringNotContainsString('runr-internal-network begin', $this->getObject('configmaps', 'kube-system', 'coredns')['data']['Corefile'], 'its Corefile is not RunR\'s to change');
        $this->assertNull(RegionInternalNetwork::coreDns($k8s, self::SERVING, false));

        unset($this->objects['configmaps kube-system/coredns']);
        $this->assertStringStartsWith('left alone: no ConfigMap kube-system/coredns', (string) RegionInternalNetwork::coreDns($k8s, self::SERVING, false));
    }

    // ── region:reconcile ────────────────────────────────────────────────────

    public function test_the_entry_point_gets_the_forward_auth_once_the_authz_answers(): void
    {
        $lines = (new InternalNetworkStep)->run($this->cluster(), false);

        $this->assertCount(1, $this->upgrades);
        $this->assertContains('ports.internal.http.middlewares='.json_encode(M::entryPointMiddlewares(self::SERVING), JSON_UNESCAPED_SLASHES), $this->upgrades[0]);
        $this->assertContains('--reuse-values', $this->upgrades[0]);
        $this->assertNotEmpty(array_filter($lines, fn ($l) => str_starts_with($l, 'upgraded Helm release knative-serving/traefik')));

        // done: the next run upgrades nothing
        $this->upgrades = [];
        (new InternalNetworkStep)->run($this->cluster([KnativeRegion::first()]), false);
        $this->assertSame([], $this->upgrades);
    }

    public function test_an_authz_without_a_ready_pod_keeps_the_entry_point_open(): void
    {
        $this->authzStarts = false;

        $lines = (new InternalNetworkStep)->run($this->cluster(), false);

        $this->assertSame([], $this->upgrades, 'a ForwardAuth nobody answers would refuse every internal call');
        $this->assertContains('waiting: the internal network\'s authz knative-serving/runr-internal-auth has no ready pod yet; the private entry point gets its ForwardAuth once it has', $lines);
    }

    public function test_the_entry_point_waits_for_the_private_ingress_move(): void
    {
        $deployment = &$this->objects['deployments '.self::SERVING.'/traefik'];
        $deployment['spec']['template']['spec']['containers'][0]['args'] = PrivateIngress::additionalArguments(self::SERVING, PrivateIngress::STATE_LEGACY);

        $lines = (new InternalNetworkStep)->run($this->cluster(), false);

        $this->assertSame([], $this->upgrades);
        $this->assertContains('waiting: the private entry point of knative-serving/traefik is not internal only yet (private-ingress)', $lines);
    }

    public function test_a_traefik_that_is_not_runrs_is_left_alone(): void
    {
        $this->objects['deployments '.self::SERVING.'/traefik']['metadata']['annotations']['meta.helm.sh/release-name'] = 'platform-traefik';

        $this->assertSame([], (new InternalNetworkStep)->run($this->cluster(), false));
        $this->assertSame([], $this->writes);
    }

    public function test_every_knative_service_of_runrs_gets_its_policy_and_a_project_its_internal_route(): void
    {
        $this->ksvc('shop', ['runr/project-id' => 'p1']);
        $this->ksvc('checkout-acme', ['runr/project-id' => 'p2', 'runr/tenant' => 'acme']);
        $this->ksvc('shop-pr-3', [PreviewManifests::LABEL_PREVIEW_OF => 'p1', PreviewManifests::LABEL_PREVIEW => 'shop-pr-3']);
        $this->ksvc('m-embed', [AiModelService::LABEL => '01JNOSUCHMODEL000000000000']);
        $this->ksvc('foreign', ['app' => 'other']);
        // already on the internal network: kept as it is
        $this->ksvc('orders', ['runr/project-id' => 'p3', M::LABEL_MANAGED => 'managed'], [M::ANNOTATION_NAME => 'orders.platform', M::ANNOTATION_CALLERS => 'shop.platform']);
        $this->putObject('ingressroutes', 'fn', ['metadata' => ['name' => M::internalRouteName('orders')]]);

        $lines = (new InternalNetworkStep)->run($this->cluster(), false);

        $this->assertContains('gave 4 Knative Service(s) in fn their internal-network policy', $lines);
        $this->assertContains('routed 2 project(s) in fn on <project>.<organization>.runr.internal', $lines);

        $shop = $this->getObject('services', 'fn', 'shop')['metadata'];
        $this->assertSame('managed', $shop['labels'][M::LABEL_MANAGED]);
        $this->assertSame('shop.platform', $shop['annotations'][M::ANNOTATION_NAME]);
        $this->assertSame('', $shop['annotations'][M::ANNOTATION_CALLERS], 'nobody but itself until its allow list says so');
        $this->assertSame('public', $shop['annotations'][M::ANNOTATION_ACCESS]);
        $this->assertSame('checkout.acme', $this->getObject('services', 'fn', 'checkout-acme')['metadata']['annotations'][M::ANNOTATION_NAME], 'the tenant suffix is the organization');
        $preview = $this->getObject('services', 'fn', 'shop-pr-3')['metadata']['annotations'];
        $this->assertSame(['shop.platform', 'preview'], [$preview[M::ANNOTATION_NAME], $preview[M::ANNOTATION_KIND]], 'a preview answers as its project');
        $this->assertSame('model', $this->getObject('services', 'fn', 'm-embed')['metadata']['annotations'][M::ANNOTATION_KIND]);
        $this->assertNotContains('PATCH services fn/foreign', $this->writes, 'another\'s Knative Service is not touched');
        $this->assertSame('shop.platform', $this->getObject('services', 'fn', 'orders')['metadata']['annotations'][M::ANNOTATION_CALLERS]);

        $this->assertSame('Host(`shop.platform.runr.internal`)', $this->getObject('ingressroutes', 'fn', 'runr-internal-shop')['spec']['routes'][0]['match']);
        $this->assertSame('acme', $this->getObject('ingressroutes', 'fn', 'runr-internal-checkout-acme')['metadata']['labels']['runr/tenant']);
        $this->assertNull($this->getObject('ingressroutes', 'fn', 'runr-internal-shop-pr-3'), 'a preview has no runr.internal host');
        $this->assertNull($this->getObject('ingressroutes', 'fn', 'runr-internal-m-embed'));

        // a second run: up to date
        $this->writes = [];
        $again = (new InternalNetworkStep)->run($this->cluster([KnativeRegion::first()]), false);
        $this->assertSame([], array_values(array_filter($again, fn ($l) => ! str_starts_with($l, 'left alone'))));
        $this->assertSame([], $this->writes);
    }

    public function test_the_allow_lists_come_from_the_database_before_the_entry_point_enforces(): void
    {
        $region = $this->region();
        $project = function (string $name, bool $demo = false) use ($region): Project {
            $project = Project::create(['name' => $name, 'service_type' => 'caas']);
            $project->forceFill(['knative_region_id' => $region->id, 'is_region_demo' => $demo])->saveQuietly();
            $this->ksvc($project->slug, ['runr/project-id' => $project->id]);

            return $project->fresh();
        };
        $project('contact-frontend', true);
        $backend = $project('contact-backend', true);
        $orders = $project('orders');
        $orders->forceFill(['access_mode' => 'allowlist', 'allowed_cidrs' => ['203.0.113.0/24']])->saveQuietly();
        ProjectInternalCaller::query()->create(['project_id' => $orders->id, 'caller' => 'shop.platform']);

        (new InternalNetworkStep)->run($this->cluster([$region]), false);

        $this->assertSame('shop.platform', $this->getObject('services', 'fn', 'orders')['metadata']['annotations'][M::ANNOTATION_CALLERS]);
        $this->assertSame('allowlist', $this->getObject('services', 'fn', 'orders')['metadata']['annotations'][M::ANNOTATION_ACCESS]);
        // the region demo's frontend calls its backend: an earlier release wrote no allow list for it
        $this->assertSame(['contact-frontend.platform'], app(InternalNetworkService::class)->callers($backend));
        $this->assertSame('contact-frontend.platform', $this->getObject('services', 'fn', 'contact-backend')['metadata']['annotations'][M::ANNOTATION_CALLERS]);

        // every policy is on its Knative Service before the private entry point asks the authz
        $this->assertLessThan(
            array_search('helm upgrade', $this->writes, true),
            max(array_keys(array_filter($this->writes, fn ($w) => str_starts_with($w, 'PATCH services fn/')))),
        );
    }

    public function test_a_preview_that_shortened_its_projects_slug_answers_as_its_project(): void
    {
        $region = $this->region();
        // A slug of an earlier release: <slug>-pr-<number> had to cut it
        $project = Project::create(['name' => 'reports', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id, 'slug' => 'reports-of-the-quarterly-reconciliation-of-every-department-x'])->saveQuietly();
        $service = PreviewManifests::serviceName($project->slug, 7);
        $this->ksvc($service, [PreviewManifests::LABEL_PREVIEW_OF => $project->id, PreviewManifests::LABEL_PREVIEW => $service]);

        (new InternalNetworkStep)->run($this->cluster([$region]), false);

        $annotations = $this->getObject('services', 'fn', $service)['metadata']['annotations'];
        $this->assertSame(InternalNetwork::nameOf($project->fresh()), $annotations[M::ANNOTATION_NAME], 'the project\'s name, not what the Service name kept of it');
        $this->assertSame('reports-of-the-quarterly-reconciliation-of-every-department-x.platform', $annotations[M::ANNOTATION_NAME]);
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $this->ksvc('shop', ['runr/project-id' => 'p1']);

        $lines = (new InternalNetworkStep)->run($this->cluster(), true);

        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->upgrades);
        $this->assertContains('would give 1 Knative Service(s) in fn their internal-network policy', $lines);
        $this->assertNotEmpty(array_filter($lines, fn ($l) => str_starts_with($l, 'would install the internal network\'s authz')));
        $this->assertNotEmpty(array_filter($lines, fn ($l) => str_starts_with($l, 'would upgrade Helm release knative-serving/traefik')));
    }
}
