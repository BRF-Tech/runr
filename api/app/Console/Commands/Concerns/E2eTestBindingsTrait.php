<?php

namespace App\Console\Commands\Concerns;

use App\Jobs\Bindings\BindingsMaintenanceJob;
use App\Models\Binding;

/**
 * E2E of bindings (test:e2e --suite=bindings; runtime/lib/test-bindings.sh): through the API it
 *
 *  1. installs the region data services — managed KV Redis, Garage, CloudNativePG + the shared
 *     cluster — and waits until they are ready;
 *  2. creates one binding of each type: kv, object, postgres (shared), postgres (dedicated) and
 *     external;
 *  3. deploys a Python FaaS function (redis, psycopg, boto3) and attaches the bindings to the
 *     running function (rolling restart onto the new revision);
 *  4. calls it: every binding is written and read back, the KV user cannot write outside its
 *     prefix and the Postgres role cannot connect to another database;
 *  5. rotates the KV, object and Postgres credentials and calls it again;
 *  6. detaches the external binding (its env var disappears);
 *  7. books usage (measure) and checks the usage records;
 *  8. deletes, restores, deletes and purges the KV binding;
 *  9. reports whether the CNI enforces the data namespace NetworkPolicy (informational).
 */
trait E2eTestBindingsTrait
{
    private const BINDINGS_E2E_HANDLER = <<<'PY'
import os
import uuid


def _kv():
    import redis
    r = redis.Redis.from_url(os.environ["REDIS_URL"], socket_timeout=10)
    prefix = os.environ.get("KV_PREFIX", "")
    key = prefix + "e2e:" + uuid.uuid4().hex
    r.set(key, "v1")
    ok = r.get(key) == b"v1"
    isolated = False
    try:
        r.set("outside:" + uuid.uuid4().hex, "x")
    except redis.exceptions.ResponseError as e:
        isolated = "NOPERM" in str(e) or "permission" in str(e).lower()
    return {"ok": ok, "isolated": isolated, "prefix": prefix}


def _s3():
    import boto3
    from botocore.config import Config
    s3 = boto3.client(
        "s3",
        endpoint_url=os.environ["S3_ENDPOINT"],
        aws_access_key_id=os.environ["S3_ACCESS_KEY_ID"],
        aws_secret_access_key=os.environ["S3_SECRET_ACCESS_KEY"],
        region_name=os.environ["S3_REGION"],
        config=Config(s3={"addressing_style": "path"}, connect_timeout=10, read_timeout=20),
    )
    bucket = os.environ["S3_BUCKET"]
    key = "e2e/" + uuid.uuid4().hex
    s3.put_object(Bucket=bucket, Key=key, Body=b"hello")
    ok = s3.get_object(Bucket=bucket, Key=key)["Body"].read() == b"hello"
    return {"ok": ok, "bucket": bucket}


def _pg(prefix=""):
    import psycopg
    url = os.environ[prefix + "DATABASE_URL"]
    with psycopg.connect(url, connect_timeout=10) as conn:
        conn.execute("CREATE TABLE IF NOT EXISTS e2e (id serial PRIMARY KEY, v text)")
        conn.execute("INSERT INTO e2e (v) VALUES ('x')")
        count = conn.execute("SELECT count(*) FROM e2e").fetchone()[0]
    isolated = None
    if prefix == "":
        try:
            psycopg.connect(url.rsplit("/", 1)[0] + "/postgres?sslmode=require", connect_timeout=5).close()
            isolated = False
        except Exception:
            isolated = True
    return {"ok": count > 0, "isolated": isolated}


def handler(event, context):
    out = {}
    for name, fn in (("kv", _kv), ("s3", _s3), ("pg", _pg), ("pgd", lambda: _pg("PGD_"))):
        try:
            out[name] = fn()
        except KeyError as e:
            out[name] = {"ok": False, "error": "env " + str(e)}
        except Exception as e:
            out[name] = {"ok": False, "error": str(e)[:300]}
    out["ext"] = os.environ.get("EXT_GREETING")
    return {"statusCode": 200, "body": out}
PY;

    private const BINDINGS_E2E_REQUIREMENTS = "redis>=5.0\npsycopg[binary]>=3.2\nboto3>=1.34\n";

    /** @var array<string, string> binding name → id */
    private array $e2eBindings = [];

    private function runBindingsSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Bindings ──');

        $tests = [];

        // 1. Region data services
        foreach ([
            'kv' => ['mode' => 'managed', 'maxmemory_mb' => 128, 'storage' => '256Mi'],
            'object' => ['mode' => 'managed', 'storage' => '1Gi'],
            'postgres' => ['instances' => 1, 'storage' => '1Gi'],
        ] as $service => $options) {
            $tests[] = $this->bindingCheck("bind-service-{$service}", $regionId, fn () => $this->installDataService($regionId, $service, $options));
        }
        if (array_filter($tests, fn ($t) => $t['steps']['verify'] !== true)) {
            return $tests;
        }

        // 2. One binding of each type
        $create = [
            'e2e-cache' => ['type' => 'kv', 'settings' => ['memory_mb' => 16]],
            'e2e-files' => ['type' => 'object', 'settings' => ['quota_gb' => 1]],
            'e2e-db' => ['type' => 'postgres', 'plan' => 'shared', 'settings' => ['storage_gb' => 1, 'connection_limit' => 10, 'statement_timeout' => '15s']],
            'e2e-pgd' => ['type' => 'postgres', 'plan' => 'dedicated', 'settings' => ['storage_gb' => 1, 'instances' => 1]],
            'e2e-ext' => ['type' => 'external', 'values' => ['GREETING' => 'hello-from-external']],
        ];
        foreach ($create as $name => $payload) {
            $tests[] = $this->bindingCheck("bind-create-{$name}", $regionId, fn () => $this->createBinding($regionId, $name, $payload));
        }

        // 3. Function: deployed first, the bindings are attached to the running Knative Service
        $function = $this->makeTestEntry('bind-function', [
            'service_type' => 'faas',
            'runtime' => 'python',
            'trigger_type' => 'http',
            'faas_files' => [
                ['name' => 'main.py', 'content' => self::BINDINGS_E2E_HANDLER],
                ['name' => 'requirements.txt', 'content' => self::BINDINGS_E2E_REQUIREMENTS],
            ],
        ], $regionId);
        $deployed = [$function];
        $this->runTestsSequentially($deployed);
        $function = $deployed[0];
        if ($function['steps']['ready'] !== true) {
            $function['steps']['verify'] = 'skipped';

            return array_merge($tests, [$function]);
        }

        $function['steps']['verify'] = $this->attachAndWait($function, [
            'e2e-cache' => '', 'e2e-files' => '', 'e2e-db' => '', 'e2e-pgd' => 'PGD_', 'e2e-ext' => 'EXT_',
        ]);
        // deployed right before: its time runs on through attaching the bindings
        $function = $this->finishTest($function);
        $tests[] = $function;
        if ($function['steps']['verify'] !== true) {
            return $tests;
        }

        // 4. Read / write every binding + isolation
        $tests[] = $this->bindingCheck('bind-call', $regionId, fn () => $this->verifyBindingCall($function, true));

        // 5. Rotation → rolling restart → still working
        $tests[] = $this->bindingCheck('bind-rotate', $regionId, function () use ($function) {
            $revision = $this->latestReadyRevision($function['slug']);
            foreach (['e2e-cache', 'e2e-files', 'e2e-db'] as $name) {
                $res = $this->api()->post("/api/bindings/{$this->e2eBindings[$name]}/rotate");
                if ($res->status() !== 202) {
                    return "rotate {$name}: HTTP {$res->status()} ".mb_substr($res->body(), 0, 200);
                }
                if (($wait = $this->waitBindingStatus($name, 'ready', 300)) !== true) {
                    return $wait;
                }
            }
            if (! $this->waitNewRevision($function['slug'], $revision, 420)) {
                return 'no new revision after the rotation';
            }

            return $this->verifyBindingCall($function, false);
        });

        // 6. Detach the external binding: EXT_GREETING disappears
        $tests[] = $this->bindingCheck('bind-detach', $regionId, function () use ($function) {
            $attachments = collect($this->api()->get("/api/projects/{$function['projectId']}/bindings")->json('data') ?? []);
            $ext = $attachments->firstWhere('binding_id', $this->e2eBindings['e2e-ext']);
            if (! $ext) {
                return 'external attachment not listed';
            }
            $revision = $this->latestReadyRevision($function['slug']);
            $res = $this->api()->delete("/api/projects/{$function['projectId']}/bindings/{$ext['id']}");
            if (! $res->successful()) {
                return "detach: HTTP {$res->status()}";
            }
            if (! $this->waitNewRevision($function['slug'], $revision, 300)) {
                return 'no new revision after detach';
            }
            $body = $this->callBindingFunction($function);

            return self::externalBindingGone($body) ? true : 'EXT_GREETING still set: '.json_encode($body);
        });

        // 7. Usage records
        $tests[] = $this->bindingCheck('bind-usage', $regionId, function () {
            dispatch_sync(new BindingsMaintenanceJob('measure'));
            $kv = Binding::query()->find($this->e2eBindings['e2e-cache']);
            if (! $kv || $kv->usage_bytes <= 0) {
                return 'KV usage not measured ('.($kv?->usage_bytes ?? 'no binding').')';
            }
            $records = $this->api()->get("/api/bindings/{$kv->id}/usage")->json('data') ?? [];

            return $records !== [] ? true : 'no usage record';
        });

        // 8. Delete with retention → restore → delete → purge
        $tests[] = $this->bindingCheck('bind-delete-restore-purge', $regionId, function () {
            $id = $this->e2eBindings['e2e-cache'];
            $res = $this->api()->delete("/api/bindings/{$id}", ['confirm' => 'e2e-cache']);
            if ($res->status() !== 202 || $res->json('data.status') !== 'deleting') {
                return "delete: HTTP {$res->status()} ".mb_substr($res->body(), 0, 200);
            }
            if (($r = $this->api()->post("/api/bindings/{$id}/restore"))->json('data.status') !== 'ready') {
                return "restore: HTTP {$r->status()}";
            }
            $this->api()->delete("/api/bindings/{$id}", ['confirm' => 'e2e-cache']);
            Binding::query()->whereKey($id)->update(['purge_at' => now()->subMinute()]);
            dispatch_sync(new BindingsMaintenanceJob('purge'));

            return $this->api()->get("/api/bindings/{$id}")->status() === 404 ? true : 'binding still there after purge';
        });

        // 9. NetworkPolicy enforcement (informational: depends on the CNI)
        $this->reportNetworkPolicyEnforcement();

        return $tests;
    }

    private function bindingCheck(string $name, string $regionId, callable $check): array
    {
        $entry = $this->makeTestEntry($name, ['service_type' => 'xaas', 'trigger_type' => 'http'], $regionId);
        $entry['startTime'] = microtime(true);
        $entry['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => null];

        try {
            $result = $check();
        } catch (\Throwable $e) {
            $result = $e->getMessage();
        }

        $entry['steps']['verify'] = $result === true ? true : (string) $result;
        $this->line(($result === true ? '  <fg=green>✓</> ' : '  <fg=red>✗</> ').$name.($result === true ? '' : ": {$result}"));

        return $this->finishTest($entry);
    }

    private function installDataService(string $regionId, string $service, array $options): bool|string
    {
        $res = $this->api()->post("/api/knative-regions/{$regionId}/data-services/{$service}", $options);
        if ($res->status() !== 202) {
            return "HTTP {$res->status()} ".mb_substr($res->body(), 0, 300);
        }

        $deadline = time() + ($service === 'postgres' ? 1200 : 600);
        while (time() < $deadline) {
            $entry = $this->api()->get("/api/knative-regions/{$regionId}/data-services")->json("data.services.{$service}") ?? [];
            if (($entry['status'] ?? null) === 'ready') {
                return true;
            }
            if (($entry['status'] ?? null) === 'error') {
                return 'error: '.mb_substr((string) ($entry['status_message'] ?? ''), 0, 300);
            }
            sleep(10);
        }

        return 'not ready in time';
    }

    private function createBinding(string $regionId, string $name, array $payload): bool|string
    {
        $res = $this->api()->post('/api/bindings', array_merge(['name' => $name, 'knative_region_id' => $regionId], $payload));
        if ($res->status() !== 201) {
            return "HTTP {$res->status()} ".mb_substr($res->body(), 0, 300);
        }
        $this->e2eBindings[$name] = (string) $res->json('data.id');

        return $this->waitBindingStatus($name, 'ready', ($payload['plan'] ?? null) === 'dedicated' ? 1200 : 300);
    }

    private function waitBindingStatus(string $name, string $status, int $timeout): bool|string
    {
        $deadline = time() + $timeout;
        $current = null;
        while (time() < $deadline) {
            $data = $this->api()->get("/api/bindings/{$this->e2eBindings[$name]}")->json('data') ?? [];
            $current = $data['status'] ?? null;
            if ($current === $status) {
                return true;
            }
            if ($current === 'error') {
                return "{$name}: error ".mb_substr((string) ($data['status_message'] ?? ''), 0, 300);
            }
            sleep(5);
        }

        return "{$name}: {$current} after {$timeout}s";
    }

    /**
     * The function's answer after the external binding was detached: "ext" (its EXT_GREETING)
     * present and null. `?? 'set'` cannot tell a null value from a missing key.
     */
    private static function externalBindingGone(array|string $body): bool
    {
        return is_array($body) && array_key_exists('ext', $body) && $body['ext'] === null;
    }

    /** @param array<string, string> $prefixes binding name → env prefix */
    private function attachAndWait(array $function, array $prefixes): bool|string
    {
        $revision = $this->latestReadyRevision($function['slug']);

        foreach ($prefixes as $name => $prefix) {
            $res = $this->api()->post("/api/projects/{$function['projectId']}/bindings", [
                'binding_id' => $this->e2eBindings[$name] ?? null,
                'env_prefix' => $prefix ?: null,
            ]);
            if ($res->status() !== 201) {
                return "attach {$name}: HTTP {$res->status()} ".mb_substr($res->body(), 0, 300);
            }
        }

        return $this->waitNewRevision($function['slug'], $revision, 420) ? true : 'no ready revision with the bindings';
    }

    private function verifyBindingCall(array $function, bool $isolation): bool|string
    {
        $body = $this->callBindingFunction($function);
        if (! is_array($body)) {
            return (string) $body;
        }

        foreach (['kv', 's3', 'pg', 'pgd'] as $part) {
            if (($body[$part]['ok'] ?? false) !== true) {
                return "{$part}: ".json_encode($body[$part] ?? null);
            }
        }
        if (($body['ext'] ?? null) !== 'hello-from-external') {
            return 'ext: '.json_encode($body['ext'] ?? null);
        }
        if ($isolation && ($body['kv']['isolated'] ?? false) !== true) {
            return 'KV user could write outside its prefix';
        }
        if ($isolation && ($body['pg']['isolated'] ?? false) !== true) {
            return 'Postgres role could connect to another database';
        }

        return true;
    }

    private function callBindingFunction(array $function): array|string
    {
        $result = $this->curlFunction($function['slug'], '/?op=all');
        if (! is_array($result)) {
            return $result;
        }
        [$status, , $body] = $result;
        if ($status !== '200') {
            return "HTTP {$status}: ".mb_substr($body, 0, 300);
        }

        $json = json_decode($body, true);

        return is_array($json) ? $json : 'not JSON: '.mb_substr($body, 0, 300);
    }

    private function latestReadyRevision(string $slug): ?string
    {
        $service = $this->getE2eKubernetesClient()->getResourceStatus('serving.knative.dev/v1', 'Service', $slug, $this->getE2eNamespace());

        return $service['status']['latestReadyRevisionName'] ?? null;
    }

    private function waitNewRevision(string $slug, ?string $previous, int $timeout): bool
    {
        $deadline = time() + $timeout;
        while (time() < $deadline) {
            $service = $this->getE2eKubernetesClient()->getResourceStatus('serving.knative.dev/v1', 'Service', $slug, $this->getE2eNamespace());
            $latest = $service['status']['latestReadyRevisionName'] ?? null;
            $ready = collect($service['status']['conditions'] ?? [])->firstWhere('type', 'Ready')['status'] ?? null;
            $created = $service['status']['latestCreatedRevisionName'] ?? null;
            if ($latest && $latest !== $previous && $ready === 'True' && $created === $latest) {
                return true;
            }
            sleep(5);
        }

        return false;
    }

    /** A pod without project label in the project namespace tries the KV Redis port. */
    private function reportNetworkPolicyEnforcement(): void
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $podName = 'e2e-bind-np-'.substr(md5(uniqid()), 0, 8);
        $pod = $this->buildCurlManifest($podName, 'telnet://runr-kv.'.config('bindings.namespace').'.svc.cluster.local:6379');
        $pod['spec']['containers'][0]['command'] = ['sh', '-c',
            'if timeout 8 sh -c "echo PING | nc -w 5 runr-kv.'.config('bindings.namespace').'.svc.cluster.local 6379" >/dev/null 2>&1; then echo REACHED; else echo BLOCKED; fi'];

        try {
            $k8s->applyResource($pod, $ns);
            $this->waitForPods($k8s, $ns, [$podName], 60);
            $logs = (string) $k8s->getPodLogs($podName, $ns, 'curl', 20);
            $this->line(str_contains($logs, 'BLOCKED')
                ? '  <fg=green>✓</> NetworkPolicy enforced: an unattached pod cannot reach the KV Redis'
                : '  <fg=yellow>!</> The cluster CNI does not enforce NetworkPolicies (an unattached pod reached the KV Redis); bindings still need their credentials');
        } catch (\Throwable $e) {
            $this->line('  <fg=yellow>!</> NetworkPolicy check skipped: '.$e->getMessage());
        } finally {
            try {
                $k8s->deleteResource('v1', 'Pod', $podName, $ns);
            } catch (\Throwable) {
            }
        }
    }
}
