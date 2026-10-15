<?php

namespace App\Services\Bindings\Objects;

use App\Models\KnativeRegion;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\Ops\BindingOpsRunner;
use App\Services\Bindings\Ops\OpsTask;

/**
 * Garage admin API (v2) calls, made from inside the region by a toolbox pod — the Kubernetes API
 * proxy drops the Authorization header the admin token travels in. One pod runs a list of calls
 * in order; a call can use values of earlier ones ("{{bucket.id}}"). Output: one
 * "RUNR_RESULT <json>" line with every call's status and body.
 */
class GarageAdmin
{
    public const CLIENT_PY = <<<'PY'
import json, os, re, sys, urllib.error, urllib.parse, urllib.request

base = os.environ["GARAGE_ADMIN_URL"].rstrip("/")
token = open("/ops/token").read().strip()
calls = json.load(open("/ops/calls.json"))
results = {}

def lookup(path):
    cur = results
    for part in path.split("."):
        cur = cur[int(part)] if isinstance(cur, list) else cur[part]
    return cur

def resolve(value):
    if isinstance(value, str):
        whole = re.fullmatch(r"\{\{([^}]+)\}\}", value)
        if whole:
            return lookup(whole.group(1))
        return re.sub(r"\{\{([^}]+)\}\}", lambda m: str(lookup(m.group(1))), value)
    if isinstance(value, dict):
        return {k: resolve(v) for k, v in value.items()}
    if isinstance(value, list):
        return [resolve(v) for v in value]
    return value

out = []
for call in calls:
    query = resolve(call.get("query") or {})
    url = base + resolve(call["path"]) + ("?" + urllib.parse.urlencode(query) if query else "")
    data = json.dumps(resolve(call["body"])).encode() if "body" in call else None
    req = urllib.request.Request(url, data=data, method=call.get("method", "GET"), headers={
        "Authorization": "Bearer " + token,
        "Content-Type": "application/json",
    })
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            status, body = resp.status, resp.read().decode()
    except urllib.error.HTTPError as e:
        status, body = e.code, e.read().decode()
    except Exception as e:
        status, body = 0, str(e)
    try:
        parsed = json.loads(body) if body else None
    except ValueError:
        parsed = body
    out.append({"name": call.get("name"), "status": status, "body": parsed})
    if call.get("name"):
        results[call["name"]] = parsed
    if (status == 0 or status >= 300) and not call.get("allow_error"):
        break

print("RUNR_RESULT " + json.dumps(out))
PY;

    public function __construct(private BindingOpsRunner $ops) {}

    /**
     * Run calls; any failed call (unless allow_error) throws.
     *
     * @param  list<array{name?: string, method?: string, path: string, query?: array, body?: mixed, allow_error?: bool}>  $calls
     * @return array<string, mixed> named call → response body
     */
    public function run(KnativeRegion $region, string $adminUrl, string $token, array $calls, string $purpose): array
    {
        $task = new OpsTask(
            $purpose,
            (string) config('bindings.ops.toolbox_image'),
            "python3 /ops/client.py\n",
            [
                'client.py' => self::CLIENT_PY,
                'calls.json' => json_encode($calls, JSON_UNESCAPED_SLASHES),
                'token' => $token,
            ],
            [['name' => 'GARAGE_ADMIN_URL', 'value' => $adminUrl]],
        );

        $result = $this->ops->run($region, $task);
        $entries = $result->json('RUNR_RESULT');

        if (! is_array($entries)) {
            throw new BindingException(__('api.bindings.object_admin_failed', ['error' => $result->tail()]));
        }

        $named = [];
        foreach ($entries as $i => $entry) {
            $status = (int) ($entry['status'] ?? 0);
            $call = $calls[$i] ?? [];
            if (($status === 0 || $status >= 300) && empty($call['allow_error'])) {
                $body = is_string($entry['body'] ?? null) ? $entry['body'] : json_encode($entry['body'] ?? null);
                throw new BindingException(__('api.bindings.object_admin_failed', [
                    'error' => ($call['method'] ?? 'GET').' '.($call['path'] ?? '?')." → {$status}: ".mb_substr((string) $body, 0, 300),
                ]));
            }
            if (! empty($entry['name'])) {
                $named[$entry['name']] = $entry['body'];
            }
        }

        if (count($entries) < count($calls)) {
            throw new BindingException(__('api.bindings.object_admin_failed', ['error' => 'incomplete call list']));
        }

        return $named;
    }

    // ── Call builders (admin API v2) ────────────────────────────────────────────────────

    public static function createBucket(string $name, string $alias): array
    {
        return ['name' => $name, 'method' => 'POST', 'path' => '/v2/CreateBucket', 'body' => ['globalAlias' => $alias]];
    }

    public static function updateQuota(string $bucketRef, int $maxBytes): array
    {
        return ['method' => 'POST', 'path' => '/v2/UpdateBucket', 'query' => ['id' => $bucketRef],
            'body' => ['quotas' => ['maxSize' => $maxBytes, 'maxObjects' => null]]];
    }

    public static function createKey(string $name, string $keyName): array
    {
        return ['name' => $name, 'method' => 'POST', 'path' => '/v2/CreateKey', 'body' => ['name' => $keyName]];
    }

    public static function allow(string $bucketRef, string $keyRef, bool $read = true, bool $write = true, bool $owner = false): array
    {
        return ['method' => 'POST', 'path' => '/v2/AllowBucketKey', 'body' => [
            'bucketId' => $bucketRef, 'accessKeyId' => $keyRef,
            'permissions' => ['read' => $read, 'write' => $write, 'owner' => $owner],
        ]];
    }

    public static function deny(string $bucketRef, string $keyRef): array
    {
        return ['method' => 'POST', 'path' => '/v2/DenyBucketKey', 'allow_error' => true, 'body' => [
            'bucketId' => $bucketRef, 'accessKeyId' => $keyRef,
            'permissions' => ['read' => true, 'write' => true, 'owner' => true],
        ]];
    }

    public static function deleteKey(string $keyId): array
    {
        return ['method' => 'POST', 'path' => '/v2/DeleteKey', 'query' => ['id' => $keyId], 'allow_error' => true];
    }

    public static function deleteBucket(string $bucketId): array
    {
        return ['method' => 'POST', 'path' => '/v2/DeleteBucket', 'query' => ['id' => $bucketId]];
    }

    public static function bucketInfo(string $name, string $bucketId, bool $allowError = false): array
    {
        return ['name' => $name, 'method' => 'GET', 'path' => '/v2/GetBucketInfo', 'query' => ['id' => $bucketId], 'allow_error' => $allowError];
    }

    public static function bucketInfoByAlias(string $name, string $alias): array
    {
        return ['name' => $name, 'method' => 'GET', 'path' => '/v2/GetBucketInfo', 'query' => ['globalAlias' => $alias], 'allow_error' => true];
    }

    public static function clusterStatus(): array
    {
        return ['name' => 'status', 'method' => 'GET', 'path' => '/v2/GetClusterStatus'];
    }

    public static function clusterLayout(): array
    {
        return ['name' => 'layout', 'method' => 'GET', 'path' => '/v2/GetClusterLayout'];
    }

    public static function updateLayout(string $nodeId, int $capacity): array
    {
        return ['method' => 'POST', 'path' => '/v2/UpdateClusterLayout', 'body' => ['roles' => [[
            'id' => $nodeId, 'zone' => 'dc1', 'capacity' => $capacity, 'tags' => ['runr'],
        ]]]];
    }

    public static function applyLayout(int $version): array
    {
        return ['method' => 'POST', 'path' => '/v2/ApplyClusterLayout', 'body' => ['version' => $version]];
    }
}
