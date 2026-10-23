<?php

namespace App\Console\Commands\Concerns;

use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\Setting;
use App\Models\User;
use App\Services\Contracts\KnativeConstants;
use App\Services\GatewayService;
use App\Services\HelmService;
use App\Services\Helpers\PrivateIngress;
use App\Services\Helpers\RegionDomains;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\KubernetesClient;
use App\Services\Manifests\ProjectDomainManifests;
use App\Services\Manifests\RegionNetworkPolicyManifests;
use App\Services\ProjectDomainService;
use App\Services\RegionDomainAliasService;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\Steps\NetworkPolicyStep;
use App\Services\RegionReconcile\Steps\PrivateIngressStep;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * E2E of the region's private ingress (test:e2e --suite=network, runtime/lib/test-network.sh): a
 * public HTTP project (net-web, CaaS nginx) and a queue-triggered one (net-sink, Python: a
 * cluster-local Knative Service and its queue consumer) on the e2e region, then
 *
 *  - net-installed        the Traefik a new region gets serves cluster-local routes on internal only
 *                         (Service traefik-internal, port 80; the LoadBalancer publishes 80 / 443),
 *                         every KIngress names traefik-internal, the NetworkPolicies are there
 *  - net-outside          from outside the cluster (the API container through the LoadBalancer
 *                         address): a cluster-local host (<svc>.<ns>, .svc, .svc.cluster.local) of
 *                         net-sink and of net-web answers 404; net-web's public host 200
 *  - net-inside           a pod calls net-sink's cluster-local URL: 200 from zero (cold), then warm
 *  - net-bypass           pods in another namespace and in the project namespace reach net-sink's
 *                         pods neither through <revision>-private, <revision>, a pod address on
 *                         8012 / 8080 nor activator-service with the Knative headers
 *  - net-scale            net-sink scales to zero again (the autoscaler probes the activator) and
 *                         from zero; the autoscaler logged no scrape error for it
 *  - net-queue            a message XADDed to net-sink's stream is delivered (the consumer's stats:
 *                         delivered, no failed attempt) through the cluster-local URL
 *  - net-routes           a custom domain, a region domain alias and a gateway route serve net-web;
 *                         the alias does not serve net-sink
 *  - net-upgrade          the region as an earlier release left it — Traefik serving cluster-local
 *                         routes on web, the KIngresses and placeholders naming traefik, the custom
 *                         domain and alias rewriting to cluster-local hosts, no NetworkPolicy — where
 *                         the hole is open (a cluster-local host answers through the LoadBalancer, a
 *                         revision's -private Service from any pod); region:reconcile's dry run names
 *                         the change; private-ingress and network-policy run while probe pods call
 *                         net-sink's cluster-local URL, net-web's public host, the custom domain and
 *                         the alias every half second and a message is XADDed every two seconds:
 *                         the measured disruption is printed (failed calls, longest failure) and must
 *                         be none; afterwards the hole is closed, a second run changes nothing
 *  - net-domain-move      the base domain moves and moves back, each completed by hand: right after
 *                         the completion — no status check in between — the custom domain's and the
 *                         gateway route's Host rewrites name net-web's host on the new domain and the
 *                         gateway's IngressRoute its new host; both answer 200 once Knative serves
 *                         the new domain. A move set back before it completed leaves them on the
 *                         current hosts and removes the alias
 *
 * A call to a cluster-local URL passes the internal network's ForwardAuth (docs/internal-network.md):
 * the curl pods send the called service's own token, which its allow list always admits.
 */
trait E2eTestNetworkTrait
{
    private const NET_CURL = 'ghcr.io/brf-tech/runr/reqs/curl:latest';

    private const NET_NS = 'runr-net-e2e';

    private const NET_CUSTOM_DOMAIN = 'shop.net-custom.test';

    private const NET_ALIAS_DOMAIN = 'net-alias.test';

    private const NET_ALIAS_NAME = 'runr-domain-alias-e2e';

    private function runNetworkSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Region network: private ingress, NetworkPolicies ──');

        $k8s = $this->getE2eKubernetesClient();
        $this->ensureNamespace($k8s, self::NET_NS);

        $python = collect($this->fetchExecutorImages())->first(fn ($i) => ($i['runtime'] ?? '') === 'python' && ($i['is_default'] ?? false));
        $web = $this->makeTestEntry('net-web', ['service_type' => 'caas', 'trigger_type' => 'http', 'image' => 'ghcr.io/brf-tech/runr/reqs/nginx:1.27-alpine'], $regionId);
        $sink = $this->makeTestEntry('net-sink', [
            'service_type' => 'xaas', 'runtime' => 'python', 'runtime_version' => $python['version'] ?? null,
            'trigger_type' => 'queue', 'code' => self::xaasEchoCode()['python'],
        ], $regionId);
        $tests = [$web, $sink];
        foreach ($tests as $i => &$test) {
            $test['startTime'] = microtime(true);
            $this->runSingleTest($test, $i + 1, 2);
            $test['steps']['verify'] = $test['steps']['ready'] === true ? true : 'skipped';
            $test = $this->finishTest($test);
        }
        unset($test);
        [$web, $sink] = $tests;
        if ($web['steps']['ready'] !== true || $sink['steps']['ready'] !== true) {
            return $tests;
        }

        $c = $this->netContext($regionId, $web, $sink);
        $this->line("    LoadBalancer {$c['ip']}, function domain {$c['domain']}, project namespace {$c['ns']}");

        $tests[] = $this->recCheck('net-installed', fn () => $this->netInstalled($c));
        $tests[] = $this->recCheck('net-outside', fn () => $this->netOutside($c, 404));
        $tests[] = $this->recCheck('net-inside', fn () => $this->netInside($c));
        $tests[] = $this->recCheck('net-bypass', fn () => $this->netBypass($c, false));
        $tests[] = $this->recCheck('net-scale', fn () => $this->netScale($c));
        $tests[] = $this->recCheck('net-queue', fn () => $this->netQueue($c));
        $tests[] = $this->recCheck('net-routes', fn () => $this->netRoutes($c));
        $tests[] = $this->recCheck('net-upgrade', fn () => $this->netUpgrade($c));
        $tests[] = $this->recCheck('net-domain-move', fn () => $this->netDomainMove($c));

        $this->netCleanup($c);

        return $tests;
    }

    private function netContext(string $regionId, array $web, array $sink): array
    {
        $region = KnativeRegion::findOrFail($regionId);
        $k8s = $this->getE2eKubernetesClient();
        $servingNs = $region->knative_serving_namespace ?: 'knative-serving';
        $ip = (string) $region->external_ip;
        if ($ip === '') {
            $ip = (string) ($k8s->getResourceStatus('v1', 'Service', KnativeConstants::TRAEFIK_RELEASE, $servingNs)['status']['loadBalancer']['ingress'][0]['ip'] ?? '');
        }

        return [
            'regionId' => $regionId,
            'k8s' => $k8s,
            'ns' => $region->project_namespace ?: $region->name,
            'servingNs' => $servingNs,
            'ip' => $ip,
            'domain' => (string) RegionDomains::functionDomain($region),
            'web' => $web['slug'],
            'webId' => $web['projectId'],
            'sink' => $sink['slug'],
            'sinkId' => $sink['projectId'],
            'stream' => "test-stream-{$sink['name']}",
            'group' => fn () => new ClusterGroup('e2e', [new ReconcileTarget(KnativeRegion::findOrFail($regionId))], $k8s),
        ];
    }

    // ── Checks ──────────────────────────────────────────────────────────────

    private function netInstalled(array $c): bool|string
    {
        $k8s = $c['k8s'];
        $deployment = $k8s->getResourceStatus('apps/v1', 'Deployment', KnativeConstants::TRAEFIK_RELEASE, $c['servingNs']);
        $args = collect($deployment['spec']['template']['spec']['containers'] ?? [])->firstWhere('name', 'traefik')['args'] ?? [];
        $state = PrivateIngress::state($args);
        if ($state !== PrivateIngress::STATE_PRIVATE) {
            return 'Traefik state '.json_encode($state).': '.implode(' ', array_filter($args, fn ($a) => str_contains($a, 'knative')));
        }

        $lb = $k8s->getResourceStatus('v1', 'Service', KnativeConstants::TRAEFIK_RELEASE, $c['servingNs']);
        $lbPorts = collect($lb['spec']['ports'] ?? [])->pluck('port')->sort()->values()->all();
        $internal = $k8s->getResourceStatus('v1', 'Service', PrivateIngress::SERVICE, $c['servingNs']);
        $internalPorts = collect($internal['spec']['ports'] ?? [])->map(fn ($p) => "{$p['port']}→{$p['targetPort']}")->all();
        if ($lbPorts !== [80, 443] || ($internal['spec']['type'] ?? '') !== 'ClusterIP' || $internalPorts !== ['80→internal']) {
            return 'Services: LoadBalancer ports '.json_encode($lbPorts).', traefik-internal '.json_encode([$internal['spec']['type'] ?? null, $internalPorts]);
        }

        $lbs = $this->netKingressBalancers($c);
        $wanted = PrivateIngress::serviceHostname(PrivateIngress::SERVICE, $c['servingNs']);
        if (array_keys($lbs) !== [$wanted]) {
            return 'KIngress private load balancers: '.json_encode($lbs);
        }

        foreach ([[RegionNetworkPolicyManifests::REVISIONS, $c['ns']], [RegionNetworkPolicyManifests::ACTIVATOR, $c['servingNs']]] as [$name, $ns]) {
            if (! $k8s->getResourceStatus(RegionNetworkPolicyManifests::API, 'NetworkPolicy', $name, $ns)) {
                return "no NetworkPolicy {$ns}/{$name}";
            }
        }
        $this->line('    traefik: '.implode(' ', array_filter($args, fn ($a) => str_contains($a, 'providers.knative.'))));
        $this->line('    LoadBalancer ports '.implode(', ', $lbPorts).'; traefik-internal '.implode(', ', $internalPorts).'; '.array_sum($lbs).' KIngress(es) → '.$wanted);

        return true;
    }

    /**
     * Through the LoadBalancer from outside the cluster: the cluster-local hosts answer $private
     * (404 once private, 200 while an earlier release's Traefik serves them on web).
     */
    private function netOutside(array $c, int $private): bool|string
    {
        $problems = [];
        $hosts = [];
        foreach ([$c['sink'], $c['web']] as $svc) {
            foreach (["{$svc}.{$c['ns']}", "{$svc}.{$c['ns']}.svc", "{$svc}.{$c['ns']}.svc.cluster.local"] as $host) {
                $hosts[$host] = $private;
            }
        }
        $hosts["{$c['web']}.{$c['domain']}"] = 200;

        foreach ($hosts as $host => $want) {
            $got = $this->netExternal($c['ip'], $host, '/', false, $want === 200 ? 120 : 0);
            $this->line("    Host {$host} → {$got}");
            if ($got !== $want) {
                $problems[] = "{$host}: {$got} (want {$want})";
            }
        }

        return $problems === [] ?: implode('; ', $problems);
    }

    private function netInside(array $c): bool|string
    {
        if (! $this->netWaitScaledToZero($c, $c['sink'], 300)) {
            return "{$c['sink']} did not scale to zero within 300 s";
        }

        $url = "http://{$c['sink']}.{$c['ns']}.svc.cluster.local/";
        [$phase, $log] = $this->netCurlPod('net-inside', self::NET_NS,
            "for t in cold warm; do printf \"\$t \"; curl -s -o /dev/null -w '%{http_code} %{time_total}\\n' --max-time 90 -X POST -H 'Content-Type: application/json' {$this->netToken($c, $c['sink'])} -d '{\"e2e\":true}' {$url}; done");
        $this->line('    '.str_replace("\n", ' | ', trim($log)));
        if ($phase !== 'Succeeded' || ! preg_match('/^cold 200 .*\nwarm 200 /m', $log)) {
            return "{$url}: {$phase} — {$log}";
        }

        return true;
    }

    /**
     * Ways past the route to net-sink's pods: reachable while $open (an earlier release without
     * NetworkPolicies), blocked otherwise. From a pod of another namespace and one in the project
     * namespace (where every organization's pods run).
     */
    private function netBypass(array $c, bool $open): bool|string
    {
        $target = $this->netWarmPod($c, $c['sink']);
        if (! is_array($target)) {
            return $target;
        }
        [$pod, $ip, $revision] = $target;
        $ns = $c['ns'];
        $headers = "-H 'Knative-Serving-Namespace: {$ns}' -H 'Knative-Serving-Revision: {$revision}' -H 'Host: {$c['sink']}.{$ns}.svc.cluster.local'";
        $attempts = [
            'private' => "http://{$revision}-private.{$ns}.svc.cluster.local/",
            'revision' => "http://{$revision}.{$ns}.svc.cluster.local/",
            'pod-8012' => "http://{$ip}:8012/",
            'pod-8080' => "http://{$ip}:8080/",
            'activator' => "{$headers} http://activator-service.{$c['servingNs']}.svc.cluster.local/",
        ];
        $script = '';
        foreach ($attempts as $label => $target) {
            $script .= "printf '{$label} '; curl -s -o /dev/null -w '%{http_code}\\n' --connect-timeout 5 --max-time 8 -X POST -d '{}' {$target} || true; ";
        }

        $problems = [];
        foreach ([self::NET_NS, $ns] as $from) {
            [$phase, $log] = $this->netCurlPod('net-bypass', $from, $script, 120);
            $this->line("    from {$from} ({$pod}): ".str_replace("\n", ' | ', trim($log)));
            foreach (explode("\n", trim($log)) as $line) {
                [$label, $code] = array_pad(explode(' ', trim($line), 2), 2, '');
                $reached = preg_match('/^[2-4]\d\d$/', $code) === 1;
                // the queue-proxy port answers any HTTP request; the user container 8080 too
                if ($reached !== $open) {
                    $problems[] = "{$from} {$label}: {$code}".($open ? ' (want reached)' : ' (want no answer)');
                }
            }
            if ($phase !== 'Succeeded') {
                $problems[] = "pod in {$from}: {$phase}";
            }
        }

        return $problems === [] ?: implode('; ', $problems);
    }

    private function netScale(array $c): bool|string
    {
        $started = time();
        $this->netWarmPod($c, $c['sink']);
        if (! $this->netWaitScaledToZero($c, $c['sink'], 300)) {
            return "{$c['sink']} did not scale to zero within 300 s";
        }
        $this->line('    scaled to zero after '.(time() - $started).' s');

        $from = microtime(true);
        $warm = $this->netWarmPod($c, $c['sink']);
        if (! is_array($warm)) {
            return "from zero: {$warm}";
        }
        $this->line('    from zero: pod '.$warm[0].' ready, '.round(microtime(true) - $from, 1).' s');

        // the autoscaler scrapes the running pod (queue-proxy's metrics port): no error about it
        $since = time() + 5;
        sleep(30);
        $errors = $this->netAutoscalerErrors($c, $since);
        $this->line('    autoscaler scrape errors about '.$c['sink'].' while it ran: '.count($errors));

        return $errors === [] ?: 'autoscaler: '.implode(' | ', array_slice($errors, 0, 3));
    }

    private function netQueue(array $c): bool|string
    {
        $before = $this->netConsumerStats($c);
        if (! is_array($before)) {
            return $before;
        }
        [$host, $port, $password] = $this->netRedis();
        $auth = $password ? "-a '{$password}' --no-auth-warning" : '';
        $add = $this->recRunPod($c['k8s'], self::NET_NS, 'net-xadd', ['containers' => [[
            'name' => 'redis', 'image' => 'ghcr.io/brf-tech/runr/reqs/redis:7-alpine',
            'command' => ['sh', '-c', "redis-cli -h {$host} -p {$port} {$auth} XADD {$c['stream']} '*' e2e network"],
        ]]]);
        if ($add !== true) {
            return $add;
        }

        $deadline = time() + 180;
        do {
            sleep(5);
            $stats = $this->netConsumerStats($c);
            if (is_array($stats) && $stats['delivered'] > $before['delivered']) {
                $this->line('    consumer stats: '.json_encode($stats));

                return $stats['failed'] === $before['failed'] ?: 'delivered after '.($stats['failed'] - $before['failed']).' failed attempt(s): '.json_encode($stats);
            }
        } while (time() < $deadline);

        return 'not delivered within 180 s: '.json_encode($stats);
    }

    private function netRoutes(array $c): bool|string
    {
        $problems = [];
        $region = KnativeRegion::findOrFail($c['regionId']);

        // custom domain (DNS ownership is the API's part: verified here)
        $domains = app(ProjectDomainService::class);
        $project = Project::findOrFail($c['webId']);
        $domain = ProjectDomain::query()->firstOrCreate(
            ['project_id' => $project->id, 'domain' => self::NET_CUSTOM_DOMAIN],
            ['status' => ProjectDomain::STATUS_VERIFIED, 'verification_token' => bin2hex(random_bytes(8)), 'tls_mode' => ProjectDomain::TLS_NONE],
        );
        if (! $domains->activate($domain->setRelation('project', $project))) {
            $problems[] = 'custom domain not activated: '.$domain->fresh()->status_message;
        }

        // region domain alias, as a base-domain move applies it
        app(RegionDomainAliasService::class)->apply($region, self::NET_ALIAS_DOMAIN, self::NET_ALIAS_NAME);
        if (! $this->netWaitDeployment($c['k8s'], $c['ns'], self::NET_ALIAS_NAME, 180)) {
            $problems[] = 'the alias proxy is not ready';
        }

        // gateway route
        $user = User::query()->where('username', config('app.admin.username'))->first() ?? User::query()->firstOrFail();
        $gateway = Gateway::query()->firstOrCreate(['user_id' => $user->id, 'knative_region_id' => $region->id], ['subdomain' => 'netgw', 'status' => 'active']);
        GatewayRoute::query()->firstOrCreate(['gateway_id' => $gateway->id, 'project_id' => $project->id], ['path' => '/web', 'strip_prefix' => true, 'sort_order' => 1]);
        app(GatewayService::class)->syncToCluster($gateway->fresh(['routes.project']));
        $gatewayHost = app(GatewayService::class)->gatewayHost($gateway);

        $checks = [
            'custom domain' => [self::NET_CUSTOM_DOMAIN, '/', false, 200],
            'alias' => ["{$c['web']}.".self::NET_ALIAS_DOMAIN, '/', false, 200],
            'alias of the cluster-local service' => ["{$c['sink']}.".self::NET_ALIAS_DOMAIN, '/', false, 404],
            'gateway route' => [$gatewayHost, '/web/', true, 200],
        ];
        foreach ($checks as $label => [$host, $path, $https, $want]) {
            $got = $this->netExternal($c['ip'], $host, $path, $https, $want === 200 ? 120 : 0);
            $this->line("    {$label}: Host {$host}{$path} → {$got}");
            if ($got !== $want) {
                $problems[] = "{$label} {$host}{$path}: {$got} (want {$want})";
            }
        }

        return $problems === [] ?: implode('; ', $problems);
    }

    /**
     * The region as an earlier release left it, region:reconcile's private-ingress and
     * network-policy steps under load, the disruption measured.
     */
    private function netUpgrade(array $c): bool|string
    {
        $k8s = $c['k8s'];
        $legacyLb = PrivateIngress::serviceHostname(KnativeConstants::TRAEFIK_RELEASE, $c['servingNs']);

        // 1. an earlier release's Traefik: cluster-local routes on web, no internal port
        $helm = app(HelmService::class);
        $helm->repoAdd(KnativeConstants::TRAEFIK_REPO_NAME, KnativeConstants::TRAEFIK_REPO_URL);
        $helm->repoUpdate();
        $kubeconfig = KnativeRegion::findOrFail($c['regionId'])->kubeconfig;
        $result = $helm->upgradeReusingValues(KnativeConstants::TRAEFIK_RELEASE, KnativeConstants::TRAEFIK_CHART, $c['servingNs'], $kubeconfig,
            KnativeConstants::TRAEFIK_CHART_VERSION, [
                'additionalArguments' => json_encode(PrivateIngress::additionalArguments($c['servingNs'], PrivateIngress::STATE_LEGACY), JSON_UNESCAPED_SLASHES),
                'ports.internal' => 'null',
                'service.additionalServices.internal' => 'null',
            ], true);
        if (! $result->success) {
            return 'downgrade of Traefik failed: '.trim($result->error);
        }
        $this->netWaitTraefikPods($k8s, $c['servingNs'], PrivateIngress::STATE_LEGACY);
        // its KIngresses and placeholders name traefik
        foreach ($k8s->listClusterResources('networking.internal.knative.dev/v1alpha1', 'Ingress')['items'] ?? [] as $ingress) {
            $k8s->mergePatchStatus('networking.internal.knative.dev/v1alpha1', 'Ingress', $ingress['metadata']['name'], $ingress['metadata']['namespace'],
                ['status' => ['observedGeneration' => max(0, (int) $ingress['metadata']['generation'] - 1)]]);
        }
        $deadline = time() + 120;
        while (array_keys($this->netKingressBalancers($c)) !== [$legacyLb] && time() < $deadline) {
            sleep(3);
        }
        // the custom domain and the alias rewrite to cluster-local hosts, no NetworkPolicy
        $mw = ProjectDomainManifests::resourceName($c['webId'], self::NET_CUSTOM_DOMAIN);
        $k8s->mergePatch(ProjectDomainManifests::TRAEFIK_API, 'Middleware', $mw, $c['ns'], [
            'metadata' => ['labels' => ['runr.dev/host-rewrite' => null], 'annotations' => ['runr.dev/service' => null]],
            'spec' => ['headers' => ['customRequestHeaders' => ['Host' => "{$c['web']}.{$c['ns']}.svc"]]],
        ]);
        $k8s->strategicMergePatch('apps/v1', 'Deployment', self::NET_ALIAS_NAME, $c['ns'], ['spec' => ['template' => ['spec' => ['containers' => [[
            'name' => 'proxy', 'env' => [['name' => 'SERVICE_SUFFIX', 'value' => ".{$c['ns']}.svc"]],
        ]]]]]]);
        $this->netWaitDeployment($k8s, $c['ns'], self::NET_ALIAS_NAME, 180);
        $k8s->deleteResource(RegionNetworkPolicyManifests::API, 'NetworkPolicy', RegionNetworkPolicyManifests::REVISIONS, $c['ns']);
        $k8s->deleteResource(RegionNetworkPolicyManifests::API, 'NetworkPolicy', RegionNetworkPolicyManifests::ACTIVATOR, $c['servingNs']);
        $this->line('    as an earlier release left it: KIngresses '.json_encode($this->netKingressBalancers($c)));

        // 2. the hole is open there — what the checks above would find
        $open = $this->netOutside($c, 200);
        $bypass = $this->netBypass($c, true);
        if ($open !== true || $bypass !== true) {
            return 'the earlier release\'s region is not open as expected (the checks would prove nothing): '.($open === true ? '' : $open.' ').($bypass === true ? '' : $bypass);
        }
        $this->line('    a gateway route as earlier releases built it (to the ExternalName placeholder): '.$this->netLegacyGateway($c));

        // 3. the dry run names the change and writes nothing
        Artisan::call('region:reconcile', ['--region' => $c['regionId'], '--dry-run' => true]);
        $plan = array_values(array_filter(explode("\n", Artisan::output()), fn ($l) => preg_match('/\[(private-ingress|network-policy)\]/', $l)));
        foreach ($plan as $line) {
            $this->line('    dry run:'.$line);
        }
        if (! collect($plan)->contains(fn ($l) => str_contains($l, '[private-ingress] would upgrade')) || ! collect($plan)->contains(fn ($l) => str_contains($l, '[network-policy] would apply'))) {
            return 'dry run: '.implode(' | ', $plan);
        }

        // 4. probes, then the two steps under them
        $probes = $this->netStartProbes($c);
        sleep(15);
        $started = microtime(true);
        try {
            $lines = array_merge((new PrivateIngressStep)->run(($c['group'])(), false), (new NetworkPolicyStep)->run(($c['group'])(), false));
        } catch (\Throwable $e) {
            $lines = ['failed: '.$e->getMessage()];
        }
        $took = round(microtime(true) - $started, 1);
        foreach ($lines as $line) {
            $this->line("    reconcile: {$line}");
        }
        sleep(20);
        $measured = $this->netStopProbes($c, $probes, $started, $started + $took);
        $this->line("    private-ingress + network-policy took {$took} s");
        foreach ($measured as $probe => $m) {
            $this->line(sprintf('    disruption %-9s %4d calls, %d failed%s', $probe, $m['calls'], $m['failed'],
                $m['failed'] ? ' ('.implode(', ', $m['codes']).'; longest '.$m['longest'].' s, at +'.implode(', +', $m['at']).' s)' : ''));
        }

        $problems = [];
        if (collect($lines)->contains(fn ($l) => str_starts_with($l, 'failed:'))) {
            $problems[] = implode(' | ', $lines);
        }
        foreach ($measured as $probe => $m) {
            if ($m['calls'] < 10 || $m['failed'] > 0) {
                $problems[] = "{$probe}: {$m['failed']} of {$m['calls']} calls failed";
            }
        }

        // 5. closed again, and nothing left to do
        foreach (['outside' => $this->netOutside($c, 404), 'bypass' => $this->netBypass($c, false)] as $label => $result) {
            if ($result !== true) {
                $problems[] = "{$label} after the reconcile: {$result}";
            }
        }
        $again = array_merge((new PrivateIngressStep)->run(($c['group'])(), false), (new NetworkPolicyStep)->run(($c['group'])(), false));
        if ($again !== []) {
            $problems[] = 'a second run: '.implode(' | ', $again);
        }

        return $problems === [] ?: implode('; ', $problems);
    }

    // ── Probes ──────────────────────────────────────────────────────────────

    /** @return array<string, string> probe → pod name */
    private function netStartProbes(array $c): array
    {
        $traefik = 'http://'.KnativeConstants::TRAEFIK_RELEASE.".{$c['servingNs']}.svc.cluster.local/";
        $calls = [
            'private' => "-X POST -H 'Content-Type: application/json' {$this->netToken($c, $c['sink'])} -d '{}' http://{$c['sink']}.{$c['ns']}.svc.cluster.local/",
            'public' => "http://{$c['web']}.{$c['domain']}/",
            'custom' => "-H 'Host: ".self::NET_CUSTOM_DOMAIN."' {$traefik}",
            'alias' => "-H 'Host: {$c['web']}.".self::NET_ALIAS_DOMAIN."' {$traefik}",
        ];
        $pods = [];
        foreach ($calls as $probe => $call) {
            $name = "net-probe-{$probe}";
            $this->netCreatePod($name, self::NET_NS, self::NET_CURL,
                "while true; do echo \"\$(date +%s) \$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 {$call})\"; sleep 0.5; done");
            $pods[$probe] = $name;
        }

        [$host, $port, $password] = $this->netRedis();
        $auth = $password ? "-a '{$password}' --no-auth-warning" : '';
        $this->netCreatePod('net-probe-xadd', self::NET_NS, 'ghcr.io/brf-tech/runr/reqs/redis:7-alpine',
            "while true; do echo \"\$(date +%s) \$(redis-cli -h {$host} -p {$port} {$auth} XADD {$c['stream']} '*' probe 1 >/dev/null && echo 200 || echo 000)\"; sleep 2; done");
        $pods['xadd'] = 'net-probe-xadd';
        $this->netQueueBefore = $this->netConsumerStats($c);

        return $pods;
    }

    /** @var array|string|null consumer stats when the probes started */
    private array|string|null $netQueueBefore = null;

    /**
     * Stops the probes: per probe the calls, the failed ones (any answer but 200), their codes, the
     * longest run of failures in seconds and when they started (seconds after $from). The queue
     * probe counts the messages the consumer failed to deliver (attempts) and still holds.
     *
     * @return array<string, array{calls: int, failed: int, codes: list<string>, longest: int, at: list<int>}>
     */
    private function netStopProbes(array $c, array $pods, float $from, float $until): array
    {
        $k8s = $c['k8s'];
        $out = [];
        foreach ($pods as $probe => $pod) {
            $log = (string) $k8s->getPodLogs($pod, self::NET_NS, null, 100000);
            $k8s->deleteResource('v1', 'Pod', $pod, self::NET_NS);
            $calls = 0;
            $failed = [];
            foreach (explode("\n", trim($log)) as $line) {
                if (! preg_match('/^(\d+) (\d{3})$/', trim($line), $m)) {
                    continue;
                }
                $calls++;
                if ($m[2] !== '200') {
                    $failed[] = [(int) $m[1], $m[2]];
                }
            }
            $runs = [];
            foreach ($failed as [$t]) {
                $last = array_key_last($runs);
                if ($last !== null && $t - $runs[$last][1] <= 2) {
                    $runs[$last][1] = $t;
                } else {
                    $runs[] = [$t, $t];
                }
            }
            $out[$probe] = [
                'calls' => $calls,
                'failed' => count($failed),
                'codes' => array_values(array_unique(array_column($failed, 1))),
                'longest' => $runs ? max(array_map(fn ($r) => $r[1] - $r[0] + 1, $runs)) : 0,
                'at' => array_map(fn ($r) => (int) round($r[0] - $from), $runs),
            ];
        }

        // the messages XADDed meanwhile: delivered, none failed
        $deadline = time() + 60;
        do {
            $stats = $this->netConsumerStats($c);
            $settled = is_array($stats) && ($stats['pending'] ?? 1) === 0;
            if (! $settled) {
                sleep(5);
            }
        } while (! $settled && time() < $deadline);
        $before = is_array($this->netQueueBefore) ? $this->netQueueBefore : ['delivered' => 0, 'failed' => 0];
        if (is_array($stats)) {
            $out['queue'] = [
                'calls' => $stats['delivered'] - $before['delivered'],
                'failed' => $stats['failed'] - $before['failed'] + (int) ($stats['pending'] ?? 0),
                'codes' => ["delivered {$stats['delivered']}, failed attempts {$stats['failed']}, pending ".($stats['pending'] ?? '?')],
                'longest' => 0,
                'at' => [],
            ];
        }

        return $out;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** HTTP status of a request through the LoadBalancer with Host $host (0: no answer), retried until $retryFor s for a 200 */
    private function netExternal(string $ip, string $host, string $path = '/', bool $https = false, int $retryFor = 0): int
    {
        $deadline = time() + $retryFor;
        do {
            try {
                $status = Http::withOptions(['verify' => false])->timeout(15)->withHeaders(['Host' => $host])
                    ->get(($https ? 'https' : 'http')."://{$ip}{$path}")->status();
            } catch (\Throwable) {
                $status = 0;
            }
            if ($status === 200 || time() >= $deadline) {
                return $status;
            }
            sleep(5);
        } while (true);
    }

    private function netCreatePod(string $name, string $ns, string $image, string $script): void
    {
        $k8s = $this->getE2eKubernetesClient();
        $k8s->deleteResource('v1', 'Pod', $name, $ns);
        for ($i = 0; $i < 20 && $k8s->getResourceStatus('v1', 'Pod', $name, $ns); $i++) {
            sleep(2);
        }
        $k8s->createResource([
            'apiVersion' => 'v1', 'kind' => 'Pod',
            'metadata' => ['name' => $name, 'namespace' => $ns, 'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/purpose' => 'e2e-network']],
            'spec' => [
                'restartPolicy' => 'Never',
                'terminationGracePeriodSeconds' => 1,
                'containers' => [[
                    'name' => 'probe', 'image' => $image, 'command' => ['sh', '-c', $script],
                    'resources' => ['requests' => ['cpu' => '20m', 'memory' => '16Mi'], 'limits' => ['cpu' => '200m', 'memory' => '64Mi']],
                ]],
            ],
        ], $ns);
    }

    /** curl's header with the service's own token on the internal network (a self call) */
    private function netToken(array $c, string $service): string
    {
        $token = InternalNetwork::tokenFor($c['regionId'], InternalNetwork::nameFor($service, null));

        return "-H '".InternalNetwork::HEADER.": {$token}'";
    }

    /** Runs a curl script pod to its end: [phase, log] */
    private function netCurlPod(string $name, string $ns, string $script, int $timeout = 240): array
    {
        $k8s = $this->getE2eKubernetesClient();
        $this->netCreatePod($name, $ns, self::NET_CURL, $script);
        $phase = $this->waitForPods($k8s, $ns, [$name], $timeout)[$name] ?? 'Timeout';
        $log = (string) $k8s->getPodLogs($name, $ns, null, 1000);
        $k8s->deleteResource('v1', 'Pod', $name, $ns);

        return [$phase, $log];
    }

    /** Calls the service's cluster-local URL until one of its pods runs: [pod, address, revision], or why not */
    private function netWarmPod(array $c, string $service): array|string
    {
        $url = "http://{$service}.{$c['ns']}.svc.cluster.local/";
        $this->netCurlPod('net-warm', self::NET_NS, "curl -s -o /dev/null --max-time 90 -X POST {$this->netToken($c, $service)} -d '{}' {$url}");
        $deadline = time() + 120;
        do {
            foreach ($c['k8s']->listResources('v1', 'Pod', $c['ns'], ['serving.knative.dev/service' => $service])['items'] ?? [] as $pod) {
                if (($pod['status']['phase'] ?? '') === 'Running' && ($pod['status']['podIP'] ?? '') !== '' && ! isset($pod['metadata']['deletionTimestamp'])) {
                    return [$pod['metadata']['name'], $pod['status']['podIP'], $pod['metadata']['labels']['serving.knative.dev/revision'] ?? ''];
                }
            }
            sleep(2);
        } while (time() < $deadline);

        return "no running pod of {$service} after a call to {$url}";
    }

    private function netWaitScaledToZero(array $c, string $service, int $timeout): bool
    {
        $deadline = time() + $timeout;
        do {
            if (($c['k8s']->listResources('v1', 'Pod', $c['ns'], ['serving.knative.dev/service' => $service])['items'] ?? [null]) === []) {
                return true;
            }
            sleep(5);
        } while (time() < $deadline);

        return false;
    }

    private function netWaitDeployment(KubernetesClient $k8s, string $ns, string $name, int $timeout): bool
    {
        $deadline = time() + $timeout;
        do {
            $d = $k8s->getResourceStatus('apps/v1', 'Deployment', $name, $ns);
            $want = (int) ($d['spec']['replicas'] ?? 1);
            if ($d && ($d['status']['observedGeneration'] ?? 0) >= ($d['metadata']['generation'] ?? 1)
                && ($d['status']['updatedReplicas'] ?? 0) >= $want && ($d['status']['availableReplicas'] ?? 0) >= $want
                && ($d['status']['replicas'] ?? 0) === $want) {
                return true;
            }
            sleep(3);
        } while (time() < $deadline);

        return false;
    }

    private function netWaitTraefikPods(KubernetesClient $k8s, string $ns, string $state): void
    {
        $deadline = time() + 300;
        do {
            $pods = $k8s->listResources('v1', 'Pod', $ns, ['app.kubernetes.io/name' => 'traefik'])['items'] ?? [];
            $behind = array_filter($pods, fn ($p) => PrivateIngress::state(collect($p['spec']['containers'] ?? [])->firstWhere('name', 'traefik')['args'] ?? []) !== $state);
            if ($pods !== [] && $behind === []) {
                return;
            }
            sleep(3);
        } while (time() < $deadline);
    }

    /**
     * What a gateway route of an earlier release answered: its IngressRoute sent the request to the
     * project's ExternalName placeholder, without a host rewrite.
     */
    private function netLegacyGateway(array $c): string
    {
        $k8s = $c['k8s'];
        $region = KnativeRegion::findOrFail($c['regionId']);
        $host = "netgw-legacy.{$region->name}.".Setting::get('base_domain');
        $k8s->applyResource([
            'apiVersion' => 'traefik.io/v1alpha1', 'kind' => 'IngressRoute',
            'metadata' => ['name' => 'gw-legacy-e2e', 'namespace' => $c['ns']],
            'spec' => ['entryPoints' => ['websecure'], 'routes' => [[
                'match' => "Host(`{$host}`) && PathPrefix(`/web`)", 'kind' => 'Rule',
                'services' => [['name' => $c['web'], 'port' => 80]],
            ]]],
        ], $c['ns']);
        sleep(5);
        $code = $this->netExternal($c['ip'], $host, '/web/', true);
        $k8s->deleteResource('traefik.io/v1alpha1', 'IngressRoute', 'gw-legacy-e2e', $c['ns']);

        return "Host {$host}/web/ → {$code}";
    }

    /** @return array<string, int> private load balancer → KIngresses naming it */
    private function netKingressBalancers(array $c): array
    {
        $lbs = [];
        foreach ($c['k8s']->listClusterResources('networking.internal.knative.dev/v1alpha1', 'Ingress')['items'] ?? [] as $ingress) {
            $lb = PrivateIngress::privateLoadBalancer($ingress) ?? '(none)';
            $lbs[$lb] = ($lbs[$lb] ?? 0) + 1;
        }
        ksort($lbs);

        return $lbs;
    }

    /** The queue consumer's /stats (delivered, failed, pending), or why not */
    private function netConsumerStats(array $c): array|string
    {
        $pods = $c['k8s']->listResources('v1', 'Pod', $c['ns'], ['runr/project-id' => $c['sinkId'], 'runr/component' => 'queue-consumer'])['items'] ?? [];
        $ip = collect($pods)->first(fn ($p) => ($p['status']['phase'] ?? '') === 'Running')['status']['podIP'] ?? null;
        if (! $ip) {
            return 'no running queue consumer';
        }
        [$phase, $log] = $this->netCurlPod('net-stats', self::NET_NS, "curl -s --max-time 10 http://{$ip}:8081/stats", 60);
        $stats = json_decode(trim($log), true);

        return is_array($stats) && isset($stats['delivered']) ? $stats : "consumer stats {$phase}: {$log}";
    }

    /** @return array{0: string, 1: int, 2: ?string} the region Redis host, port, password */
    private function netRedis(): array
    {
        $region = $this->getE2eRegion()->fresh();
        $address = (string) ($region->redis_address ?? '');
        $parsed = parse_url(str_contains($address, '://') ? $address : "redis://{$address}");

        return [(string) ($parsed['host'] ?? ''), (int) ($parsed['port'] ?? 6379), $region->redis_password];
    }

    /** Autoscaler log lines since $since (epoch) about net-sink with a failed scrape */
    private function netAutoscalerErrors(array $c, int $since): array
    {
        $errors = [];
        foreach ($c['k8s']->listResources('v1', 'Pod', $c['servingNs'], ['app' => 'autoscaler'])['items'] ?? [] as $pod) {
            foreach (explode("\n", (string) $c['k8s']->getPodLogs($pod['metadata']['name'], $c['servingNs'], 'autoscaler', 5000)) as $line) {
                $at = preg_match('/"timestamp":"([^"]+)"/', $line, $m) ? strtotime($m[1]) : false;
                if ($at !== false && $at >= $since && str_contains($line, $c['sink']) && preg_match('/scrap/i', $line)
                    && preg_match('/"(severity|level)":"(ERROR|error|WARN|warn)/', $line)) {
                    $errors[] = mb_substr($line, 0, 300);
                }
            }
        }

        return $errors;
    }

    /**
     * A base-domain migration completed by hand, there and back, and one set back before it
     * completed: the custom domain, the gateway route and the gateway follow at once
     * (DomainMoveFollower), not on the next status check.
     */
    private function netDomainMove(array $c): bool|string
    {
        $region = KnativeRegion::findOrFail($c['regionId']);
        $old = (string) Setting::get('base_domain');
        if ($old === '' || ! RegionDomains::followsBase($region, $old)) {
            $this->line('    skipped: the e2e region has a function domain of its own');

            return true;
        }
        $gateway = Gateway::query()->where('knative_region_id', $region->id)->with('routes')->first();
        $route = $gateway?->routes->first();
        if (! $gateway || ! $route) {
            return 'no gateway route of net-routes';
        }

        $k8s = $c['k8s'];
        $customRewrite = ProjectDomainManifests::resourceName($c['webId'], self::NET_CUSTOM_DOMAIN);
        $rewrite = fn (string $name) => $k8s->getResourceStatus(ProjectDomainManifests::TRAEFIK_API, 'Middleware', $name, $c['ns'])['spec']['headers']['customRequestHeaders']['Host'] ?? null;
        $gatewayMatch = fn () => (string) ($k8s->getResourceStatus(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', "gw-{$gateway->id}", $c['ns'])['spec']['routes'][0]['match'] ?? '');
        $problems = [];

        foreach (['moved-'.$old, $old] as $to) {
            $set = $this->api()->patch('/api/settings', ['base_domain' => $to]);
            $complete = $this->api()->post("/api/knative-regions/{$region->id}/complete-domain-migration");
            if (! $set->successful() || ! $complete->successful()) {
                return "moving to {$to}: settings HTTP {$set->status()}, completion HTTP {$complete->status()} ".mb_substr($complete->body(), 0, 200);
            }

            // at once: read right after the completion answered
            $webHost = "{$c['web']}.{$region->name}.{$to}";
            $gatewayHost = "{$gateway->subdomain}.{$region->name}.{$to}";
            foreach (['custom domain' => $customRewrite, 'gateway route' => "gw-host-{$route->id}"] as $label => $name) {
                if (($got = $rewrite($name)) !== $webHost) {
                    $problems[] = "{$label} after the move to {$to} rewrites to {$got} (want {$webHost})";
                }
            }
            if (! str_contains($gatewayMatch(), "Host(`{$gatewayHost}`)")) {
                $problems[] = "the gateway's IngressRoute after the move to {$to}: {$gatewayMatch()}";
            }

            // and served once Knative has switched over
            $custom = $this->netExternal($c['ip'], self::NET_CUSTOM_DOMAIN, '/', false, 120);
            $through = $this->netExternal($c['ip'], $gatewayHost, '/web/', true, 120);
            $this->line("    moved to {$to}: custom domain {$custom}, gateway {$gatewayHost} {$through}");
            if ($custom !== 200 || $through !== 200) {
                $problems[] = "after the move to {$to}: custom domain {$custom}, gateway {$through} (want 200)";
            }
        }

        // a move set back before it completed: everything stays on the current hosts
        $this->api()->patch('/api/settings', ['base_domain' => 'third-'.$old]);
        $this->api()->patch('/api/settings', ['base_domain' => $old]);
        if (($got = $rewrite($customRewrite)) !== "{$c['web']}.{$region->name}.{$old}") {
            $problems[] = "after a rollback the custom domain rewrites to {$got}";
        }
        if ($k8s->getResourceStatus('apps/v1', 'Deployment', RegionDomainAliasService::NAME, $c['ns'])) {
            $problems[] = 'the alias of the rolled back move is still there';
        }

        return $problems === [] ?: implode('; ', $problems);
    }

    private function netCleanup(array $c): void
    {
        try {
            $region = KnativeRegion::findOrFail($c['regionId']);
            app(RegionDomainAliasService::class)->remove($region, self::NET_ALIAS_NAME);
            foreach (Gateway::query()->where('knative_region_id', $region->id)->get() as $gateway) {
                app(GatewayService::class)->teardown($gateway->load('routes'));
            }
            foreach (ProjectDomain::query()->where('domain', self::NET_CUSTOM_DOMAIN)->get() as $domain) {
                app(ProjectDomainService::class)->removeFromRegion($domain->load('project'), $region);
            }
            $c['k8s']->deleteClusterResource('v1', 'Namespace', self::NET_NS);
        } catch (\Throwable $e) {
            $this->warn('  network suite cleanup: '.$e->getMessage());
        }
    }
}
