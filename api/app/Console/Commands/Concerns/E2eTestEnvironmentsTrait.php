<?php

namespace App\Console\Commands\Concerns;

use App\Models\DeploymentSession;
use App\Models\EnvironmentSecret;
use App\Models\KnativeRegion;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\GatewayService;
use App\Services\Metering\UsageAggregator;
use App\Services\Secrets\SecretFingerprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * E2E of project environments and of a rollback that restores the config (test:e2e
 * --suite=environments, runtime/lib/test-environments.sh; docs/environments.md,
 * docs/versioning.md#rollback), on one Python HTTP function in the e2e region:
 *  - env-create        POST /projects/{p}/environments: staging, GREETING overridden, no approval;
 *                      the pipeline lists staging before production
 *  - env-deploy        the production version deployed to staging: its own Knative Service
 *                      <slug>-staging answers with RUNR_ENVIRONMENT=staging and the overridden
 *                      GREETING, production keeps answering with its own
 *  - env-labels        staging's pods carry runr/environment-id, never runr/project-id (the binding
 *                      NetworkPolicies admit pods by those labels)
 *  - env-gateway-route a route of the region's gateway to staging (docs/environments.md#gateway-routes)
 *                      answers with staging's workload; a second route to staging and a second one
 *                      to the project's own workload are refused (422), and so is a second row of
 *                      the own workload written past the API's check: the partial unique index
 *                      gateway_routes_project_production_unique (central migration
 *                      2026_10_23_100001; a NULL environment_id never conflicted). The routes are
 *                      removed afterwards
 *  - env-secrets       a project secret staging inherits and a secret of staging's own (pass-through:
 *                      straight into <slug>-staging-secret-env, RunR's row holds a fingerprint only);
 *                      after a deploy of staging both values are in its Secret and its function sees
 *                      them, production's Secret has only the project's
 *  - env-metering      staging's pods are metered through the region agent: a session of staging's
 *                      deployment (scale to zero) and today's usage record of it with the
 *                      environment's id and CPU / memory time above zero
 *  - cron-metering     a schedule-triggered project: a run its CronJob starts is a session of
 *                      entry point "schedule" (the region agent), and today's usage record of the
 *                      deployment counts its time
 *  - env-promote       a new version on staging, promoted to production (approval turned off for
 *                      the suite): production runs the same version — not built again — with its
 *                      own config
 *  - env-rollback      production's GREETING changed and deployed, then a rollback to the earlier
 *                      deployment: the earlier GREETING comes back with the code; keeping the
 *                      current config keeps the new one
 *  - env-delete        DELETE the environment: its Knative Service leaves the region, production
 *                      still answers (with the config env-rollback left it)
 */
trait E2eTestEnvironmentsTrait
{
    /** e2eConnectAgent() connected the region agent through the e2e gateway */
    private bool $e2eAgentConnected = false;

    private const ENV_PYTHON = <<<'PY'
import hashlib, os

def handler(event, context):
    def digest(name):
        value = os.environ.get(name)
        return hashlib.sha256(value.encode()).hexdigest() if value is not None else None
    return {"statusCode": 200, "body": {"environment": os.environ.get("RUNR_ENVIRONMENT", "production"), "greeting": os.environ.get("GREETING", ""),
                                        "inherited": digest("ENV_E2E_INHERITED"), "own": digest("ENV_E2E_OWN")}}
PY;

    private function runEnvironmentsSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Environments (staging next to production, promotion, rollback with the config) ──');

        $tests = [$this->makeTestEntry('env-function', [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'http',
            'code' => self::ENV_PYTHON,
            'env_vars' => ['GREETING' => 'from production'],
        ], $regionId)];
        $this->runTestsSequentially($tests);
        $test = $tests[0];
        if ($test['steps']['ready'] !== true) {
            return $tests;
        }
        $test['steps']['verify'] = $this->envWaitBody($test['slug'], 'from production', 180);
        $tests[0] = $test;
        if ($test['steps']['verify'] !== true) {
            return $tests;
        }

        $tests[] = $this->envCheck('env-create', fn () => $this->envCreate($test));
        $tests[] = $this->envCheck('env-deploy', fn () => $this->envDeploy($test));
        $tests[] = $this->envCheck('env-labels', fn () => $this->envLabels($test));
        $tests[] = $this->envCheck('env-gateway-route', fn () => $this->envGatewayRoute($test));
        $tests[] = $this->envCheck('env-secrets', fn () => $this->envSecrets($test));
        $tests[] = $this->envCheck('env-metering', fn () => $this->envMetering($test));
        $tests[] = $this->envCheck('cron-metering', fn () => $this->cronMetering($regionId));
        $tests[] = $this->envCheck('env-promote', fn () => $this->envPromote($test));
        $tests[] = $this->envCheck('env-rollback', fn () => $this->envRollback($test));
        $tests[] = $this->envCheck('env-delete', fn () => $this->envDelete($test));

        return $tests;
    }

    private function envCreate(array $test): bool|string
    {
        $created = $this->api()->post("/api/projects/{$test['projectId']}/environments", [
            'name' => 'staging',
            'requires_approval' => false,
            'overrides' => ['env_vars' => ['GREETING' => 'from staging']],
        ]);
        if ($created->status() !== 201 || $created->json('data.workload_name') !== $test['slug'].'-staging') {
            return 'create answered '.$created->status().': '.mb_substr($created->body(), 0, 300);
        }

        $names = array_column((array) $this->api()->get("/api/projects/{$test['projectId']}/environments")->json('data'), 'name');

        return $names === ['staging', 'production'] ? true : 'pipeline: '.implode(' → ', $names);
    }

    private function envDeploy(array $test): bool|string
    {
        $deploy = $this->api()->post("/api/projects/{$test['projectId']}/deploy", ['version_id' => $test['versionId'], 'environment' => 'staging']);
        if ($deploy->status() !== 202 || $deploy->json('environment') !== 'staging') {
            return 'deploy to staging answered '.$deploy->status().': '.mb_substr($deploy->body(), 0, 300);
        }
        if (($ready = $this->envWaitActive($test, 'staging')) !== true) {
            return $ready;
        }

        $staging = $this->envWaitBody($test['slug'].'-staging', 'from staging', 180);
        if ($staging !== true) {
            return 'staging: '.$staging;
        }
        $production = $this->envWaitBody($test['slug'], 'from production', 60);

        return $production === true ? true : 'production after the staging deploy: '.$production;
    }

    private function envLabels(array $test): bool|string
    {
        $environment = $this->api()->get("/api/projects/{$test['projectId']}/environments/staging")->json('data');
        $pods = $this->e2eK8s->listResources('v1', 'Pod', $this->e2eNamespace, ['runr/environment-id' => (string) ($environment['id'] ?? '')])['items'] ?? [];
        if ($pods === []) {
            return 'no pod carries runr/environment-id='.($environment['id'] ?? '?');
        }
        foreach ($pods as $pod) {
            if (isset($pod['metadata']['labels']['runr/project-id'])) {
                return 'pod '.$pod['metadata']['name'].' of staging carries runr/project-id';
            }
        }

        return true;
    }

    private function envGatewayRoute(array $test): bool|string
    {
        $admin = User::query()->where('username', config('app.admin.username'))->first() ?? User::query()->first();
        $gateway = app(GatewayService::class)->findOrCreateForRegion($admin, $this->getE2eRegion());
        // the gateway's host for apiCurl() (E2eTestApiProductTrait)
        $this->apiGateway = (array) $this->api()->get("/api/gateways/{$gateway->id}")->json('data');
        $routes = [];

        try {
            $staging = $this->api()->post("/api/gateways/{$gateway->id}/routes", ['project_id' => $test['projectId'], 'environment' => 'staging', 'path' => '/e2e-env-staging']);
            if ($staging->status() !== 201 || $staging->json('data.environment') !== 'staging') {
                return 'the route to staging answered '.$staging->status().': '.mb_substr($staging->body(), 0, 300);
            }
            $routes[] = (string) $staging->json('data.id');
            $through = $this->apiWaitGateway([], ['200'], 180, 'from staging', '/e2e-env-staging');
            if ($through !== true) {
                return 'staging through the gateway: '.$through;
            }

            $again = $this->api()->post("/api/gateways/{$gateway->id}/routes", ['project_id' => $test['projectId'], 'environment' => 'staging', 'path' => '/e2e-env-staging-2']);
            if ($again->status() !== 422) {
                return 'a second route to staging answered '.$again->status().': '.mb_substr($again->body(), 0, 300);
            }
            $own = $this->api()->post("/api/gateways/{$gateway->id}/routes", ['project_id' => $test['projectId'], 'path' => '/e2e-env-own']);
            if ($own->status() !== 201 || $own->json('data.environment') !== null) {
                return 'the route to the own workload answered '.$own->status().': '.mb_substr($own->body(), 0, 300);
            }
            $routes[] = (string) $own->json('data.id');
            $ownAgain = $this->api()->post("/api/gateways/{$gateway->id}/routes", ['project_id' => $test['projectId'], 'path' => '/e2e-env-own-2']);
            if ($ownAgain->status() !== 422) {
                return 'a second route to the own workload answered '.$ownAgain->status().': '.mb_substr($ownAgain->body(), 0, 300);
            }

            // The database itself, past the API's check (a concurrent request's row): in a
            // transaction of its own, so the refused insert leaves this connection usable
            try {
                DB::transaction(fn () => DB::table('gateway_routes')->insert([
                    'id' => (string) Str::ulid(), 'gateway_id' => $gateway->id, 'project_id' => $test['projectId'],
                    'path' => '/e2e-env-own-db', 'created_at' => now(), 'updated_at' => now(),
                ]));
                DB::table('gateway_routes')->where('path', '/e2e-env-own-db')->where('gateway_id', $gateway->id)->delete();

                return 'the database took a second route to the project\'s own workload';
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'gateway_routes_project_production_unique')) {
                    return 'the second row was refused by another index: '.mb_substr($e->getMessage(), 0, 300);
                }
            }

            return true;
        } finally {
            foreach ($routes as $id) {
                $this->api()->delete("/api/gateways/{$gateway->id}/routes/{$id}");
            }
        }
    }

    private function envSecrets(array $test): bool|string
    {
        $inherited = bin2hex(random_bytes(16));
        $own = bin2hex(random_bytes(16));

        $put = $this->api()->put("/api/projects/{$test['projectId']}/secrets/ENV_E2E_INHERITED", ['value' => $inherited]);
        if (! in_array($put->status(), [200, 201], true)) {
            return 'project secret answered '.$put->status().': '.$put->json('message');
        }
        $put = $this->api()->put("/api/projects/{$test['projectId']}/environments/staging/secrets/ENV_E2E_OWN", ['value' => $own]);
        if ($put->status() !== 201 || $put->json('data.stored_in') !== 'region') {
            return 'staging secret answered '.$put->status().': '.$put->json('message');
        }

        // Pass-through: in staging's own Secret at once, RunR's row without a value
        $workload = $test['slug'].'-staging';
        $secret = $this->e2eK8s->getResourceStatus('v1', 'Secret', "{$workload}-secret-env", $this->e2eNamespace);
        if (base64_decode((string) ($secret['data']['ENV_E2E_OWN'] ?? '')) !== $own) {
            return "{$workload}-secret-env does not hold staging's own value after the PUT";
        }
        $row = EnvironmentSecret::query()->where('project_id', $test['projectId'])->where('name', 'ENV_E2E_OWN')->first();
        if (! $row || ! SecretFingerprint::matches($own, $row->fingerprint) || Schema::hasColumn('environment_secrets', 'value')) {
            return 'RunR\'s environment secret row is not metadata with a matching fingerprint';
        }

        $live = $this->envLive($test, 'staging');
        $deploy = $this->api()->post("/api/projects/{$test['projectId']}/deploy", ['version_id' => $live['version']['id'] ?? $test['versionId'], 'environment' => 'staging']);
        if ($deploy->status() !== 202) {
            return 'deploy to staging answered '.$deploy->status().': '.mb_substr($deploy->body(), 0, 300);
        }
        $deadline = time() + $this->timeout;
        $seen = 'no answer';
        while (time() < $deadline) {
            $res = $this->curlFunction($workload, '/', false);
            if (is_array($res)) {
                $body = json_decode((string) $res[2], true);
                $body = is_array($body['body'] ?? null) ? $body['body'] : $body;
                if ($res[0] === '200' && ($body['inherited'] ?? null) === hash('sha256', $inherited) && ($body['own'] ?? null) === hash('sha256', $own)) {
                    $seen = true;
                    break;
                }
                $seen = "HTTP {$res[0]} inherited ".(($body['inherited'] ?? null) === hash('sha256', $inherited) ? 'ok' : 'missing').', own '.(($body['own'] ?? null) === hash('sha256', $own) ? 'ok' : 'missing');
            } else {
                $seen = $res;
            }
            sleep(5);
        }
        if ($seen !== true) {
            return "staging does not see both values: {$seen}";
        }

        $secret = $this->e2eK8s->getResourceStatus('v1', 'Secret', "{$workload}-secret-env", $this->e2eNamespace);
        if (base64_decode((string) ($secret['data']['ENV_E2E_INHERITED'] ?? '')) !== $inherited) {
            return "{$workload}-secret-env does not hold the inherited value";
        }
        $production = $this->e2eK8s->getResourceStatus('v1', 'Secret', "{$test['slug']}-secret-env", $this->e2eNamespace);

        return array_key_exists('ENV_E2E_OWN', (array) ($production['data'] ?? [])) ? 'staging\'s own value reached production\'s Secret' : true;
    }

    private function envMetering(array $test): bool|string
    {
        if (($connected = $this->e2eConnectAgent((string) $this->e2eRegion->id)) !== true) {
            return $connected;
        }
        $environment = (array) $this->api()->get("/api/projects/{$test['projectId']}/environments/staging")->json('data');
        $deploymentId = (string) ($environment['deployment']['id'] ?? '');
        if ($deploymentId === '') {
            return 'staging has no live deployment';
        }
        // a request, so a pod of staging runs (and its session is open) now
        $this->curlFunction($test['slug'].'-staging', '/', false);

        return $this->e2eMetered($test['projectId'], $deploymentId, ['deployment_id' => $deploymentId], function ($records) use ($environment) {
            $record = $records->first();

            return match (true) {
                (string) $record->environment_id !== (string) ($environment['id'] ?? '') => 'the usage record names environment '.json_encode($record->environment_id),
                $record->scale_mode !== 'scale_to_zero' => 'scale mode '.$record->scale_mode,
                default => true,
            };
        });
    }

    /**
     * A run of a schedule-triggered project's CronJob, started the way the CronJob controller
     * starts one (Job <slug>-trigger-<minutes>, the CronJob's job template), is metered.
     */
    private function cronMetering(string $regionId): bool|string
    {
        if (($connected = $this->e2eConnectAgent($regionId)) !== true) {
            return $connected;
        }
        $tests = [$this->makeTestEntry('cron-meter', [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'schedule',
            'code' => self::ENV_PYTHON,
        ], $regionId)];
        $this->runTestsSequentially($tests);
        $test = $tests[0];
        if ($test['steps']['ready'] !== true) {
            return 'the schedule project is not ready: '.json_encode($test['steps']);
        }

        try {
            $deploymentId = (string) $this->api()->get("/api/projects/{$test['projectId']}/environments/production")->json('data.deployment.id');
            $k8s = $this->e2eK8s;
            $cron = $k8s->getResourceStatus('batch/v1', 'CronJob', "{$test['slug']}-trigger", $this->e2eNamespace);
            if (! $cron) {
                return "CronJob {$test['slug']}-trigger not found";
            }
            $job = "{$test['slug']}-trigger-".intdiv(time(), 60);
            // the job template as JSON objects (an empty map decodes to a list: jobPodSpecFromCronJob)
            $labels = (array) ($cron['spec']['jobTemplate']['metadata']['labels'] ?? []);
            $podLabels = (array) ($cron['spec']['jobTemplate']['spec']['template']['metadata']['labels'] ?? []);
            $applied = $k8s->applyResource([
                'apiVersion' => 'batch/v1',
                'kind' => 'Job',
                'metadata' => [
                    'name' => $job,
                    'labels' => $labels ?: new \stdClass,
                    'annotations' => ['cronjob.kubernetes.io/instantiate' => 'manual'],
                    'ownerReferences' => [[
                        'apiVersion' => 'batch/v1', 'kind' => 'CronJob', 'name' => $cron['metadata']['name'], 'uid' => $cron['metadata']['uid'],
                    ]],
                ],
                'spec' => [
                    'backoffLimit' => 0,
                    'template' => [
                        'metadata' => ['labels' => $podLabels ?: new \stdClass],
                        'spec' => self::jobPodSpecFromCronJob($cron),
                    ],
                ],
            ], $this->e2eNamespace);
            if (! $applied) {
                return "Job {$job} of the CronJob was not created";
            }

            return $this->e2eMetered($test['projectId'], $deploymentId, ['deployment_id' => $deploymentId, 'entry_point' => 'schedule'], function ($records) {
                return $records->contains(fn ($r) => $r->entry_point === 'schedule' && (int) $r->session_count > 0)
                    ? true
                    : 'no schedule record with a session: '.$records->map(fn ($r) => $r->entry_point.'/'.$r->session_count)->implode(', ');
            });
        } finally {
            $this->api()->delete("/api/projects/{$test['projectId']}");
        }
    }

    /**
     * The region agent reports sessions over RunR's websocket, which a gateway serves with the /ws
     * prefix stripped (the Helm chart's runr-gateway, Caddy, to Reverb). The e2e's compose stack has
     * no gateway and its APP_URL (localhost) is nothing the pods reach: an nginx in the cluster
     * forwards /ws/ to the stack's Reverb and the rest to its API, at their addresses on the Kind
     * network; the region's agent_ws_url names it and the agent is redeployed. Once per run.
     */
    private function e2eConnectAgent(string $regionId): bool|string
    {
        if ($this->e2eAgentConnected) {
            return true;
        }
        $k8s = $this->getE2eKubernetesClient();
        $nodeIp = null;
        foreach ($k8s->listClusterResources('v1', 'Node')['items'] ?? [] as $node) {
            foreach ($node['status']['addresses'] ?? [] as $address) {
                if (($address['type'] ?? '') === 'InternalIP' && filter_var($address['address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $nodeIp = (string) $address['address'];
                    break 2;
                }
            }
        }
        if ($nodeIp === null) {
            return 'no node address';
        }
        // Docker networks are /16: the stack's addresses that share the node's first two octets
        $prefix = implode('.', array_slice(explode('.', $nodeIp), 0, 2)).'.';
        $onKind = fn (array $ips) => collect($ips)->first(fn ($ip) => is_string($ip) && $ip !== $nodeIp && str_starts_with($ip, $prefix));
        $apiIp = $onKind(collect(net_get_interfaces() ?: [])->flatMap(fn ($i) => array_column($i['unicast'] ?? [], 'address'))->all());
        $reverbIp = $onKind(array_merge(gethostbynamel('reverb') ?: [], gethostbynamel('runr-reverb') ?: []));
        if (! $apiIp || ! $reverbIp) {
            return 'the stack is not on the Kind network (api '.json_encode($apiIp).', reverb '.json_encode($reverbIp).')';
        }

        $ns = 'runr-e2e-agent-gw';
        $conf = <<<NGINX
events {}
http {
  map \$http_upgrade \$connection_upgrade { default upgrade; '' close; }
  server {
    listen 80;
    location /ws/ {
      proxy_pass http://{$reverbIp}:8080/;
      proxy_http_version 1.1;
      proxy_set_header Upgrade \$http_upgrade;
      proxy_set_header Connection \$connection_upgrade;
      proxy_set_header Host \$host;
      proxy_read_timeout 3600s;
      proxy_send_timeout 3600s;
    }
    location / {
      proxy_pass http://{$apiIp}:80;
      proxy_set_header Host \$host;
    }
  }
}
NGINX;
        $labels = ['app' => 'e2e-agent-gw'];
        $k8s->applyClusterResource(['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => $ns]]);
        $k8s->applyResource(['apiVersion' => 'v1', 'kind' => 'ConfigMap', 'metadata' => ['name' => 'e2e-agent-gw', 'namespace' => $ns], 'data' => ['nginx.conf' => $conf]], $ns);
        $k8s->applyResource([
            'apiVersion' => 'apps/v1', 'kind' => 'Deployment',
            'metadata' => ['name' => 'e2e-agent-gw', 'namespace' => $ns, 'labels' => $labels],
            'spec' => [
                'replicas' => 1,
                'selector' => ['matchLabels' => $labels],
                'template' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => [
                        'containers' => [[
                            'name' => 'nginx', 'image' => 'ghcr.io/brf-tech/runr/reqs/nginx:1.27-alpine',
                            'ports' => [['containerPort' => 80]],
                            'volumeMounts' => [['name' => 'conf', 'mountPath' => '/etc/nginx/nginx.conf', 'subPath' => 'nginx.conf']],
                        ]],
                        'volumes' => [['name' => 'conf', 'configMap' => ['name' => 'e2e-agent-gw']]],
                    ],
                ],
            ],
        ], $ns);
        $k8s->applyResource(['apiVersion' => 'v1', 'kind' => 'Service', 'metadata' => ['name' => 'e2e-agent-gw', 'namespace' => $ns],
            'spec' => ['selector' => $labels, 'ports' => [['port' => 80, 'targetPort' => 80]]]], $ns);
        if (! $this->netWaitDeployment($k8s, $ns, 'e2e-agent-gw', 180)) {
            return 'the agent gateway (nginx) did not become ready';
        }

        $region = KnativeRegion::query()->findOrFail($regionId);
        $region->forceFill(['knative_settings' => array_merge((array) ($region->knative_settings ?? []), ['agent_ws_url' => "ws://e2e-agent-gw.{$ns}.svc.cluster.local"])])->saveQuietly();
        if ($this->callSilently('agent:redeploy', ['regionId' => $regionId]) !== 0) {
            return 'agent:redeploy failed';
        }

        $deadline = time() + 180;
        $log = '';
        while (time() < $deadline) {
            $log = $this->agentLog();
            if (str_contains($log, 'WS connected to ws://e2e-agent-gw.')) {
                $this->line("    region agent connected through ws://e2e-agent-gw.{$ns}.svc.cluster.local (reverb {$reverbIp}, api {$apiIp})");
                $this->e2eAgentConnected = true;

                return true;
            }
            sleep(5);
        }

        return 'the region agent did not connect through the e2e gateway: '.mb_substr(implode(' | ', array_slice(array_filter(explode("\n", $log)), -3)), 0, 400);
    }

    /**
     * Metering through the region agent (docs/monitoring.md#metering): the agent reports a session
     * of the project matching $where, and today's usage records of $deploymentId — aggregated now,
     * as the scheduler does every five minutes — count CPU and memory time above zero.
     *
     * @param  array<string, string>  $where  deployment_sessions columns
     * @param  callable(Collection<int, UsageRecord>): (bool|string)|null  $check
     */
    private function e2eMetered(string $projectId, string $deploymentId, array $where, ?callable $check = null, int $timeout = 300): bool|string
    {
        $deadline = time() + $timeout;
        $session = null;
        while (time() < $deadline) {
            $session = DeploymentSession::query()->where('project_id', $projectId)->where($where)->orderByDesc('started_at')->first();
            if ($session) {
                break;
            }
            sleep(5);
        }
        if (! $session) {
            return 'the region agent reported no session of '.json_encode($where)." within {$timeout}s";
        }
        // a little pod time to count
        sleep(10);

        app(UsageAggregator::class)->aggregate(now()->toDateString());
        $records = UsageRecord::query()->where('project_id', $projectId)->where('deployment_id', $deploymentId)
            ->whereDate('record_date', now()->toDateString())->get();
        if ($records->isEmpty()) {
            return "no usage record of deployment {$deploymentId} today (session {$session->id}, entry point {$session->entry_point})";
        }
        $cpu = (int) $records->sum('total_cpu_mc_ms');
        $memory = (int) $records->sum('total_memory_mib_ms');
        $this->line("    metered: {$records->count()} record(s), {$cpu} mc·ms CPU, {$memory} MiB·ms memory, sessions ".(int) $records->sum('session_count'));
        if ($cpu <= 0 || $memory <= 0) {
            return "the usage of deployment {$deploymentId} is zero: ".$records->map(fn ($r) => "{$r->scale_mode}/{$r->entry_point}: cpu {$r->total_cpu_mc_ms}, memory {$r->total_memory_mib_ms}, sessions {$r->session_count}")->implode('; ');
        }

        return $check ? $check($records) : true;
    }

    private function envPromote(array $test): bool|string
    {
        $approval = $this->api()->patch("/api/projects/{$test['projectId']}/environments/production", ['requires_approval' => false]);
        if (! $approval->successful()) {
            return 'production without approval answered '.$approval->status();
        }

        // A new version on staging first
        $saved = $this->api()->post("/api/projects/{$test['projectId']}/code", ['code' => self::ENV_PYTHON."\n# v2\n", 'message' => 'e2e: v2']);
        $versionId = $saved->json('version.id');
        if (! $versionId) {
            return 'code save answered '.$saved->status().': '.mb_substr($saved->body(), 0, 200);
        }
        $this->api()->post("/api/projects/{$test['projectId']}/deploy", ['version_id' => $versionId, 'environment' => 'staging']);
        if (($ready = $this->envWaitActive($test, 'staging', $versionId)) !== true) {
            return 'staging v2: '.$ready;
        }

        $preview = $this->api()->get("/api/projects/{$test['projectId']}/environments/staging/promote");
        if ($preview->json('data.code.to.id') !== $versionId || $preview->json('data.target.environment') !== 'production') {
            return 'preview: '.mb_substr($preview->body(), 0, 300);
        }

        $promoted = $this->api()->post("/api/projects/{$test['projectId']}/environments/staging/promote", ['message' => 'e2e']);
        if ($promoted->status() !== 202 || $promoted->json('data.status') !== 'deploying') {
            return 'promote answered '.$promoted->status().': '.mb_substr($promoted->body(), 0, 300);
        }
        if (($ready = $this->envWaitActive($test, 'production', $versionId)) !== true) {
            return 'production after the promotion: '.$ready;
        }

        // The same version, production's own config
        return $this->envWaitBody($test['slug'], 'from production', 120);
    }

    private function envRollback(array $test): bool|string
    {
        $before = $this->envLive($test, 'production');
        $update = $this->api()->put("/api/projects/{$test['projectId']}", ['config' => ['env_vars' => ['GREETING' => 'changed']]]);
        if (! $update->successful()) {
            return 'config change answered '.$update->status().': '.mb_substr($update->body(), 0, 200);
        }
        $latest = $this->api()->get("/api/projects/{$test['projectId']}")->json('data.latest_version.id');
        $this->api()->post("/api/projects/{$test['projectId']}/deploy", ['version_id' => $latest]);
        if (($ready = $this->envWaitActive($test, 'production', $latest)) !== true) {
            return 'deploy of the change: '.$ready;
        }
        if (($changed = $this->envWaitBody($test['slug'], 'changed', 120)) !== true) {
            return 'after the change: '.$changed;
        }

        // Back to the earlier deployment: its code and its config
        $rollback = $this->api()->post("/api/projects/{$test['projectId']}/deployments/{$before['id']}/rollback");
        if ($rollback->status() !== 202 || $rollback->json('config_source') !== 'restored') {
            return 'rollback answered '.$rollback->status().': '.mb_substr($rollback->body(), 0, 300);
        }
        if (($ready = $this->envWaitActive($test, 'production', $before['version']['id'] ?? null)) !== true) {
            return 'rollback: '.$ready;
        }
        if (($restored = $this->envWaitBody($test['slug'], 'from production', 120)) !== true) {
            return 'after the rollback: '.$restored;
        }

        // Keeping the current config: the earlier code with GREETING=changed
        $this->api()->post("/api/projects/{$test['projectId']}/deployments/{$before['id']}/rollback", ['keep_current_config' => true]);

        return $this->envWaitBody($test['slug'], 'changed', 180);
    }

    private function envDelete(array $test): bool|string
    {
        $deleted = $this->api()->delete("/api/projects/{$test['projectId']}/environments/staging");
        if (! $deleted->successful()) {
            return 'delete answered '.$deleted->status().': '.mb_substr($deleted->body(), 0, 200);
        }

        $deadline = time() + 120;
        while (time() < $deadline) {
            if (! $this->e2eK8s->getResourceStatus('serving.knative.dev/v1', 'Service', $test['slug'].'-staging', $this->e2eNamespace)) {
                // production answers as it did (env-rollback left it with GREETING=changed)
                return $this->envWaitBody($test['slug'], '"environment": "production"', 60);
            }
            sleep(5);
        }

        return 'the Knative Service '.$test['slug'].'-staging is still there';
    }

    /** The live deployment of an environment (GET …/environments/{env}): id, status, version. */
    private function envLive(array $test, string $environment): array
    {
        return (array) $this->api()->get("/api/projects/{$test['projectId']}/environments/{$environment}")->json('data.deployment');
    }

    private function envWaitActive(array $test, string $environment, ?string $versionId = null): bool|string
    {
        $deadline = time() + $this->timeout;
        $last = 'nothing live';
        while (time() < $deadline) {
            $live = $this->envLive($test, $environment);
            if (($live['status'] ?? null) === 'active' && ($versionId === null || ($live['version']['id'] ?? null) === $versionId)) {
                return true;
            }
            if (($live['status'] ?? null) === 'failed') {
                return "{$environment} failed";
            }
            $last = ($live['status'] ?? 'none').' v'.($live['version']['number'] ?? '?');
            sleep(5);
        }

        return "{$environment} not active within {$this->timeout}s (last: {$last})";
    }

    private function envWaitBody(string $host, string $needle, int $timeout): bool|string
    {
        $deadline = time() + $timeout;
        $last = 'no answer';
        while (time() < $deadline) {
            $res = $this->curlFunction($host, '/', false);
            if (is_array($res)) {
                [$status, , $body] = $res;
                if ($status === '200' && str_contains((string) $body, $needle)) {
                    return true;
                }
                $last = "HTTP {$status} ".mb_substr(trim((string) $body), 0, 160);
            } else {
                $last = $res;
            }
            sleep(5);
        }

        return "want {$needle} within {$timeout}s, last: {$last}";
    }

    private function envCheck(string $name, callable $check): array
    {
        $entry = $this->makeTestEntry($name, ['service_type' => 'environments', 'trigger_type' => 'http'], (string) ($this->e2eRegion->id ?? ''));
        $entry['startTime'] = microtime(true);
        try {
            $result = $check();
        } catch (\Throwable $e) {
            $result = $e::class.': '.$e->getMessage();
        }
        $entry['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $result === true ? true : (string) $result];
        $entry = $this->finishTest($entry);

        $state = $result === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line("  {$state} {$name}".($result === true ? '' : ': '.mb_substr((string) $result, 0, 400)));

        return $entry;
    }
}
