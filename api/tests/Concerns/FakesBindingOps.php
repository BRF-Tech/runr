<?php

namespace Tests\Concerns;

use App\Models\Binding;
use App\Models\BindingRegionService;
use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Bindings\BindingManager;
use App\Services\Bindings\Ops\BindingOpsRunner;
use App\Services\Bindings\Ops\OpsResult;
use App\Services\Bindings\Ops\OpsTask;
use App\Services\HelmResult;
use App\Services\HelmService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Bindings without a cluster:
 *
 *  - in-cluster operations (BindingOpsRunner) are recorded and answered: ok + "RUNR_OK" by
 *    default, Garage admin calls with a RUNR_RESULT built from the call list, usage tasks with
 *    USAGE lines from $usageBytes, the KV probe with ACL_SUPPORTED $aclSupported and REDIS_VERSION $redisVersion;
 *    $opsResponses[purpose] overrides (OpsResult or fn (OpsTask): OpsResult);
 *  - the Kubernetes API (Http::fake): every request recorded; pods Running + Ready, CNPG served,
 *    CNPG Clusters ready ($readyInstances), $k8sResources[path] for other GETs (null = 404),
 *    every write answered 200 (unless the path contains $failWritesTo);
 *  - helm: commands recorded, $helmFails makes upgrade --install fail.
 *
 * Call setUpBindingFakes() from setUp().
 */
trait FakesBindingOps
{
    protected static string $k8sServer = 'https://region.test:6443';

    /** @var list<OpsTask> */
    protected array $opsTasks = [];

    /** @var array<string, OpsResult|callable> */
    protected array $opsResponses = [];

    /** @var list<array{method: string, path: string, query: string, body: string}> */
    protected array $k8sCalls = [];

    /** @var array<string, array|null> GET path → body (null = 404) */
    protected array $k8sResources = [];

    protected ?string $failWritesTo = null;

    /** @var list<list<string>> */
    protected array $helmCommands = [];

    protected bool $helmFails = false;

    /** @var array<string, int> binding id / database name / Garage bucket id → bytes */
    protected array $usageBytes = [];

    protected bool $aclSupported = true;

    /** REDIS_VERSION the KV probe reports */
    protected string $redisVersion = '7.4.1';

    protected int $readyInstances = 1;

    /** @var list<string> CNPG clusters that do not exist */
    protected array $missingClusters = [];

    /** Garage layout answered by GetClusterLayout */
    protected array $garageLayout = ['version' => 0, 'roles' => []];

    /** @var array<string, array{0: int, 1: mixed}> Garage path → [status, body] */
    protected array $garageResponses = [];

    private int $garageKeys = 0;

    protected function setUpBindingFakes(): void
    {
        config([
            'bindings.poll_interval' => 0,
            'knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid(),
        ]);

        $answer = fn (KnativeRegion $region, OpsTask $task) => $this->answerOps($task);
        $this->app->instance(BindingOpsRunner::class, new class($answer) implements BindingOpsRunner
        {
            public function __construct(private \Closure $answer) {}

            public function run(KnativeRegion $region, OpsTask $task): OpsResult
            {
                return ($this->answer)($region, $task);
            }
        });

        Http::fake(fn (Request $request) => $this->answerK8s($request));

        $helm = fn (array $argv) => $this->answerHelm($argv);
        $this->app->instance(HelmService::class, new class($helm) extends HelmService
        {
            public function __construct(private \Closure $runner) {}

            protected function run(array $command): HelmResult
            {
                return ($this->runner)($command);
            }
        });
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────────────

    protected function bindingsKubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => self::$k8sServer, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    protected function bindingRegion(string $name = 'fn', array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => $name,
            'project_namespace' => $name,
            'kubeconfig' => $this->bindingsKubeconfig(),
            'is_active' => true,
        ], $attributes));
    }

    protected function readyService(KnativeRegion $region, string $service, string $mode = 'managed', array $config = [], ?array $secrets = null): BindingRegionService
    {
        $defaults = match ($service) {
            'kv' => [['acl' => true, 'maxmemory_mb' => 512, 'storage' => '2Gi'], ['password' => 'kv-admin-pw']],
            'object' => [['provider' => 'garage', 'storage' => '20Gi', 's3_region' => 'garage'], ['rpc_secret' => str_repeat('ab', 32), 'admin_token' => 'garage-admin-token']],
            'postgres' => [['instances' => 1, 'storage' => '10Gi', 'backups' => false], null],
        };

        return BindingRegionService::create([
            'knative_region_id' => $region->id,
            'service' => $service,
            'mode' => $mode,
            'status' => BindingRegionService::STATUS_READY,
            'config' => array_merge($defaults[0], $config),
            'secrets' => $secrets ?? $defaults[1],
        ]);
    }

    protected function bindingProject(KnativeRegion $region, string $name = 'orders-api', string $trigger = 'http'): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '250m',
            'memory_mb' => 128,
            'trigger_type' => $trigger,
            'schedule' => $trigger === 'schedule' ? '*/5 * * * *' : null,
        ]);

        return $project->fresh();
    }

    /**
     * A ready binding row with credentials (no provisioning).
     */
    protected function makeBinding(string $type, ?KnativeRegion $region, array $attributes = []): Binding
    {
        $id = strtolower((string) Str::ulid());
        $name = $attributes['name'] ?? $type.'-'.substr($id, -4);
        $plan = $attributes['plan'] ?? ($type === 'external' ? 'external' : 'shared');
        $rn = Binding::resourceNameFor($name, $id);
        $pg = 'b_'.str_replace('-', '_', $rn);

        $credentials = match ($type) {
            'kv' => ['mode' => 'acl', 'username' => $rn, 'password' => 'kv-pass-1'],
            'object' => ['bucket' => $rn, 'bucket_id' => 'bucket-'.$rn, 'access_key_id' => 'GKold', 'secret_access_key' => 'old-secret', 'scoped' => true],
            'postgres' => [
                'cluster' => $plan === 'dedicated' ? 'pg-'.$rn : 'runr-pg-shared',
                'host' => ($plan === 'dedicated' ? 'pg-'.$rn : 'runr-pg-shared').'-rw.runr-data.svc.cluster.local',
                'port' => 5432,
                'database' => $pg,
                'username' => $pg,
                'password' => 'pg-pass-1',
            ],
            'external' => ['values' => ['API_URL' => 'https://api.example.com', 'API_TOKEN' => 'tok-1']],
        };

        $binding = new Binding(array_merge([
            'name' => $name,
            'resource_name' => $rn,
            'type' => $type,
            'plan' => $plan,
            'knative_region_id' => $region?->id,
            'status' => Binding::STATUS_READY,
            'settings' => BindingManager::normalizeSettings($type, $plan, (array) ($attributes['settings'] ?? [])),
            'credentials' => $credentials,
        ], array_diff_key($attributes, ['settings' => true])));
        $binding->id = $id;
        $binding->save();

        return $binding->fresh(['region']);
    }

    // ── Assertions helpers ──────────────────────────────────────────────────────────────

    /** @return list<OpsTask> */
    protected function opsTasks(string $purpose): array
    {
        return array_values(array_filter($this->opsTasks, fn (OpsTask $t) => $t->purpose === $purpose));
    }

    protected function opsTask(string $purpose): ?OpsTask
    {
        $tasks = $this->opsTasks($purpose);

        return $tasks ? end($tasks) : null;
    }

    /** Every Garage admin call made, in order: [method, path, query, body] */
    protected function garageCalls(): array
    {
        $calls = [];
        foreach ($this->opsTasks as $task) {
            if (isset($task->files['calls.json'])) {
                array_push($calls, ...json_decode($task->files['calls.json'], true));
            }
        }

        return $calls;
    }

    protected function garageCallTo(string $path): ?array
    {
        $found = null;
        foreach ($this->garageCalls() as $call) {
            if ($call['path'] === $path) {
                $found = $call;
            }
        }

        return $found;
    }

    /** @return list<array{method: string, path: string, query: string, body: string}> */
    protected function k8sSentAll(string $method, string $path): array
    {
        return array_values(array_filter($this->k8sCalls, fn ($c) => $c['method'] === $method && $c['path'] === $path));
    }

    protected function k8sSent(string $method, string $path): ?array
    {
        $calls = $this->k8sSentAll($method, $path);

        return $calls ? end($calls) : null;
    }

    /** Decoded body of the last PATCH (apply) to a path */
    protected function applied(string $path): ?array
    {
        $call = $this->k8sSent('PATCH', $path);

        return $call ? json_decode($call['body'], true) : null;
    }

    protected function assertApplied(string $path): array
    {
        $body = $this->applied($path);
        $this->assertNotNull($body, "PATCH {$path} was not sent");

        return $body;
    }

    // ── Fakes ───────────────────────────────────────────────────────────────────────────

    private function answerOps(OpsTask $task): OpsResult
    {
        $this->opsTasks[] = $task;

        if (isset($this->opsResponses[$task->purpose])) {
            $response = $this->opsResponses[$task->purpose];

            return $response instanceof OpsResult ? $response : $response($task);
        }

        if (isset($task->files['calls.json'])) {
            return $this->answerGarage(json_decode($task->files['calls.json'], true));
        }

        return match ($task->purpose) {
            'kv-probe' => new OpsResult(true, "PING PONG\nACL_SUPPORTED ".($this->aclSupported ? 'yes' : 'no')."\nREDIS_VERSION {$this->redisVersion}\nRUNR_OK\n"),
            'kv-usage' => new OpsResult(true, $this->usageLines($task->files['targets'] ?? '', fn ($id) => ($this->usageBytes[$id] ?? 0).' 3')."RUNR_OK\n"),
            'object-usage' => new OpsResult(true, $this->usageLines($task->files['targets'] ?? '', fn ($id) => (string) ($this->usageBytes[$id] ?? 0))."RUNR_OK\n"),
            'postgres-usage' => new OpsResult(true, $this->postgresUsage((string) ($task->files['script.sql'] ?? ''))."RUNR_OK\n"),
            default => new OpsResult(true, "RUNR_OK\n"),
        };
    }

    private function usageLines(string $targets, callable $value): string
    {
        $out = '';
        foreach (preg_split('/\n/', trim($targets)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $id = explode(' ', trim($line))[0];
            $out .= "USAGE {$id} ".$value($id)."\n";
        }

        return $out;
    }

    private function postgresUsage(string $sql): string
    {
        preg_match('/IN \((.*)\)/', $sql, $m);
        preg_match_all("/'([^']+)'/", $m[1] ?? '', $names);

        $out = '';
        foreach ($names[1] as $db) {
            $out .= "USAGE {$db} ".($this->usageBytes[$db] ?? 0)."\n";
        }

        return $out;
    }

    private function answerGarage(array $calls): OpsResult
    {
        $out = [];
        foreach ($calls as $call) {
            $path = $call['path'];
            $query = (array) ($call['query'] ?? []);
            $body = $call['body'] ?? null;

            if (isset($this->garageResponses[$path])) {
                [$status, $response] = $this->garageResponses[$path];
            } else {
                $status = 200;
                $response = match ($path) {
                    '/v2/CreateBucket' => ['id' => 'bucket-'.($body['globalAlias'] ?? 'x'), 'globalAliases' => [$body['globalAlias'] ?? 'x']],
                    '/v2/CreateKey' => (function () use ($body) {
                        $n = ++$this->garageKeys;

                        return ['accessKeyId' => "GK{$n}", 'secretAccessKey' => "secret-{$n}", 'name' => $body['name'] ?? ''];
                    })(),
                    '/v2/GetClusterStatus' => ['layoutVersion' => $this->garageLayout['version'] ?? 0, 'nodes' => [['id' => 'node-1', 'isUp' => true]]],
                    '/v2/GetClusterLayout' => $this->garageLayout,
                    '/v2/GetBucketInfo' => (function () use ($query) {
                        $id = $query['id'] ?? ('bucket-'.($query['globalAlias'] ?? 'x'));

                        return ['id' => $id, 'bytes' => $this->usageBytes[$id] ?? 0, 'objects' => 2, 'quotas' => ['maxSize' => 5 * 1024 ** 3, 'maxObjects' => null]];
                    })(),
                    default => null,
                };
            }

            $out[] = ['name' => $call['name'] ?? null, 'status' => $status, 'body' => $response];
            if (($status === 0 || $status >= 300) && empty($call['allow_error'])) {
                break;
            }
        }

        return new OpsResult(true, 'RUNR_RESULT '.json_encode($out)."\n");
    }

    private function answerK8s(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $method = $request->method();
        $this->k8sCalls[] = [
            'method' => $method,
            'path' => $path,
            'query' => (string) parse_url($request->url(), PHP_URL_QUERY),
            'body' => $request->body(),
        ];

        if ($method !== 'GET') {
            if ($this->failWritesTo !== null && str_contains($path, $this->failWritesTo)) {
                return Http::response(['message' => 'denied'], 500);
            }

            return Http::response([], 200);
        }

        if (array_key_exists($path, $this->k8sResources)) {
            $resource = $this->k8sResources[$path];

            return $resource === null ? Http::response(['kind' => 'Status', 'code' => 404], 404) : Http::response($resource);
        }

        if (str_ends_with($path, '/pods')) {
            return Http::response(['items' => [[
                'metadata' => ['name' => 'svc-0'],
                'status' => ['phase' => 'Running', 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
            ]]]);
        }

        if ($path === '/apis/postgresql.cnpg.io/v1') {
            return Http::response(['kind' => 'APIResourceList', 'groupVersion' => 'postgresql.cnpg.io/v1', 'resources' => [['name' => 'clusters']]]);
        }

        if (preg_match('#^/apis/postgresql\.cnpg\.io/v1/namespaces/[^/]+/clusters/([^/]+)$#', $path, $m)) {
            if (in_array($m[1], $this->missingClusters, true)) {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            return Http::response([
                'metadata' => ['name' => $m[1]],
                'spec' => ['instances' => max(1, $this->readyInstances)],
                'status' => ['readyInstances' => $this->readyInstances, 'phase' => 'Cluster in healthy state'],
            ]);
        }

        return Http::response(['kind' => 'Status', 'code' => 404], 404);
    }

    private function answerHelm(array $argv): HelmResult
    {
        $this->helmCommands[] = $argv;

        if (($argv[1] ?? '') === 'upgrade' && $this->helmFails) {
            return new HelmResult(false, '', 'Error: INSTALLATION FAILED: chart not found', 1);
        }

        return new HelmResult(true, '', '', 0);
    }
}
