<?php

namespace App\Console\Commands\Concerns;

use App\Jobs\ApplyInternalNetworkJob;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Setting;
use App\Services\Contracts\KnativeConstants;
use App\Services\HelmService;
use App\Services\Helpers\PrivateIngress;
use App\Services\Helpers\RegionDomains;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\InternalNetwork\InternalNetworkManifests as M;
use App\Services\InternalNetwork\InternalNetworkService;
use App\Services\InternalNetwork\RegionInternalNetwork;
use App\Services\Manifests\ProjectDomainManifests;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\Steps\InternalNetworkStep;

/**
 * E2E of the region's internal network (test:e2e --suite=internal-network,
 * runtime/lib/test-internal-network.sh, docs/internal-network.md): an HTTP project that echoes
 * what it gets (in-api, Python) and a queue-triggered one (in-sink) on the e2e region, then
 *
 *  - in-installed   a new region runs the authz (two pods), the private entry point asks it
 *                   (ForwardAuth, then the token header removed), CoreDNS sends runr.internal to
 *                   traefik-internal, the region enforces, its key is the API's
 *  - in-enforce     from a pod: no token 401, a token of another key 401, a caller not on the
 *                   allow list 403, the project itself and RunR (runr:system) 200 — on the
 *                   cluster-local host and on <project>.<organization>.runr.internal
 *  - in-identity    a pod of a project without a token (a workload of an earlier release) is
 *                   that project: its own service answers, another one only once its allow list
 *                   names it
 *  - in-allow-list  a caller added to the allow list is admitted within the authz's reload, a
 *                   removed one refused again; the callee sees the verified caller
 *                   (X-Runr-Caller), never the token
 *  - in-cross-org   a project of another organization needs a grant; the platform's switch off
 *                   refuses granted callers too
 *  - in-access      access mode internal: no public host (404 through the LoadBalancer), the
 *                   internal host answers; allowlist: a range without the client 403, 0.0.0.0/0
 *                   200; public again
 *  - in-queue       a message reaches the queue-triggered project through the authz (the
 *                   consumer sends the project's token)
 *  - in-environment an environment "staging" of the queue-triggered project: its workload is
 *                   <sink>-staging.<organization> on the internal network (its token's subject),
 *                   and a message on its own stream reaches it through the authz with that token
 *  - in-system      RunR's own call through the API server (shell, model playground) carries
 *                   runr:system; without it 401
 *  - in-audit       a region set to audit — explicitly, an operator's choice — admits what the
 *                   allow list refuses and logs it
 *  - in-model       a small model service (the AI suite's tiny CPU model on hf-serve, online)
 *                   bound to in-api as EMBED: in-api's pods call it without a header (known by
 *                   their address) and with in-api's token; in-sink's pods and token are refused
 *                   (403), a pod of no project without a token too (401)
 *  - in-upgrade     the region as an earlier release left it (no internal_auth, no authz, no
 *                   ForwardAuth, no runr.internal, Knative Services — the model service's
 *                   included — without policy, a queue consumer without a token);
 *                   region:reconcile's internal-network step while RunR's own traffic goes on — a
 *                   message XADDed every two seconds, a pod of the queue project calling its own
 *                   service every half second and a pod of in-api calling its bound model service,
 *                   all without a token: none fails. Afterwards the region enforces: a call between
 *                   the two projects without an allow-list entry is refused, the bound model calls
 *                   pass with and without a token and the unbound ones are refused; a second run
 *                   changes nothing
 *
 * The model service needs the hf-serve image (--hf-serve-image, which ci.sh loads into Kind) and
 * the Hub's tiny model online; RUNR_E2E_IN_MODEL=0 leaves it out (in-model is then skipped and
 * in-upgrade checks the projects only).
 */
trait E2eTestInternalNetworkTrait
{
    private function runInternalNetworkSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Internal network: authz, allow lists, access modes ──');

        $k8s = $this->getE2eKubernetesClient();
        $this->ensureNamespace($k8s, self::NET_NS);

        $python = collect($this->fetchExecutorImages())->first(fn ($i) => ($i['runtime'] ?? '') === 'python' && ($i['is_default'] ?? false));
        $python = ['service_type' => 'xaas', 'runtime' => 'python', 'runtime_version' => $python['version'] ?? null, 'code' => self::xaasEchoCode()['python']];
        $api = $this->makeTestEntry('in-api', $python + ['trigger_type' => 'http'], $regionId);
        $sink = $this->makeTestEntry('in-sink', $python + ['trigger_type' => 'queue'], $regionId);
        $tests = [$api, $sink];
        foreach ($tests as $i => &$test) {
            $test['startTime'] = microtime(true);
            $this->runSingleTest($test, $i + 1, 2);
            $test['steps']['verify'] = $test['steps']['ready'] === true ? true : 'skipped';
            $test = $this->finishTest($test);
        }
        unset($test);
        [$api, $sink] = $tests;
        if ($api['steps']['ready'] !== true || $sink['steps']['ready'] !== true) {
            return $tests;
        }

        $c = $this->inContext($regionId, $api, $sink);
        $this->line("    {$c['apiName']} ({$c['api']}), {$c['sinkName']} ({$c['sink']}); namespace {$c['ns']}, LoadBalancer {$c['ip']}");
        // the model service in-api is bound to (in-model, in-upgrade); a string says why there is none
        $c['model'] = getenv('RUNR_E2E_IN_MODEL') === '0' ? 'left out (RUNR_E2E_IN_MODEL=0)' : $this->inModelService($c);
        $this->line(is_array($c['model'])
            ? "    model service {$c['model']['service_name']} bound to {$c['api']} as EMBED"
            : "    no model service: {$c['model']}");

        $tests[] = $this->recCheck('in-installed', fn () => $this->inInstalled($c));
        $tests[] = $this->recCheck('in-enforce', fn () => $this->inEnforce($c));
        $tests[] = $this->recCheck('in-identity', fn () => $this->inIdentity($c));
        $tests[] = $this->recCheck('in-allow-list', fn () => $this->inAllowList($c));
        $tests[] = $this->recCheck('in-cross-org', fn () => $this->inCrossOrg($c));
        $tests[] = $this->recCheck('in-access', fn () => $this->inAccess($c));
        $tests[] = $this->recCheck('in-queue', fn () => $this->netQueue($c));
        $tests[] = $this->recCheck('in-environment', fn () => $this->inEnvironment($c));
        $tests[] = $this->recCheck('in-system', fn () => $this->inSystem($c));
        $tests[] = $this->recCheck('in-audit', fn () => $this->inAudit($c));
        if (is_array($c['model']) || getenv('RUNR_E2E_IN_MODEL') !== '0') {
            $tests[] = $this->recCheck('in-model', fn () => $this->inModel($c));
        } else {
            $this->line("  ─ in-model skipped: {$c['model']}");
        }
        $tests[] = $this->recCheck('in-upgrade', fn () => $this->inUpgrade($c));

        $this->inCleanup($c);

        return $tests;
    }

    private function inContext(string $regionId, array $api, array $sink): array
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
            'api' => $api['slug'],
            'apiId' => $api['projectId'],
            'apiName' => InternalNetwork::nameFor($api['slug'], null),
            'sink' => $sink['slug'],
            'sinkId' => $sink['projectId'],
            'sinkName' => InternalNetwork::nameFor($sink['slug'], null),
            'stream' => "test-stream-{$sink['name']}",
            'group' => fn () => new ClusterGroup('e2e', [new ReconcileTarget(KnativeRegion::findOrFail($regionId))], $k8s),
        ];
    }

    // ── Checks ──────────────────────────────────────────────────────────────

    private function inInstalled(array $c): bool|string
    {
        $k8s = $c['k8s'];
        $problems = [];

        $authz = $k8s->getResourceStatus('apps/v1', 'Deployment', M::AUTHZ, $c['servingNs']);
        if (($authz['spec']['replicas'] ?? 0) !== 2 || ($authz['status']['readyReplicas'] ?? 0) < 1) {
            $problems[] = 'authz: '.json_encode(['replicas' => $authz['spec']['replicas'] ?? null, 'ready' => $authz['status']['readyReplicas'] ?? null]);
        }
        $this->line('    authz image '.($authz['spec']['template']['spec']['containers'][0]['image'] ?? '?'));

        $traefik = $k8s->getResourceStatus('apps/v1', 'Deployment', KnativeConstants::TRAEFIK_RELEASE, $c['servingNs']);
        $args = collect($traefik['spec']['template']['spec']['containers'] ?? [])->firstWhere('name', 'traefik')['args'] ?? [];
        $wanted = implode(',', M::entryPointMiddlewares($c['servingNs']));
        if (! collect($args)->contains(fn ($a) => (bool) preg_match('/^--entry[pP]oints\.internal\.http\.middlewares='.preg_quote($wanted, '/').'$/', (string) $a))) {
            $problems[] = 'the private entry point has no ForwardAuth: '.implode(' ', array_filter($args, fn ($a) => str_contains((string) $a, 'internal')));
        }

        $corefile = (string) ($k8s->getResourceStatus('v1', 'ConfigMap', 'coredns', 'kube-system')['data']['Corefile'] ?? '');
        $custom = $k8s->getResourceStatus('v1', 'ConfigMap', RegionInternalNetwork::COREDNS_CUSTOM, 'kube-system')['data'][RegionInternalNetwork::COREDNS_OVERRIDE_KEY] ?? '';
        if (! str_contains($corefile, M::COREDNS_BEGIN) && $custom === '') {
            $problems[] = 'CoreDNS has no runr.internal rewrite';
        }

        $mode = $k8s->getResourceStatus('v1', 'ConfigMap', M::CONFIG, $c['servingNs'])['data']['mode.'.$c['ns']] ?? null;
        if ($mode !== InternalNetwork::MODE_ENFORCE) {
            $problems[] = "a new region enforces; mode.{$c['ns']} is ".json_encode($mode);
        }
        $key = $k8s->getResourceStatus('v1', 'Secret', M::CONFIG, $c['servingNs'])['data']['key.'.$c['regionId']] ?? null;
        if ($key !== base64_encode(InternalNetwork::regionKey($c['regionId']))) {
            $problems[] = 'the region key in the cluster is not the API\'s';
        }

        $ksvc = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $c['api'], $c['ns']);
        if (($ksvc['metadata']['annotations'][M::ANNOTATION_NAME] ?? null) !== $c['apiName']) {
            $problems[] = "{$c['api']} has no internal name: ".json_encode($ksvc['metadata']['annotations'] ?? []);
        }

        return $problems === [] ?: implode('; ', $problems);
    }

    private function inEnforce(array $c): bool|string
    {
        $local = "http://{$c['api']}.{$c['ns']}.svc.cluster.local/";
        $internal = 'http://'.InternalNetwork::hostFor($c['apiName']).'/';
        $forged = InternalNetwork::sign('01JNOTTHISREGION0000000000', ['sub' => $c['sinkName'], 'reg' => $c['regionId'], 'iat' => time()]);

        return $this->inCalls($c, [
            'no-token' => [$local, null, 401],
            'other-key' => [$local, $forged, 401],
            'not-listed' => [$local, $this->inToken($c, $c['sinkName']), 403],
            'self' => [$local, $this->inToken($c, $c['apiName']), 200],
            'runr' => [$local, InternalNetwork::systemToken($c['regionId']), 200],
            'internal-host-self' => [$internal, $this->inToken($c, $c['apiName']), 200],
            'internal-host-not-listed' => [$internal, $this->inToken($c, $c['sinkName']), 403],
        ]);
    }

    /** A pod of in-sink without a token: in-sink, known by its address (InternalNetwork) */
    private function inIdentity(array $c): bool|string
    {
        $network = app(InternalNetworkService::class);
        $as = $this->inAsProject($c, $c['sinkId']);
        $internal = 'http://'.InternalNetwork::hostFor($c['apiName']).'/';
        $problems = [];

        if (($r = $this->inCalls($c, [
            'self' => ["http://{$c['sink']}.{$c['ns']}.svc.cluster.local/", null, 200],
            'not-listed' => [$internal, null, 403],
        ], 0, $as)) !== true) {
            $problems[] = $r;
        }
        $network->addCaller(Project::findOrFail($c['apiId']), $c['sink']);
        if (($r = $this->inCalls($c, ['listed' => [$internal, null, 200]], 40, $as)) !== true) {
            $problems[] = "after the grant: {$r}";
        }
        $network->removeCaller(Project::findOrFail($c['apiId']), $c['sinkName']);
        if (($r = $this->inCalls($c, ['removed' => [$internal, null, 403]], 40, $as)) !== true) {
            $problems[] = "after the removal: {$r}";
        }

        return $problems === [] ?: implode('; ', $problems);
    }

    private function inAllowList(array $c): bool|string
    {
        $network = app(InternalNetworkService::class);
        $api = Project::findOrFail($c['apiId']);
        $internal = 'http://'.InternalNetwork::hostFor($c['apiName']).'/';
        $sinkToken = $this->inToken($c, $c['sinkName']);

        $network->addCaller($api, $c['sink']);
        $added = $this->inCalls($c, ['listed' => [$internal, $sinkToken, 200]], 40);
        if ($added !== true) {
            return "after the grant: {$added}";
        }

        // the callee gets the verified caller, never the caller's token
        [, $log] = $this->netCurlPod('in-echo', self::NET_NS, "curl -s --max-time 30 -X POST -H 'Content-Type: application/json' -H '".InternalNetwork::HEADER.": {$sinkToken}' -d '{}' {$internal}");
        $headers = array_change_key_case((array) (json_decode(trim($log), true)['headers'] ?? []), CASE_LOWER);
        $this->line('    the callee sees: x-runr-caller '.json_encode($headers['x-runr-caller'] ?? null).', x-runr-internal-token '.json_encode($headers['x-runr-internal-token'] ?? null));
        $problems = [];
        if (($headers['x-runr-caller'] ?? null) !== $c['sinkName']) {
            $problems[] = 'X-Runr-Caller: '.json_encode($headers['x-runr-caller'] ?? null).' (log: '.mb_substr($log, 0, 300).')';
        }
        if (isset($headers['x-runr-internal-token'])) {
            $problems[] = 'the callee got the caller\'s token';
        }

        $network->removeCaller($api, $c['sinkName']);
        $removed = $this->inCalls($c, ['removed' => [$internal, $sinkToken, 403]], 40);
        if ($removed !== true) {
            $problems[] = "after the removal: {$removed}";
        }

        return $problems === [] ?: implode('; ', $problems);
    }

    private function inCrossOrg(array $c): bool|string
    {
        $network = app(InternalNetworkService::class);
        $api = Project::findOrFail($c['apiId']);
        $partner = 'partner.acme';
        $call = ['http://'.InternalNetwork::hostFor($c['apiName']).'/', $this->inToken($c, $partner)];
        $problems = [];

        if (($r = $this->inCalls($c, ['no-grant' => [...$call, 403]])) !== true) {
            $problems[] = $r;
        }
        $network->addCaller($api, $partner);
        if (($r = $this->inCalls($c, ['granted' => [...$call, 200]], 40)) !== true) {
            $problems[] = $r;
        }

        // the platform switches cross-organization grants off: the grant stays, the call is refused
        Setting::set(InternalNetwork::CROSS_TENANT_SETTING, 'false');
        dispatch_sync(new ApplyInternalNetworkJob(KnativeRegion::findOrFail($c['regionId'])));
        if (($r = $this->inCalls($c, ['switched-off' => [...$call, 403]], 40)) !== true) {
            $problems[] = $r;
        }
        Setting::set(InternalNetwork::CROSS_TENANT_SETTING, 'true');
        dispatch_sync(new ApplyInternalNetworkJob(KnativeRegion::findOrFail($c['regionId'])));
        if (($r = $this->inCalls($c, ['switched-on' => [...$call, 200]], 40)) !== true) {
            $problems[] = $r;
        }
        $network->removeCaller($api, $partner);

        return $problems === [] ?: implode('; ', $problems);
    }

    private function inAccess(array $c): bool|string
    {
        $network = app(InternalNetworkService::class);
        $public = "{$c['api']}.{$c['domain']}";
        $problems = [];

        $network->setAccess(Project::findOrFail($c['apiId']), Project::ACCESS_INTERNAL, null);
        $this->inExpectExternal($c, $public, 404, $problems, 'internal: public host');
        if (($r = $this->inCalls($c, ['internal-host' => ['http://'.InternalNetwork::hostFor($c['apiName']).'/', $this->inToken($c, $c['apiName']), 200]], 30)) !== true) {
            $problems[] = "internal: {$r}";
        }

        // TEST-NET-1: no client is in it
        $network->setAccess(Project::findOrFail($c['apiId']), Project::ACCESS_ALLOWLIST, ['192.0.2.0/24']);
        $this->inExpectExternal($c, $public, 403, $problems, 'allowlist 192.0.2.0/24');
        $network->setAccess(Project::findOrFail($c['apiId']), Project::ACCESS_ALLOWLIST, ['0.0.0.0/0']);
        $this->inExpectExternal($c, $public, 200, $problems, 'allowlist 0.0.0.0/0');

        $network->setAccess(Project::findOrFail($c['apiId']), Project::ACCESS_PUBLIC, null);
        $this->inExpectExternal($c, $public, 200, $problems, 'public again');
        if ($c['k8s']->getResourceStatus(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', M::accessName($c['api']), $c['ns'])) {
            $problems[] = 'the allowlist route stayed';
        }

        return $problems === [] ?: implode('; ', $problems);
    }

    private function inSystem(array $c): bool|string
    {
        $k8s = $c['k8s'];
        $signed = $k8s->knativePrivateRequest('POST', $c['servingNs'], $c['ns'], $c['api'], '', ['Content-Type' => 'application/json'], '{}', 60, InternalNetwork::systemToken($c['regionId']))->status();
        $unsigned = $k8s->knativePrivateRequest('POST', $c['servingNs'], $c['ns'], $c['api'], '', ['Content-Type' => 'application/json'], '{}', 60)->status();
        $this->line("    through the API server: runr:system {$signed}, no token {$unsigned}");

        return [$signed, $unsigned] === [200, 401] ?: "runr:system {$signed} (want 200), no token {$unsigned} (want 401)";
    }

    private function inAudit(array $c): bool|string
    {
        $this->inSetMode($c, InternalNetwork::MODE_AUDIT);
        $call = ["http://{$c['api']}.{$c['ns']}.svc.cluster.local/", $this->inToken($c, $c['sinkName'])];
        $since = time();
        $problems = [];
        if (($r = $this->inCalls($c, ['audit-admits' => [...$call, 200]], 40)) !== true) {
            $problems[] = $r;
        }
        $logged = $this->inAuthzLog($c, 'audit', $c['sinkName'], $since);
        $this->line('    authz audit lines for '.$c['sinkName'].': '.count($logged).($logged ? ' — '.$logged[0] : ''));
        if ($logged === []) {
            $problems[] = 'the authz logged no audit line';
        }

        $this->inSetMode($c, InternalNetwork::MODE_ENFORCE);
        if (($r = $this->inCalls($c, ['enforced-again' => [...$call, 403]], 40)) !== true) {
            $problems[] = $r;
        }

        return $problems === [] ?: implode('; ', $problems);
    }

    /**
     * A bound model service on the internal network (docs/internal-network.md#model-services-queue-triggers-and-previews):
     * the bound project's pods without a header and its token pass; another project's pods and
     * token are refused, a caller of no project without a token too.
     */
    private function inModel(array $c): bool|string
    {
        if (! is_array($c['model'])) {
            return 'no model service: '.$c['model'];
        }
        [$url, $body] = $this->inModelCall($c);
        $problems = [];

        // the first call may start the service (scale from zero, the model loaded): up to 3 minutes
        if (($r = $this->inCalls($c, ['bound-no-token' => [$url, null, 200, $body]], 180, $this->inAsProject($c, $c['apiId']), 120)) !== true) {
            $problems[] = "from in-api's pod: {$r}";
        }
        if (($r = $this->inCalls($c, [
            'bound-token' => [$url, $this->inToken($c, $c['apiName']), 200, $body],
            'other-token' => [$url, $this->inToken($c, $c['sinkName']), 403, $body],
            'no-project-no-token' => [$url, null, 401, $body],
        ], 40, null, 120)) !== true) {
            $problems[] = $r;
        }
        if (($r = $this->inCalls($c, ['unbound-no-token' => [$url, null, 403, $body]], 40, $this->inAsProject($c, $c['sinkId']), 120)) !== true) {
            $problems[] = "from in-sink's pod: {$r}";
        }

        return $problems === [] ?: implode('; ', $problems);
    }

    /**
     * The region as an earlier release left it, region:reconcile's internal-network step under
     * RunR's own traffic, the disruption measured; the region enforces afterwards.
     */
    private function inUpgrade(array $c): bool|string
    {
        $k8s = $c['k8s'];
        $region = KnativeRegion::findOrFail($c['regionId']);
        $problems = [];

        // 1. the earlier release: no internal_auth, no ForwardAuth, no authz, no rewrite, no policy,
        //    a queue consumer without a token and pods without their internal name
        $region->update(['knative_settings' => array_diff_key((array) $region->knative_settings, [InternalNetwork::MODE_SETTING => true])]);
        $helm = app(HelmService::class);
        $helm->repoAdd(KnativeConstants::TRAEFIK_REPO_NAME, KnativeConstants::TRAEFIK_REPO_URL);
        $helm->repoUpdate();
        $result = $helm->upgradeReusingValues(KnativeConstants::TRAEFIK_RELEASE, KnativeConstants::TRAEFIK_CHART, $c['servingNs'], $region->kubeconfig,
            // an empty list: the chart renders no middlewares argument for it (a null is undone by
            // --reuse-values, which merges the release's old value back in)
            KnativeConstants::TRAEFIK_CHART_VERSION, ['ports.'.PrivateIngress::ENTRYPOINT.'.http.middlewares' => '[]'], true);
        if (! $result->success) {
            return 'removing the ForwardAuth failed: '.trim($result->error);
        }
        if (! $this->inWaitTraefikArgs($c, false)) {
            return 'the region Traefik keeps the ForwardAuth on its private entry point after the upgrade that removes it';
        }
        foreach (M::authz($c['servingNs'], 'unused') as $object) {
            in_array($object['kind'], ['ClusterRole', 'ClusterRoleBinding'], true)
                ? $k8s->deleteClusterResource($object['apiVersion'], $object['kind'], $object['metadata']['name'])
                : $k8s->deleteResource($object['apiVersion'], $object['kind'], $object['metadata']['name'], $c['servingNs']);
        }
        foreach ([M::AUTHZ, M::STRIP] as $name) {
            $k8s->deleteResource(ProjectDomainManifests::TRAEFIK_API, 'Middleware', $name, $c['servingNs']);
        }
        $k8s->deleteResource('v1', 'ConfigMap', M::CONFIG, $c['servingNs']);
        $k8s->deleteResource('v1', 'Secret', M::CONFIG, $c['servingNs']);
        $corefile = (string) ($k8s->getResourceStatus('v1', 'ConfigMap', 'coredns', 'kube-system')['data']['Corefile'] ?? '');
        $k8s->mergePatch('v1', 'ConfigMap', 'coredns', 'kube-system', ['data' => ['Corefile' => M::corefileWithout($corefile)]]);
        $model = is_array($c['model']) ? $c['model'] : null;
        foreach (array_filter([$c['api'], $c['sink'], $model['service_name'] ?? null]) as $service) {
            $k8s->mergePatch('serving.knative.dev/v1', 'Service', $service, $c['ns'], ['metadata' => [
                'labels' => [M::LABEL_MANAGED => null],
                'annotations' => array_fill_keys([M::ANNOTATION_NAME, M::ANNOTATION_ORG, M::ANNOTATION_CALLERS, M::ANNOTATION_KIND, M::ANNOTATION_ACCESS], null),
            ]]);
            $k8s->deleteResource(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', M::internalRouteName($service), $c['ns']);
        }
        $consumer = "{$c['sink']}-qc";
        $k8s->strategicMergePatch('apps/v1', 'Deployment', $consumer, $c['ns'], ['spec' => ['template' => [
            'metadata' => ['labels' => [InternalNetwork::POD_LABEL => null]],
            'spec' => ['containers' => [['name' => 'consumer', 'env' => [['name' => InternalNetwork::ENV_TOKEN, '$patch' => 'delete']]]]],
        ]]]);
        $this->netWaitDeployment($k8s, $c['ns'], $consumer, 180);
        $this->line('    as an earlier release left it: no internal_auth, authz, ForwardAuth, runr.internal or policy'.($model ? ' (the model service\'s neither)' : '').'; the queue consumer sends no token');

        // 2. callers of an earlier release send no token: answered there
        $sinkUrl = "http://{$c['sink']}.{$c['ns']}.svc.cluster.local/";
        $apiUrl = "http://{$c['api']}.{$c['ns']}.svc.cluster.local/";
        if (($r = $this->inCalls($c, ['before-sink' => [$sinkUrl, null, 200], 'before-api' => [$apiUrl, null, 200]], 30)) !== true) {
            return 'the earlier release\'s region does not answer as expected (the check would prove nothing): '.$r;
        }
        [$modelUrl, $modelBody] = $model ? $this->inModelCall($c) : [null, null];
        if ($model) {
            // before the switch the model service answers its bound project and the other one alike
            $before = $this->inCalls($c, ['before-model-bound' => [$modelUrl, null, 200, $modelBody]], 120, $this->inAsProject($c, $c['apiId']), 120);
            $beforeOther = $this->inCalls($c, ['before-model-unbound' => [$modelUrl, null, 200, $modelBody]], 30, $this->inAsProject($c, $c['sinkId']), 120);
            if ($before !== true || $beforeOther !== true) {
                return 'the earlier release\'s model service does not answer as expected: '.implode('; ', array_filter([$before === true ? null : $before, $beforeOther === true ? null : $beforeOther]));
            }
        }

        // 3. RunR's own traffic, then the step under it: the queue consumer delivering, a pod of the
        //    queue project calling its own service — no token, as workloads of an earlier release
        $as = $this->inAsProject($c, $c['sinkId']);
        $this->inCreatePod('in-probe-self', $as['ns'], $as['labels'],
            "while true; do echo \"\$(date +%s) \$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -X POST -H 'Content-Type: application/json' -d '{}' {$sinkUrl})\"; sleep 0.5; done");
        $probes = ['self' => ['in-probe-self', $as['ns']], 'xadd' => ['in-probe-xadd', self::NET_NS]];
        if ($model) {
            // in-api's pod calls its bound model service every two seconds, without a token
            $asApi = $this->inAsProject($c, $c['apiId']);
            $this->inCreatePod('in-probe-model', $asApi['ns'], $asApi['labels'],
                "while true; do echo \"\$(date +%s) \$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 -X POST -H 'Content-Type: application/json' -d '{$modelBody}' {$modelUrl})\"; sleep 2; done");
            $probes['model'] = ['in-probe-model', $asApi['ns']];
        }
        [$host, $port, $password] = $this->netRedis();
        $auth = $password ? "-a '{$password}' --no-auth-warning" : '';
        $this->netCreatePod('in-probe-xadd', self::NET_NS, 'ghcr.io/brf-tech/runr/reqs/redis:7-alpine',
            "while true; do echo \"\$(date +%s) \$(redis-cli -h {$host} -p {$port} {$auth} XADD {$c['stream']} '*' probe 1 >/dev/null && echo 200 || echo 000)\"; sleep 2; done");
        $queueBefore = $this->netConsumerStats($c);
        sleep(15);
        $started = microtime(true);
        try {
            $lines = (new InternalNetworkStep)->run(($c['group'])(), false);
        } catch (\Throwable $e) {
            $lines = ['failed: '.$e->getMessage()];
        }
        $took = round(microtime(true) - $started, 1);
        foreach ($lines as $line) {
            $this->line("    reconcile: {$line}");
        }
        sleep(20);
        $measured = $this->inStopProbes($c, $probes);
        $measured['queue'] = $this->inQueueDelivered($c, $queueBefore);
        foreach ($measured as $probe => [$calls, $failed, $codes]) {
            $this->line(sprintf('    disruption %-5s %4d calls, %d failed%s', $probe, $calls, $failed, $failed ? ' ('.implode(', ', $codes).')' : ''));
            if ($calls < 5 || $failed > 0) {
                $problems[] = "{$probe}: {$failed} of {$calls} calls failed";
            }
        }
        $this->line("    internal-network took {$took} s");

        // 4. installed and enforcing: a call between the projects without an allow-list entry is
        //    refused, a caller that is no project's pod too; the runr.internal host answers; nothing
        //    left to do
        foreach (['installed the internal network\'s authz', 'upgraded Helm release', 'gave '.($model ? 3 : 2).' Knative Service(s)', 'routed'] as $wanted) {
            if (! collect($lines)->contains(fn ($l) => str_contains($l, $wanted))) {
                $problems[] = "no \"{$wanted}\" line: ".implode(' | ', $lines);
            }
        }
        $mode = $k8s->getResourceStatus('v1', 'ConfigMap', M::CONFIG, $c['servingNs'])['data']['mode.'.$c['ns']] ?? null;
        if ($mode !== InternalNetwork::MODE_ENFORCE) {
            $problems[] = 'a region of an earlier release enforces after the upgrade; mode is '.json_encode($mode);
        }
        $internal = 'http://'.InternalNetwork::hostFor($c['apiName']).'/';
        if (($r = $this->inCalls($c, ['between-projects' => [$apiUrl, null, 403], 'internal-host' => [$internal, null, 403]], 40, $as)) !== true) {
            $problems[] = "a call between the projects: {$r}";
        }
        if (($r = $this->inCalls($c, ['no-project' => [$apiUrl, null, 401], 'own-token' => [$internal, $this->inToken($c, $c['apiName']), 200]], 40)) !== true) {
            $problems[] = $r;
        }
        if ($model) {
            // the bound model calls go on with and without a token; the unbound ones are refused now
            if (($r = $this->inCalls($c, ['model-bound-no-token' => [$modelUrl, null, 200, $modelBody]], 40, $this->inAsProject($c, $c['apiId']), 120)) !== true) {
                $problems[] = "the bound model call after the upgrade: {$r}";
            }
            if (($r = $this->inCalls($c, ['model-unbound-no-token' => [$modelUrl, null, 403, $modelBody]], 40, $as, 120)) !== true) {
                $problems[] = "an unbound model call after the upgrade: {$r}";
            }
            if (($r = $this->inCalls($c, [
                'model-bound-token' => [$modelUrl, $this->inToken($c, $c['apiName']), 200, $modelBody],
                'model-unbound-token' => [$modelUrl, $this->inToken($c, $c['sinkName']), 403, $modelBody],
            ], 40, null, 120)) !== true) {
                $problems[] = "the model calls with a token after the upgrade: {$r}";
            }
        }
        $again = array_values(array_filter((new InternalNetworkStep)->run(($c['group'])(), false), fn ($l) => ! str_starts_with($l, 'left alone')));
        if ($again !== []) {
            $problems[] = 'a second run: '.implode(' | ', $again);
        }

        return $problems === [] ?: implode('; ', $problems);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** The token of an internal name on the e2e region (what its workloads get as RUNR_INTERNAL_TOKEN) */
    private function inToken(array $c, string $name): string
    {
        return InternalNetwork::tokenFor($c['regionId'], $name);
    }

    /**
     * Calls from one pod: label → [URL, token or null, wanted status, JSON body (default {})]. A
     * call that does not answer the wanted status is repeated for up to $settle seconds (the authz
     * reads its policy every 10 s); each call may take $maxTime seconds.
     *
     * @param  array<string, array{0: string, 1: ?string, 2: int, 3?: string}>  $calls
     * @param  array{ns: string, labels: array<string, string>}|null  $as  the pod's namespace and labels (null: a pod of no project)
     */
    private function inCalls(array $c, array $calls, int $settle = 0, ?array $as = null, int $maxTime = 30): bool|string
    {
        $script = '';
        foreach ($calls as $label => $call) {
            [$url, $token, $want] = $call;
            $body = $call[3] ?? '{}';
            $header = $token !== null ? "-H '".InternalNetwork::HEADER.": {$token}'" : '';
            $script .= "i=0; while :; do s=\$(curl -s -o /dev/null -w '%{http_code}' --max-time {$maxTime} -X POST -H 'Content-Type: application/json' {$header} -d '{$body}' {$url}); "
                ."if [ \"\$s\" = \"{$want}\" ] || [ \$i -ge {$settle} ]; then break; fi; i=\$((i+2)); sleep 2; done; echo \"{$label} \$s\"; ";
        }
        $timeout = 120 + ($settle + $maxTime) * count($calls);
        [$phase, $log] = $as === null
            ? $this->netCurlPod('in-calls', self::NET_NS, $script, $timeout)
            : $this->inCurlPod('in-calls-project', $as['ns'], $as['labels'], $script, $timeout);
        $this->line('    '.str_replace("\n", ' | ', trim($log)));

        $got = [];
        foreach (explode("\n", trim($log)) as $line) {
            [$label, $code] = array_pad(explode(' ', trim($line), 2), 2, '');
            $got[$label] = $code;
        }
        $problems = [];
        foreach ($calls as $label => [, , $want]) {
            if (($got[$label] ?? null) !== (string) $want) {
                $problems[] = "{$label}: ".($got[$label] ?? 'no answer')." (want {$want})";
            }
        }
        if ($phase !== 'Succeeded') {
            $problems[] = "pod {$phase}";
        }

        return $problems === [] ?: implode(', ', $problems);
    }

    /**
     * The suite's model service: the AI suite's tiny CPU model (feature-extraction) on hf-serve,
     * online, in the e2e region, bound to in-api as EMBED. The service row of the API, or why there
     * is none.
     */
    private function inModelService(array $c): array|string
    {
        $this->aiModelStorageForCluster($c['regionId']);
        $row = ['steps' => []];
        $model = $this->aiEnsureModel(self::AI_TINY_MODEL, 'feature-extraction', $row);
        if (! $model) {
            return 'the model is not in the library: '.($row['steps']['version'] ?? '?');
        }

        $hfServe = (string) ($this->option('hf-serve-image') ?: (getenv('RUNR_E2E_HF_SERVE_IMAGE') ?: 'ghcr.io/brf-tech/runr/hf-serve:1.1.1'));
        $res = $this->api()->post('/api/model-services', [
            'ai_model_id' => $model['id'], 'name' => 'in-embed', 'mode' => 'online', 'device' => 'cpu', 'engine' => 'transformers',
            'image' => $hfServe, 'cpu' => '500m', 'memory_mb' => 1024, 'knative_region_id' => $c['regionId'], 'deploy' => true,
        ]);
        $service = $this->aiData($res);
        if (! $res->successful() || empty($service['id'])) {
            return "not created: HTTP {$res->status()} ".mb_substr((string) json_encode($res->json('errors') ?? $res->json('message')), 0, 300);
        }
        $service = $this->aiWaitService($service['id'], self::AI_WAIT_READY);
        if (($service['status'] ?? null) !== 'ready') {
            return 'status '.($service['status'] ?? '?').': '.mb_substr((string) ($service['status_message'] ?? ''), 0, 300).$this->aiServiceLogTail($service);
        }

        $bind = $this->api()->post("/api/projects/{$c['apiId']}/models", ['ai_model_service_id' => $service['id'], 'alias' => 'EMBED']);
        if (! $bind->successful()) {
            return "binding refused: HTTP {$bind->status()} ".mb_substr((string) json_encode($bind->json('errors') ?? $bind->json('message')), 0, 300);
        }

        return $service + ['binding_id' => (string) ($this->aiData($bind)['id'] ?? '')];
    }

    /** @return array{0: string, 1: string} the model service's embeddings URL on its cluster-local host, and the request's body */
    private function inModelCall(array $c): array
    {
        $model = $c['model'];

        return [
            "http://{$model['service_name']}.{$c['ns']}.svc.cluster.local/v1/embeddings",
            (string) json_encode(['input' => ['hello from the internal network'], 'model' => (string) ($model['served_name'] ?? '')], JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * A pod as RunR runs it for a project, deployed before the internal network: in the project
     * namespace, labelled with the project's id, no internal-name label, no token.
     *
     * @return array{ns: string, labels: array<string, string>}
     */
    private function inAsProject(array $c, string $projectId): array
    {
        return ['ns' => $c['ns'], 'labels' => ['runr/project-id' => $projectId, 'runr.dev/purpose' => 'e2e-internal-network']];
    }

    private function inCreatePod(string $name, string $ns, array $labels, string $script): void
    {
        $k8s = $this->getE2eKubernetesClient();
        $k8s->deleteResource('v1', 'Pod', $name, $ns);
        for ($i = 0; $i < 20 && $k8s->getResourceStatus('v1', 'Pod', $name, $ns); $i++) {
            sleep(2);
        }
        $k8s->createResource([
            'apiVersion' => 'v1', 'kind' => 'Pod',
            'metadata' => ['name' => $name, 'namespace' => $ns, 'labels' => ['app.kubernetes.io/managed-by' => 'runr'] + $labels],
            'spec' => [
                'restartPolicy' => 'Never',
                'terminationGracePeriodSeconds' => 1,
                'containers' => [[
                    'name' => 'probe', 'image' => self::NET_CURL, 'command' => ['sh', '-c', $script],
                    'resources' => ['requests' => ['cpu' => '20m', 'memory' => '16Mi'], 'limits' => ['cpu' => '200m', 'memory' => '64Mi']],
                ]],
            ],
        ], $ns);
    }

    /** @return array{0: string, 1: string} phase and log of a curl pod run to its end */
    private function inCurlPod(string $name, string $ns, array $labels, string $script, int $timeout): array
    {
        $k8s = $this->getE2eKubernetesClient();
        $this->inCreatePod($name, $ns, $labels, $script);
        $phase = $this->waitForPods($k8s, $ns, [$name], $timeout)[$name] ?? 'Timeout';
        $log = (string) $k8s->getPodLogs($name, $ns, null, 1000);
        $k8s->deleteResource('v1', 'Pod', $name, $ns);

        return [$phase, $log];
    }

    /** @return array{0: int, 1: int, 2: list<string>} messages delivered since $before, failed and pending ones, the consumer's stats */
    private function inQueueDelivered(array $c, array|string $before): array
    {
        $deadline = time() + 60;
        do {
            $stats = $this->netConsumerStats($c);
            $settled = is_array($stats) && ($stats['pending'] ?? 1) === 0;
            if (! $settled) {
                sleep(5);
            }
        } while (! $settled && time() < $deadline);
        if (! is_array($stats) || ! is_array($before)) {
            return [0, 1, [is_string($stats) ? $stats : (string) $before]];
        }

        return [
            $stats['delivered'] - $before['delivered'],
            $stats['failed'] - $before['failed'] + (int) ($stats['pending'] ?? 0),
            ["delivered {$stats['delivered']}, failed attempts {$stats['failed']}, pending ".($stats['pending'] ?? '?')],
        ];
    }

    /** Through the LoadBalancer until $want (or 90 s) */
    private function inExpectExternal(array $c, string $host, int $want, array &$problems, string $label): void
    {
        $deadline = time() + 90;
        do {
            $got = $this->netExternal($c['ip'], $host);
            if ($got === $want) {
                break;
            }
            sleep(3);
        } while (time() < $deadline);
        $this->line("    {$label}: Host {$host} → {$got}");
        if ($got !== $want) {
            $problems[] = "{$label}: {$got} (want {$want})";
        }
    }

    private function inSetMode(array $c, string $mode): void
    {
        $region = KnativeRegion::findOrFail($c['regionId']);
        $region->update(['knative_settings' => array_merge((array) $region->knative_settings, [InternalNetwork::MODE_SETTING => $mode])]);
        dispatch_sync(new ApplyInternalNetworkJob($region->fresh()));
    }

    /** JSON lines of the authz pods since $since with this decision about $caller */
    private function inAuthzLog(array $c, string $decision, string $caller, int $since): array
    {
        $lines = [];
        foreach ($c['k8s']->listResources('v1', 'Pod', $c['servingNs'], ['app.kubernetes.io/name' => M::AUTHZ])['items'] ?? [] as $pod) {
            foreach (explode("\n", (string) $c['k8s']->getPodLogs($pod['metadata']['name'], $c['servingNs'], null, 2000)) as $line) {
                if (str_contains($line, '"decision":"'.$decision.'"') && str_contains($line, '"caller":"'.$caller.'"')) {
                    $lines[] = mb_substr(trim($line), 0, 300);
                }
            }
        }

        return $lines;
    }

    private function inWaitTraefikArgs(array $c, bool $withForwardAuth): bool
    {
        $deadline = time() + 300;
        do {
            $pods = $c['k8s']->listResources('v1', 'Pod', $c['servingNs'], ['app.kubernetes.io/name' => 'traefik'])['items'] ?? [];
            $behind = array_filter($pods, function ($p) use ($withForwardAuth) {
                $args = collect($p['spec']['containers'] ?? [])->firstWhere('name', 'traefik')['args'] ?? [];
                $has = collect($args)->contains(fn ($a) => str_contains((string) $a, '.internal.http.middlewares='));

                return $has !== $withForwardAuth || isset($p['metadata']['deletionTimestamp']);
            });
            if ($pods !== [] && $behind === []) {
                return true;
            }
            sleep(3);
        } while (time() < $deadline);

        return false;
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $probes  probe → [pod, namespace]
     * @return array<string, array{0: int, 1: int, 2: list<string>}> probe → [calls, failed, failed codes]
     */
    private function inStopProbes(array $c, array $probes): array
    {
        $out = [];
        foreach ($probes as $probe => [$pod, $ns]) {
            $log = (string) $c['k8s']->getPodLogs($pod, $ns, null, 100000);
            $c['k8s']->deleteResource('v1', 'Pod', $pod, $ns);
            $calls = 0;
            $failed = [];
            foreach (explode("\n", trim($log)) as $line) {
                if (preg_match('/^\d+ (\d{3})$/', trim($line), $m)) {
                    $calls++;
                    if ($m[1] !== '200') {
                        $failed[] = $m[1];
                    }
                }
            }
            $out[$probe] = [$calls, count($failed), array_values(array_unique($failed))];
        }

        return $out;
    }

    /**
     * An environment's workload on the internal network: its own name (<workload>.<organization>),
     * its own token, and its event gateway delivering through the authz with that token.
     */
    private function inEnvironment(array $c): bool|string
    {
        $base = "/api/projects/{$c['sinkId']}/environments";
        $created = $this->api()->post($base, ['name' => 'staging', 'requires_approval' => false]);
        if ($created->status() !== 201) {
            return 'environment answered '.$created->status().': '.mb_substr($created->body(), 0, 300);
        }
        $environmentId = (string) $created->json('data.id');
        $workload = "{$c['sink']}-staging";

        try {
            $version = $this->api()->get("{$base}/production")->json('data.deployment.version.id');
            $deploy = $this->api()->post("/api/projects/{$c['sinkId']}/deploy", ['version_id' => $version, 'environment' => 'staging']);
            if ($deploy->status() !== 202) {
                return 'deploy to staging answered '.$deploy->status().': '.mb_substr($deploy->body(), 0, 300);
            }
            $deadline = time() + $this->timeout;
            $status = null;
            while (time() < $deadline) {
                $status = $this->api()->get("{$base}/staging")->json('data.deployment.status');
                if (in_array($status, ['active', 'failed'], true)) {
                    break;
                }
                sleep(5);
            }
            if ($status !== 'active') {
                return "staging not active ({$status})";
            }

            // Its identity on the internal network: the token in its own values Secret names it
            $name = InternalNetwork::nameFor($workload, null);
            $secret = $c['k8s']->getResourceStatus('v1', 'Secret', "{$workload}-secret-env", $c['ns']);
            $token = base64_decode((string) ($secret['data'][InternalNetwork::ENV_TOKEN] ?? ''));
            $claims = $token !== '' ? InternalNetwork::verify($c['regionId'], $token) : null;
            if (($claims['sub'] ?? null) !== $name) {
                return "the token of {$workload} names ".json_encode($claims['sub'] ?? null).", want {$name}";
            }

            // Its event gateway reads <stream>-staging and calls <workload> through the authz
            $stats = function () use ($c, $environmentId): array|string {
                $pods = $c['k8s']->listResources('v1', 'Pod', $c['ns'], ['runr/environment-id' => $environmentId, 'runr/component' => 'queue-consumer'])['items'] ?? [];
                $ip = collect($pods)->first(fn ($p) => ($p['status']['phase'] ?? '') === 'Running')['status']['podIP'] ?? null;
                if (! $ip) {
                    return 'no running queue consumer of staging';
                }
                [$phase, $log] = $this->netCurlPod('in-env-stats', self::NET_NS, "curl -s --max-time 10 http://{$ip}:8081/stats", 60);
                $decoded = json_decode(trim($log), true);

                return is_array($decoded) && isset($decoded['delivered']) ? $decoded : "consumer stats {$phase}: {$log}";
            };
            $deadline = time() + 180;
            do {
                $before = $stats();
                if (is_array($before)) {
                    break;
                }
                sleep(5);
            } while (time() < $deadline);
            if (! is_array($before)) {
                return $before;
            }

            [$host, $port, $password] = $this->netRedis();
            $auth = $password ? "-a '{$password}' --no-auth-warning" : '';
            $add = $this->recRunPod($c['k8s'], self::NET_NS, 'in-env-xadd', ['containers' => [[
                'name' => 'redis', 'image' => 'ghcr.io/brf-tech/runr/reqs/redis:7-alpine',
                'command' => ['sh', '-c', "redis-cli -h {$host} -p {$port} {$auth} XADD {$c['stream']}-staging '*' e2e environment"],
            ]]]);
            if ($add !== true) {
                return $add;
            }
            $deadline = time() + 180;
            $after = $before;
            do {
                sleep(5);
                $after = $stats();
                if (is_array($after) && $after['delivered'] > $before['delivered']) {
                    $this->line('    staging consumer stats: '.json_encode($after));

                    return $after['failed'] === $before['failed'] ?: 'delivered after '.($after['failed'] - $before['failed']).' failed attempt(s) (authz?): '.json_encode($after);
                }
            } while (time() < $deadline);

            return 'not delivered to staging within 180 s: '.json_encode($after);
        } finally {
            $this->api()->delete("{$base}/staging");
        }
    }

    private function inCleanup(array $c): void
    {
        try {
            $network = app(InternalNetworkService::class);
            $api = Project::find($c['apiId']);
            if ($api) {
                foreach ($network->callers($api) as $caller) {
                    $network->removeCaller($api, $caller);
                }
                if ($api->accessMode() !== Project::ACCESS_PUBLIC) {
                    $network->setAccess($api, Project::ACCESS_PUBLIC, null);
                }
            }
            Setting::set(InternalNetwork::CROSS_TENANT_SETTING, 'true');
            // the model service, once its binding is gone (a bound service is not deleted)
            if (is_array($c['model'] ?? null)) {
                if (($c['model']['binding_id'] ?? '') !== '') {
                    $this->api()->delete("/api/projects/{$c['apiId']}/models/{$c['model']['binding_id']}");
                }
                $this->api()->delete("/api/model-services/{$c['model']['id']}");
            }
            $c['k8s']->deleteClusterResource('v1', 'Namespace', self::NET_NS);
        } catch (\Throwable $e) {
            $this->warn('  internal network suite cleanup: '.$e->getMessage());
        }
    }
}
