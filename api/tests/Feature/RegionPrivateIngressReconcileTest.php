<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\Helpers\PrivateIngress;
use App\Services\KubernetesClient;
use App\Services\Manifests\RegionNetworkPolicyManifests;
use App\Services\PublicHostRewrites;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\NetworkPolicyStep;
use App\Services\RegionReconcile\Steps\PrivateIngressStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Cluster-local Knative routes off the LoadBalancer for existing regions (region:reconcile):
 * PrivateIngressStep moves RunR's own host rewrites to public hosts, upgrades the region Traefik
 * twice (cluster-local routes on web and internal, then on internal only) and has the KIngresses
 * that still name traefik written again in between; NetworkPolicyStep applies the policies that
 * leave Traefik and Knative the only way to the project pods.
 *
 * The fake cluster keeps its objects: a Helm upgrade changes the Traefik args (pods included), a
 * status patch of a KIngress is answered the way Traefik and Knative answer it (the status names
 * the Traefik's private service, the placeholder follows).
 */
class RegionPrivateIngressReconcileTest extends TestCase
{
    use RefreshDatabase;

    private const SERVING = 'knative-serving';

    private const LEGACY_LB = 'traefik.knative-serving.svc.cluster.local';

    private const NEW_LB = 'traefik-internal.knative-serving.svc.cluster.local';

    /** @var list<string> Traefik container args (Deployment and pods) */
    private array $args = [];

    private string $release = 'traefik';

    /** Traefik writes KIngress statuses again (false: it never does) */
    private bool $traefikAnswers = true;

    /** @var array<string, array> "namespace/name" → KIngress */
    private array $kingresses = [];

    /** @var array<string, array> "namespace/name" → Service */
    private array $services = [];

    /** @var array<string, array> "kind namespace/name" → object (Middleware, Deployment, Knative Service, NetworkPolicy) */
    private array $objects = [];

    /** @var list<string> namespaces that exist */
    private array $namespaces = ['fn', self::SERVING];

    /** @var list<string> what happened, in order: "helm …", "PATCH …" */
    private array $events = [];

    /** @var list<array> bodies of the KIngress status patches */
    private array $statusPatches = [];

    /** @var array<string, array> "kind namespace/name" → body of its last patch */
    private array $patches = [];

    /** @var list<list<string>> argv of every helm upgrade */
    private array $upgrades = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['knative.private_ingress.dns_grace_seconds' => 0, 'knative.private_ingress.regenerate_timeout_seconds' => 5]);
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);
        config(['observability.tracing.install_with_serving' => false]);
        RegionReconciler::clearRegionSources();

        $this->args = PrivateIngress::additionalArguments(self::SERVING, PrivateIngress::STATE_LEGACY);
        $this->kingress('fn', 'shop', self::LEGACY_LB, 3);
        $this->kingress('fn', 'orders-sink', self::LEGACY_LB, 1);
        // another ingress controller's: never touched
        $this->kingress('other', 'x', 'kourier.kourier-system.svc.cluster.local', 1);

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

    private function kingress(string $ns, string $name, string $lb, int $generation): void
    {
        $this->kingresses["{$ns}/{$name}"] = [
            'metadata' => ['namespace' => $ns, 'name' => $name, 'generation' => $generation, 'labels' => ['serving.knative.dev/route' => $name]],
            'status' => ['observedGeneration' => $generation, 'privateLoadBalancer' => ['ingress' => [['domainInternal' => $lb]]]],
        ];
        $this->services["{$ns}/{$name}"] = [
            'metadata' => ['namespace' => $ns, 'name' => $name, 'labels' => ['serving.knative.dev/route' => $name]],
            'spec' => ['type' => 'ExternalName', 'externalName' => $lb],
        ];
    }

    private function object(string $kind, string $ns, array $object): void
    {
        $object['metadata']['namespace'] = $ns;
        $this->objects["{$kind} {$ns}/{$object['metadata']['name']}"] = $object;
    }

    /** @return list<array> objects of a kind in a namespace whose labels include $selector */
    private function list(string $kind, string $ns, array $selector): array
    {
        $items = [];
        foreach ($this->objects as $key => $object) {
            if (str_starts_with($key, "{$kind} {$ns}/") && array_intersect_assoc($selector, $object['metadata']['labels'] ?? []) === $selector) {
                $items[] = $object;
            }
        }

        return $items;
    }

    private function answer(Request $request)
    {
        $method = $request->method();
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $selector = [];
        foreach (array_filter(explode(',', (string) ($query['labelSelector'] ?? ''))) as $pair) {
            [$k, $v] = explode('=', $pair, 2);
            $selector[$k] = $v;
        }
        $body = json_decode($request->body(), true) ?? [];
        $ok = fn ($data = []) => Http::response($data ?: ['kind' => 'Status'], 200);
        $missing = Http::response(['kind' => 'Status', 'code' => 404, 'reason' => 'NotFound'], 404);

        if ($path === '/version') {
            return $ok(['major' => '1', 'minor' => '32']);
        }
        if ($path === '/apis/apps/v1/namespaces/knative-serving/deployments/traefik' && $method === 'GET') {
            return $ok([
                'metadata' => ['name' => 'traefik', 'annotations' => ['meta.helm.sh/release-name' => $this->release, 'meta.helm.sh/release-namespace' => self::SERVING]],
                'spec' => ['template' => ['spec' => ['containers' => [['name' => 'traefik', 'args' => $this->args]]]]],
            ]);
        }
        if ($path === '/api/v1/namespaces/knative-serving/pods' && $method === 'GET') {
            $pods = [];
            foreach (['a', 'b', 'c'] as $suffix) {
                $pods[] = ['metadata' => ['name' => "traefik-{$suffix}"], 'spec' => ['containers' => [['name' => 'traefik', 'args' => $this->args]]]];
            }

            return $ok(['items' => $pods]);
        }
        if ($path === '/apis/networking.internal.knative.dev/v1alpha1/ingresses') {
            return $ok(['items' => array_values($this->kingresses)]);
        }
        if (preg_match('#^/apis/networking\.internal\.knative\.dev/v1alpha1/namespaces/([^/]+)/ingresses/([^/]+)(/status)?$#', $path, $m)) {
            $key = "{$m[1]}/{$m[2]}";
            if ($method === 'PATCH' && ($m[3] ?? '') === '/status') {
                $this->events[] = "status {$key}";
                $this->statusPatches[] = ['key' => $key, 'body' => $body];
                if ($this->traefikAnswers) {
                    // Traefik writes the status again with its private service, Knative moves the placeholder
                    $lb = PrivateIngress::state($this->args) === PrivateIngress::STATE_LEGACY ? self::LEGACY_LB : self::NEW_LB;
                    $this->kingresses[$key]['status']['privateLoadBalancer']['ingress'][0]['domainInternal'] = $lb;
                    $this->services[$key]['spec']['externalName'] = $lb;
                }

                return $ok();
            }

            return isset($this->kingresses[$key]) ? $ok($this->kingresses[$key]) : $missing;
        }
        if (preg_match('#^/api/v1/namespaces/([^/]+)/services$#', $path, $m) && $method === 'GET') {
            return $ok(['items' => array_values(array_filter($this->services, fn ($s) => $s['metadata']['namespace'] === $m[1]
                && array_intersect_assoc($selector, $s['metadata']['labels']) === $selector))]);
        }
        if (preg_match('#^/api/v1/namespaces/([^/]+)$#', $path, $m) && $method === 'GET') {
            return in_array($m[1], $this->namespaces, true) ? $ok(['metadata' => ['name' => $m[1]]]) : $missing;
        }

        $kinds = [
            '#^/apis/traefik\.io/v1alpha1/namespaces/([^/]+)/middlewares(?:/([^/]+))?$#' => 'Middleware',
            '#^/apis/apps/v1/namespaces/([^/]+)/deployments(?:/([^/]+))?$#' => 'Deployment',
            '#^/apis/serving\.knative\.dev/v1/namespaces/([^/]+)/services(?:/([^/]+))?$#' => 'KService',
            '#^/apis/networking\.k8s\.io/v1/namespaces/([^/]+)/networkpolicies(?:/([^/]+))?$#' => 'NetworkPolicy',
        ];
        foreach ($kinds as $pattern => $kind) {
            if (! preg_match($pattern, $path, $m)) {
                continue;
            }
            $key = "{$kind} {$m[1]}/".($m[2] ?? '');
            if ($method === 'GET' && ! isset($m[2])) {
                return $ok(['items' => $this->list($kind, $m[1], $selector)]);
            }
            if ($method === 'GET') {
                return isset($this->objects[$key]) ? $ok($this->objects[$key]) : $missing;
            }
            if ($method === 'PATCH') {
                $this->events[] = "PATCH {$kind} {$m[1]}/{$m[2]}";
                $this->patches[$key] = $body;
                // a Deployment's container env is a strategic merge (by name): kept as sent
                $this->objects[$key] = in_array($kind, ['NetworkPolicy', 'Deployment'], true) ? $body : array_replace_recursive($this->objects[$key] ?? [], $body);

                return $ok($this->objects[$key]);
            }

            return $ok();
        }

        return $method === 'GET' ? $missing : $ok();
    }

    private function helmRun(array $argv): HelmResult
    {
        if (($argv[1] ?? '') === 'list') {
            return new HelmResult(true, json_encode([['name' => 'traefik', 'namespace' => self::SERVING, 'status' => 'deployed', 'chart' => 'traefik-40.3.0']]), '', 0);
        }
        $set = $this->setJson($argv);
        if (($argv[1] ?? '') === 'upgrade' && isset($set['additionalArguments'])) {
            $this->upgrades[] = $argv;
            // the chart renders ports.internal as its address argument
            $this->args = array_merge(json_decode($set['additionalArguments'], true), isset($set['ports.internal']) ? ['--entryPoints.internal.address=:8081/tcp'] : []);
            $this->events[] = 'helm '.PrivateIngress::state($this->args);
        }

        return new HelmResult(true, '', '', 0);
    }

    /** @return array<string, string> */
    private function setJson(array $argv): array
    {
        $set = [];
        foreach ($argv as $i => $arg) {
            if ($arg === '--set-json') {
                [$key, $value] = explode('=', $argv[$i + 1], 2);
                $set[$key] = $value;
            }
        }

        return $set;
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
            'knative_settings' => ['knative_domain' => 'eu.runr.test', 'request_timeout' => 600],
        ], $attributes));
    }

    private function cluster(?KnativeRegion $region = null): ClusterGroup
    {
        $region ??= $this->region();

        return new ClusterGroup('uid-c', [new ReconcileTarget($region)], new KubernetesClient($region->kubeconfig));
    }

    // ── Traefik and the KIngresses ──────────────────────────────────────────

    public function test_a_legacy_traefik_moves_in_two_upgrades_with_the_kingresses_regenerated_in_between(): void
    {
        $lines = (new PrivateIngressStep)->run($this->cluster(), false);

        // first web and internal, the KIngresses, then internal only
        $this->assertSame([
            'helm '.PrivateIngress::STATE_TRANSITIONAL,
            'status fn/shop',
            'status fn/orders-sink',
            'helm '.PrivateIngress::STATE_PRIVATE,
        ], $this->events);
        $this->assertCount(2, $this->upgrades);
        foreach ($this->upgrades as $argv) {
            $this->assertContains('--wait', $argv, 'the next part waits for the Traefik pods');
            $this->assertContains('--reuse-values', $argv);
            $this->assertContains('40.3.0', $argv, 'at the chart version it runs');
        }

        $first = $this->setJson($this->upgrades[0]);
        $port = json_decode($first['ports.internal'], true);
        $this->assertSame(8081, $port['port']);
        $this->assertSame(['default' => false, 'internal' => true], $port['expose'], 'not on the LoadBalancer Service, on traefik-internal');
        $this->assertSame(80, $port['exposedPort']);
        $this->assertSame('610s', $port['transport']['respondingTimeouts']['writeTimeout'], 'request_timeout reaches the private entry point too');
        $this->assertSame(['type' => 'ClusterIP'], json_decode($first['service.additionalServices.internal'], true));
        $transitional = json_decode($first['additionalArguments'], true);
        $this->assertContains('--providers.knative.privateentrypoints=web,internal', $transitional);
        $this->assertContains('--providers.knative.privateservice.name=traefik-internal', $transitional);
        $this->assertContains('--providers.knative.publicentrypoints=web,websecure', $transitional);
        $this->assertContains('--ping=true', $transitional, 'Traefik\'s own arguments stay');

        $final = json_decode($this->setJson($this->upgrades[1])['additionalArguments'], true);
        $this->assertContains('--providers.knative.privateentrypoints=internal', $final);
        $this->assertNotContains('--providers.knative.privateentrypoints=web,internal', $final);

        // observedGeneration set back: Traefik writes the status again, the routes are not touched
        $this->assertSame(['observedGeneration' => 2], $this->statusPatches[0]['body']['status']);
        $this->assertSame(['observedGeneration' => 0], $this->statusPatches[1]['body']['status']);
        $this->assertSame(self::NEW_LB, $this->services['fn/shop']['spec']['externalName']);
        $this->assertSame(self::NEW_LB, $this->services['fn/orders-sink']['spec']['externalName']);
        $this->assertSame('kourier.kourier-system.svc.cluster.local', $this->services['other/x']['spec']['externalName'], 'another controller\'s');

        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('upgraded Helm release knative-serving/traefik (chart traefik-40.3.0, values reused): private entry point internal', $lines[0]);
        $this->assertStringStartsWith('pointed 2 KIngress(es) at traefik-internal', $lines[1]);
        $this->assertStringContainsString('cluster-local Knative routes on internal only', $lines[2]);

        // done: a second run changes nothing
        $this->events = [];
        $this->assertSame([], (new PrivateIngressStep)->run($this->cluster(KnativeRegion::first()), false));
        $this->assertSame([], $this->events);
    }

    public function test_the_dry_run_names_the_parts_and_writes_nothing(): void
    {
        $lines = (new PrivateIngressStep)->run($this->cluster(), true);

        $this->assertSame([], $this->events);
        $this->assertSame([], $this->upgrades);
        $this->assertStringStartsWith('would upgrade Helm release knative-serving/traefik', $lines[0]);
        $this->assertStringStartsWith('would point the KIngresses at traefik-internal', $lines[1]);
    }

    public function test_a_run_that_stopped_halfway_goes_on_from_the_transitional_traefik(): void
    {
        $this->args = array_merge(PrivateIngress::additionalArguments(self::SERVING, PrivateIngress::STATE_TRANSITIONAL), ['--entryPoints.internal.address=:8081/tcp']);
        $this->kingresses['fn/shop']['status']['privateLoadBalancer']['ingress'][0]['domainInternal'] = self::NEW_LB;
        $this->services['fn/shop']['spec']['externalName'] = self::NEW_LB;

        (new PrivateIngressStep)->run($this->cluster(), false);

        $this->assertSame(['status fn/orders-sink', 'helm '.PrivateIngress::STATE_PRIVATE], $this->events);
    }

    public function test_kingresses_that_keep_the_old_load_balancer_fail_the_step_before_web_stops_serving_them(): void
    {
        $this->traefikAnswers = false;
        config(['knative.private_ingress.regenerate_timeout_seconds' => 1]);

        try {
            (new PrivateIngressStep)->run($this->cluster(), false);
            $this->fail('the step must fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('2 KIngress(es) still name traefik.knative-serving.svc.cluster.local', $e->getMessage());
        }

        // cluster-local routes stay on web too: their placeholders still point at traefik
        $this->assertSame(PrivateIngress::STATE_TRANSITIONAL, PrivateIngress::state($this->args));
        $this->assertCount(1, $this->upgrades);
    }

    public function test_a_traefik_that_is_not_runrs_or_has_no_private_entry_point_is_left_alone(): void
    {
        $this->release = 'platform-traefik';
        $this->assertSame([], (new PrivateIngressStep)->run($this->cluster(), false));

        $this->release = 'traefik';
        $this->args = ['--ping=true'];
        $this->assertSame(['left alone: Traefik knative-serving/traefik serves cluster-local Knative routes on no entry point of RunR\'s'], (new PrivateIngressStep)->run($this->cluster(), false));
        $this->assertSame([], $this->upgrades);
        $this->assertSame([], $this->statusPatches);
    }

    // ── RunR's routes that re-enter Traefik ─────────────────────────────────

    public function test_custom_domain_middlewares_of_earlier_releases_move_to_the_public_host(): void
    {
        $this->args = array_merge(PrivateIngress::additionalArguments(self::SERVING), ['--entryPoints.internal.address=:8081/tcp']);
        $this->kingresses = [];
        $legacy = fn (string $name, string $service) => [
            'metadata' => ['name' => $name, 'labels' => ['app.kubernetes.io/managed-by' => 'runr'], 'annotations' => ['runr.dev/domain' => "{$service}.example.com"]],
            'spec' => ['headers' => ['customRequestHeaders' => ['Host' => "{$service}.fn.svc", 'K-Original-Host' => "{$service}.example.com"]]],
        ];
        $this->object('Middleware', 'fn', $legacy('runr-domain-shop', 'shop'));
        $this->object('Middleware', 'fn', $legacy('runr-domain-sink', 'orders-sink'));
        $this->object('KService', 'fn', ['metadata' => ['name' => 'shop'], 'status' => ['url' => 'https://shop.eu.runr.test']]);
        $this->object('KService', 'fn', ['metadata' => ['name' => 'orders-sink'], 'status' => ['url' => 'http://orders-sink.fn.svc.cluster.local']]);

        $lines = (new PrivateIngressStep)->run($this->cluster(), false);

        $shop = $this->objects['Middleware fn/runr-domain-shop'];
        $this->assertSame('shop.eu.runr.test', $shop['spec']['headers']['customRequestHeaders']['Host']);
        $this->assertSame('shop.example.com', $shop['spec']['headers']['customRequestHeaders']['K-Original-Host']);
        $this->assertSame('shop', $shop['metadata']['annotations'][PublicHostRewrites::ANNOTATION_SERVICE]);
        $this->assertSame('public', $shop['metadata']['labels'][PublicHostRewrites::LABEL]);
        // a cluster-local service has no public route: never routed from outside
        $this->assertSame('orders-sink.fn.svc', $this->objects['Middleware fn/runr-domain-sink']['spec']['headers']['customRequestHeaders']['Host']);
        $this->assertSame([
            'pointed Middleware fn/runr-domain-shop at the public host: Host shop.fn.svc → shop.eu.runr.test',
            'left alone: Middleware fn/runr-domain-sink rewrites to orders-sink.fn.svc, and Knative Service orders-sink has no public URL',
        ], $lines);

        // the service moved to another domain (a base-domain move): the status check follows it
        $this->objects['KService fn/shop']['status']['url'] = 'https://shop.eu.new.test';
        $sync = fn () => array_values(array_filter(
            PublicHostRewrites::sync(new KubernetesClient(KnativeRegion::first()->kubeconfig), 'fn'),
            fn (string $line) => ! str_starts_with($line, 'left alone'),
        ));
        $this->assertSame(['pointed Middleware fn/runr-domain-shop at the public host: Host shop.eu.runr.test → shop.eu.new.test'], $sync());
        $this->assertSame([], $sync(), 'up to date');
    }

    public function test_domain_alias_proxies_of_earlier_releases_send_to_the_public_hosts(): void
    {
        $this->args = array_merge(PrivateIngress::additionalArguments(self::SERVING), ['--entryPoints.internal.address=:8081/tcp']);
        $this->kingresses = [];
        $proxy = fn (string $name, array $labels) => [
            'metadata' => ['name' => $name, 'labels' => ['app.kubernetes.io/managed-by' => 'runr'] + $labels],
            'spec' => ['template' => ['spec' => ['containers' => [['name' => 'proxy', 'env' => [
                ['name' => 'ALIAS_DOMAIN', 'value' => 'eu.new.test'], ['name' => 'SERVICE_SUFFIX', 'value' => '.fn.svc'],
            ]]]]]],
        ];
        $this->object('Deployment', 'fn', $proxy('runr-domain-alias', []));
        $this->object('Deployment', 'fn', $proxy('runr-domain-alias-tabc', ['runr/tenant' => 'acme']));
        // the organization's services answer on its own domain
        $this->object('KService', 'fn', ['metadata' => ['name' => 'api-acme', 'labels' => ['runr/tenant' => 'acme']], 'status' => ['url' => 'https://api-acme.eu.acme.test']]);

        $lines = (new PrivateIngressStep)->run($this->cluster(), false);

        $this->assertSame([
            'sent domain alias fn/runr-domain-alias to the services\' public hosts: *.fn.svc → *.eu.runr.test',
            'sent domain alias fn/runr-domain-alias-tabc to the services\' public hosts: *.fn.svc → *.eu.acme.test',
        ], $lines);
        $this->assertSame([['name' => 'proxy', 'env' => [['name' => 'SERVICE_SUFFIX', 'value' => '.eu.runr.test']]]],
            $this->patches['Deployment fn/runr-domain-alias']['spec']['template']['spec']['containers']);
    }

    // ── NetworkPolicies ─────────────────────────────────────────────────────

    public function test_the_network_policies_are_applied_once(): void
    {
        $lines = (new NetworkPolicyStep)->run($this->cluster(), false);

        $this->assertSame([
            'applied NetworkPolicy knative-serving/runr-activator (the activator admits only Traefik and the autoscaler, its metrics port stays open)',
            'applied NetworkPolicy fn/runr-knative-revisions (revision pods admit only Traefik, the activator and the autoscaler)',
        ], $lines);
        $revisions = $this->objects['NetworkPolicy fn/'.RegionNetworkPolicyManifests::REVISIONS];
        $this->assertSame([['key' => 'serving.knative.dev/revision', 'operator' => 'Exists']], $revisions['spec']['podSelector']['matchExpressions']);

        $this->assertSame([], (new NetworkPolicyStep)->run($this->cluster(KnativeRegion::first()), false), 'up to date');

        // a Serving namespace without RunR's Traefik: its pods are not the ones the policies admit
        $this->objects = [];
        $this->release = 'platform-traefik';
        $this->assertSame([], (new NetworkPolicyStep)->run($this->cluster(KnativeRegion::first()), false));
    }

    public function test_region_reconcile_runs_both_after_the_timeouts(): void
    {
        $this->region();

        Artisan::call('region:reconcile');
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/\[request-timeout\].*\n(.*\n)*.*\[private-ingress\].*\n(.*\n)*.*\[network-policy\]/', $output);
        $this->assertStringContainsString('[private-ingress] pointed 2 KIngress(es) at traefik-internal', $output);
        $this->assertStringContainsString('[network-policy] applied NetworkPolicy fn/runr-knative-revisions', $output);
    }
}
