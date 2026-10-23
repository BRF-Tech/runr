<?php

namespace App\Console\Commands\Concerns;

use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\GatewayService;
use App\Services\Manifests\GatewayAuthManifests;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\Steps\GatewayRoutesStep;

/**
 * E2E of the API product (test:e2e --suite=api-product, runtime/lib/test-api-product.sh; the
 * gateway-auth image is built from runtime/gateway-auth and loaded into Kind), on one Python
 * function that echoes its request headers, routed through the admin's gateway in the e2e region:
 *  - api-route-public    the route answers 200 through the gateway host (no key needed)
 *  - api-key-required    auth_mode key: right after the switch the route refuses calls without a
 *                        key (503 auth_unavailable while gateway-auth starts — fail closed);
 *                        runr-gateway-auth becomes ready; without a key 401, with an
 *                        unknown key 401, with the consumer's key 200 — the function sees
 *                        X-Runr-Consumer = the consumer's id and neither X-Api-Key nor the key
 *  - api-rate-limit      the consumer's plan allows 3 requests a minute: of 6 calls at least one
 *                        answers 429 with RateLimit-Limit 3 and Retry-After
 *  - api-metering        the key route goes through gateway-auth's metering proxy (its rule names
 *                        runr-gateway-auth-proxy, its forwardAuth asks for a ticket); three calls
 *                        with a key of an unlimited plan reach GET /api/api-usage per key as three
 *                        2xx answers with latency percentiles and answer bytes; the proxy called
 *                        directly, without the check's ticket, answers 403 not_through_gateway
 *  - api-routes-reconcile the gateway's IngressRoute without its routing revision (as an earlier
 *                        release left it) is rebuilt by region:reconcile's gateway-routes step,
 *                        the route keeps refusing calls without a key, a second run changes nothing
 *  - api-usage           the region's usage report reaches GET /api/api-usage: requests and
 *                        rate_limited of the consumer, unauthorized calls without a consumer
 *  - api-jwt             auth_mode jwt with the JWKS of a second function: a token RunR signs
 *                        here passes (X-Runr-Subject), a token of another key and an expired one 401
 *  - api-key-on-create   the route removed and added again with auth_mode key in the POST: its
 *                        answer is the route as the gateway shows it (url, auth_mode key), no call
 *                        without a key reaches the function from the creation on (401, or nothing
 *                        while Traefik loads the rules), and a new key of the consumer gets 200
 *  - api-gateway-only    gateway_only: the function's own URL stops serving (404), the gateway
 *                        route keeps answering 200
 */
trait E2eTestApiProductTrait
{
    private const API_PRODUCT_ECHO = <<<'PY'
def handler(event, context):
    headers = {k.lower(): v for k, v in (context.get("headers") or {}).items()}
    return {"statusCode": 200, "body": {
        "consumer": headers.get("x-runr-consumer"),
        "subject": headers.get("x-runr-subject"),
        "api_key": headers.get("x-api-key"),
        "authorization": headers.get("authorization"),
    }}
PY;

    private ?array $apiGateway = null;

    private ?string $apiRouteId = null;

    private ?string $apiConsumerId = null;

    private ?string $apiKeyId = null;

    private ?string $apiKey = null;

    private function runApiProductSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── API product (keys, plans, usage, JWT, gateway-only) ──');

        // gateway-auth calls RunR back (config sync, usage): the API container on the Kind network
        $this->pointIngestAtApiContainer();

        $tests = [$this->makeTestEntry('api-function', [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'http',
            'code' => self::API_PRODUCT_ECHO,
        ], $regionId)];
        $this->runTestsSequentially($tests);
        $tests[0]['steps']['verify'] = $tests[0]['steps']['ready'] === true ? true : 'skipped';
        $fn = $tests[0];
        if ($fn['steps']['ready'] !== true) {
            return $tests;
        }

        $tests[] = $this->admCheck('api-route-public', fn () => $this->apiRoutePublic($fn, $regionId));
        if ($this->apiRouteId === null) {
            return $tests;
        }
        $tests[] = $this->admCheck('api-key-required', fn () => $this->apiKeyRequired());
        $tests[] = $this->admCheck('api-rate-limit', fn () => $this->apiRateLimit());
        $tests[] = $this->admCheck('api-metering', fn () => $this->apiMetering());
        $tests[] = $this->admCheck('api-routes-reconcile', fn () => $this->apiRoutesReconcile($regionId));
        $tests[] = $this->admCheck('api-revoke', fn () => $this->apiRevoke());
        $tests[] = $this->admCheck('api-usage', fn () => $this->apiUsage());
        $tests[] = $this->admCheck('api-jwt', fn () => $this->apiJwt($regionId));
        $tests[] = $this->admCheck('api-key-on-create', fn () => $this->apiKeyOnCreate($fn));
        $tests[] = $this->admCheck('api-gateway-only', fn () => $this->apiGatewayOnly($fn));

        return $tests;
    }

    private function apiRoutePublic(array $fn, string $regionId): bool|string
    {
        $admin = User::query()->where('username', config('app.admin.username'))->first() ?? User::query()->first();
        $gateway = app(GatewayService::class)->findOrCreateForRegion($admin, $this->getE2eRegion());

        $route = $this->api()->post("/api/gateways/{$gateway->id}/routes", ['project_id' => $fn['projectId'], 'path' => '/e2e-api']);
        if ($route->status() !== 201) {
            return 'adding the route answered '.$route->status().': '.mb_substr($route->body(), 0, 300);
        }
        $this->apiRouteId = (string) $route->json('data.id');
        $this->apiGateway = (array) $this->api()->get("/api/gateways/{$gateway->id}")->json('data');

        return $this->apiWaitGateway([], ['200'], 180, '"consumer"');
    }

    private function apiKeyRequired(): bool|string
    {
        $gatewayId = $this->apiGateway['id'];
        $plan = $this->api()->post('/api/api-plans', ['gateway_id' => $gatewayId, 'name' => 'e2e', 'rate_per_minute' => 3]);
        $consumer = $this->api()->post('/api/api-consumers', ['gateway_id' => $gatewayId, 'name' => 'e2e customer', 'plan_id' => $plan->json('data.id')]);
        $this->apiConsumerId = $consumer->json('data.id');
        $key = $this->api()->post("/api/api-consumers/{$this->apiConsumerId}/keys", ['name' => 'e2e']);
        if (! $this->apiConsumerId || $key->status() !== 201) {
            return 'plan / consumer / key answered '.$plan->status().' / '.$consumer->status().' / '.$key->status().': '.mb_substr($key->body(), 0, 300);
        }
        $this->apiKeyId = $key->json('data.id');
        $this->apiKey = $key->json('data.key');

        $mode = $this->api()->patch("/api/gateways/{$gatewayId}/routes/{$this->apiRouteId}", ['auth_mode' => 'key']);
        if (! $mode->successful()) {
            return 'auth_mode key answered '.$mode->status().': '.mb_substr($mode->body(), 0, 300);
        }

        // fail closed: from the switch on, a call without a key never reaches the function — while
        // gateway-auth starts it answers 503 auth_unavailable (or 401 once it is ready)
        $early = $this->apiCurl([], 1)[0] ?? null;
        if (is_array($early) && ($early['status'] === '200' || str_contains($early['body'], '"consumer"'))) {
            return 'right after the switch the route served a call without a key: HTTP '.$early['status'];
        }
        if (is_array($early) && $early['status'] === '503' && ! str_contains($early['body'], 'auth_unavailable') && ! str_contains($early['body'], 'not_ready')) {
            return 'the 503 while gateway-auth starts does not say why: '.mb_substr($early['body'], 0, 200);
        }

        $ready = $this->apiWaitDeployment(180);
        if ($ready !== true) {
            return $ready;
        }

        // the routes open once gateway-auth is ready (GatewayAuthHealthJob, every minute)
        $anonymous = $this->apiWaitGateway([], ['401'], 180);
        if ($anonymous !== true) {
            return 'without a key: '.$anonymous;
        }
        $unknown = $this->apiWaitGateway(['X-Api-Key: rk_unknownunknownunknownunknownunknownunknown0'], ['401'], 30);
        if ($unknown !== true) {
            return 'with an unknown key: '.$unknown;
        }

        $res = $this->apiCurl(['X-Api-Key: '.$this->apiKey], 1)[0] ?? null;
        if (! is_array($res) || $res['status'] !== '200') {
            return 'with the key: '.json_encode($res);
        }
        $body = json_decode($res['body'], true) ?: [];
        if (($body['consumer'] ?? null) !== $this->apiConsumerId) {
            return 'the function saw X-Runr-Consumer '.json_encode($body['consumer'] ?? null).', want '.$this->apiConsumerId;
        }
        if (! empty($body['api_key']) || str_contains((string) ($body['authorization'] ?? ''), (string) $this->apiKey)) {
            return 'the key reached the function';
        }

        return true;
    }

    private function apiRateLimit(): bool|string
    {
        // a new minute window is not needed: the previous step used one of the three
        $results = $this->apiCurl(['X-Api-Key: '.$this->apiKey], 6);
        $limited = array_values(array_filter($results, fn ($r) => is_array($r) && $r['status'] === '429'));
        if ($limited === []) {
            return 'no 429 among '.json_encode(array_map(fn ($r) => is_array($r) ? $r['status'] : $r, $results));
        }

        $headers = strtolower($limited[0]['headers']);

        return str_contains($headers, 'ratelimit-limit: 3') && str_contains($headers, 'retry-after:')
            ? true
            : 'the 429 lacks RateLimit-Limit 3 / Retry-After: '.mb_substr($limited[0]['headers'], 0, 400);
    }

    private function apiMetering(): bool|string
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $gatewayId = $this->apiGateway['id'];

        // the health job moves the route onto the proxy once a pod of it takes calls
        $deadline = time() + 150;
        $service = '';
        while (time() < $deadline) {
            $rule = $k8s->getResourceStatus('traefik.io/v1alpha1', 'IngressRoute', "gw-{$gatewayId}", $ns)['spec']['routes'][0] ?? [];
            $service = (string) ($rule['services'][0]['name'] ?? '');
            if ($service === GatewayAuthManifests::PROXY_NAME) {
                break;
            }
            sleep(10);
        }
        if ($service !== GatewayAuthManifests::PROXY_NAME) {
            return "the key route does not go through the metering proxy within 150 s (service {$service})";
        }
        $address = (string) ($k8s->getResourceStatus('traefik.io/v1alpha1', 'Middleware', GatewayAuthManifests::middlewareName($this->apiRouteId), $ns)['spec']['forwardAuth']['address'] ?? '');
        if (! str_ends_with($address, '?meter=1')) {
            return "the route's check does not hand the proxy a ticket: {$address}";
        }

        // a key of its own plan without limits: counted alone, never refused
        $plan = $this->api()->post('/api/api-plans', ['gateway_id' => $gatewayId, 'name' => 'e2e unlimited']);
        $key = $this->api()->post("/api/api-consumers/{$this->apiConsumerId}/keys", ['name' => 'e2e metered', 'plan_id' => $plan->json('data.id')]);
        if ($key->status() !== 201) {
            return 'key of the unlimited plan answered '.$key->status().': '.mb_substr($key->body(), 0, 300);
        }
        $keyId = (string) $key->json('data.id');
        $ok = $this->apiWaitGateway(['X-Api-Key: '.$key->json('data.key')], ['200'], 60, '"consumer"');
        if ($ok !== true) {
            return 'with the new key: '.$ok;
        }
        $calls = $this->apiCurl(['X-Api-Key: '.$key->json('data.key')], 2);
        if (array_filter($calls, fn ($r) => ! is_array($r) || $r['status'] !== '200') !== []) {
            return 'calls with the new key: '.json_encode(array_map(fn ($r) => is_array($r) ? $r['status'] : $r, $calls));
        }

        // what the function answered reaches the usage per key
        $deadline = time() + 120;
        $last = '';
        while (time() < $deadline) {
            $usage = $this->api()->get('/api/api-usage', ['gateway_id' => $gatewayId, 'group_by' => 'key']);
            $row = collect((array) $usage->json('data'))->firstWhere('key_id', $keyId);
            if ($row && $row['status_2xx'] >= 3 && $row['responses'] >= 3 && $row['latency_p50_ms'] !== null && $row['bytes_out'] > 0) {
                $this->line("    key {$keyId}: {$row['responses']} answers, p50 {$row['latency_p50_ms']} ms, p99 {$row['latency_p99_ms']} ms, {$row['bytes_out']} bytes out");
                break;
            }
            $last = mb_substr($usage->body(), 0, 400);
            sleep(10);
        }
        if (! isset($row) || ! $row || $row['status_2xx'] < 3) {
            return 'the answers did not reach the usage within 120 s: '.$last;
        }

        // the proxy itself, without the check's ticket: nothing reaches the function
        $direct = $k8s->serviceProxyRequest('GET', $ns, GatewayAuthManifests::PROXY_NAME, 80, '/e2e-api');
        if ($direct->status() !== 403 || ! str_contains($direct->body(), 'not_through_gateway')) {
            return 'the proxy called without a ticket answered '.$direct->status().' '.mb_substr($direct->body(), 0, 200);
        }

        return true;
    }

    private function apiRoutesReconcile(string $regionId): bool|string
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $name = "gw-{$this->apiGateway['id']}";

        // as an earlier release left it: no routing revision
        $k8s->mergePatch('traefik.io/v1alpha1', 'IngressRoute', $name, $ns, ['metadata' => ['labels' => [GatewayService::ROUTING_LABEL => null]]]);

        $group = new ClusterGroup('e2e', [new ReconcileTarget(KnativeRegion::findOrFail($regionId), 'central')], $k8s);
        $dry = (new GatewayRoutesStep)->run($group, true);
        if (! collect($dry)->contains(fn ($l) => str_contains($l, 'would rebuild') && str_contains($l, (string) $this->apiGateway['id']))) {
            return 'the dry run does not name the gateway: '.json_encode($dry);
        }
        $lines = (new GatewayRoutesStep)->run($group, false);
        if (! collect($lines)->contains(fn ($l) => str_contains($l, 'rebuilt') && str_contains($l, (string) $this->apiGateway['id']))) {
            return 'the gateway was not rebuilt: '.json_encode($lines);
        }
        if (empty($k8s->getResourceStatus('traefik.io/v1alpha1', 'IngressRoute', $name, $ns)['metadata']['labels'][GatewayService::ROUTING_LABEL])) {
            return 'the rebuilt IngressRoute has no routing revision';
        }
        $again = (new GatewayRoutesStep)->run($group, false);
        if ($again !== []) {
            return 'a second run changed something: '.json_encode($again);
        }

        $refused = $this->apiWaitGateway([], ['401'], 60);

        return $refused === true ? true : 'without a key after the rebuild: '.$refused;
    }

    private function apiRevoke(): bool|string
    {
        $revoke = $this->api()->post("/api/api-keys/{$this->apiKeyId}/revoke");
        if (! $revoke->successful()) {
            return 'revoke answered '.$revoke->status();
        }

        return $this->apiWaitGateway(['X-Api-Key: '.$this->apiKey], ['401'], 90);
    }

    private function apiUsage(): bool|string
    {
        $deadline = time() + 120;
        $last = '';
        while (time() < $deadline) {
            $usage = $this->api()->get('/api/api-usage', ['gateway_id' => $this->apiGateway['id'], 'group_by' => 'consumer']);
            $rows = collect((array) $usage->json('data'));
            $mine = $rows->firstWhere('consumer_id', $this->apiConsumerId);
            $anonymous = $rows->first(fn ($r) => ($r['consumer_id'] ?? null) === null);
            if ($mine && $mine['requests'] >= 1 && $mine['rate_limited'] >= 1 && $anonymous && $anonymous['unauthorized'] >= 1) {
                return true;
            }
            $last = mb_substr($usage->body(), 0, 400);
            sleep(10);
        }

        return 'usage not reported within 120 s: '.$last;
    }

    private function apiJwt(string $regionId): bool|string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $jwks = json_encode(['keys' => [[
            'kty' => 'RSA', 'kid' => 'e2e', 'alg' => 'RS256', 'use' => 'sig',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]]);

        $issuer = [$this->makeTestEntry('api-jwks', [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'http',
            'code' => "import json\n\ndef handler(event, context):\n    return {\"statusCode\": 200, \"body\": json.loads('".addslashes($jwks)."')}\n",
        ], $regionId)];
        $this->runTestsSequentially($issuer);
        if ($issuer[0]['steps']['ready'] !== true) {
            return 'JWKS function: '.$issuer[0]['steps']['ready'];
        }

        $route = $this->api()->patch("/api/gateways/{$this->apiGateway['id']}/routes/{$this->apiRouteId}", [
            'auth_mode' => 'jwt',
            // the issuer's public URL, as an identity provider's would be: its cluster-local host is
            // on the internal network, which admits RunR's projects with their tokens only
            'jwt' => ['jwks_url' => $this->e2eFunctionUrl($issuer[0]['slug']), 'issuer' => 'https://issuer.e2e', 'audience' => 'runr-e2e'],
        ]);
        if (! $route->successful()) {
            return 'auth_mode jwt answered '.$route->status().': '.mb_substr($route->body(), 0, 300);
        }

        $sign = function (array $claims, $privateKey) use ($b64): string {
            $input = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'e2e'])).'.'.$b64(json_encode($claims));
            openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256);

            return $input.'.'.$b64($signature);
        };
        $claims = ['iss' => 'https://issuer.e2e', 'aud' => 'runr-e2e', 'sub' => 'partner-42', 'exp' => time() + 600];

        $valid = $this->apiWaitGateway(['Authorization: Bearer '.$sign($claims, $key)], ['200'], 90, 'partner-42');
        if ($valid !== true) {
            return 'a valid token: '.$valid;
        }
        $other = $sign($claims, openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]));
        $expired = $sign(['exp' => time() - 60] + $claims, $key);
        foreach (['another key' => $other, 'expired' => $expired] as $what => $token) {
            $res = $this->apiCurl(['Authorization: Bearer '.$token], 1)[0] ?? null;
            if (! is_array($res) || $res['status'] !== '401') {
                return "a token of {$what}: ".json_encode($res);
            }
        }

        return true;
    }

    /**
     * A key route created in one call is never public: its forwardAuth Middleware reaches the region
     * before (or with) the IngressRoute, so the calls right after the POST never reach the function
     * without a key.
     */
    private function apiKeyOnCreate(array $fn): bool|string
    {
        $gatewayId = $this->apiGateway['id'];
        $removed = $this->api()->delete("/api/gateways/{$gatewayId}/routes/{$this->apiRouteId}");
        if ($removed->status() !== 204) {
            return 'removing the route answered '.$removed->status().': '.mb_substr($removed->body(), 0, 300);
        }
        $route = $this->api()->post("/api/gateways/{$gatewayId}/routes", ['project_id' => $fn['projectId'], 'path' => '/e2e-api', 'auth_mode' => 'key']);
        if ($route->status() !== 201) {
            return 'adding the key route answered '.$route->status().': '.mb_substr($route->body(), 0, 300);
        }
        if ($route->json('data.auth_mode') !== 'key' || ! str_ends_with((string) $route->json('data.url'), '/e2e-api')) {
            return 'the answer is not the route as the gateway shows it: '.mb_substr($route->body(), 0, 300);
        }
        $this->apiRouteId = (string) $route->json('data.id');

        // from the creation on: never the function without a key
        foreach ($this->apiCurl([], 5) as $res) {
            if (is_array($res) && ($res['status'] === '200' || str_contains($res['body'], '"consumer"'))) {
                return 'right after its creation the key route served a call without a key: HTTP '.$res['status'];
            }
        }
        $anonymous = $this->apiWaitGateway([], ['401'], 90);
        if ($anonymous !== true) {
            return 'without a key: '.$anonymous;
        }

        // the consumer's key was revoked (api-revoke): a new one opens the new route
        $key = $this->api()->post("/api/api-consumers/{$this->apiConsumerId}/keys", ['name' => 'e2e-on-create']);
        if ($key->status() !== 201) {
            return 'a new key answered '.$key->status().': '.mb_substr($key->body(), 0, 300);
        }
        $withKey = $this->apiWaitGateway(['X-Api-Key: '.$key->json('data.key')], ['200'], 90, (string) $this->apiConsumerId);

        return $withKey === true ? true : 'with a new key: '.$withKey;
    }

    private function apiGatewayOnly(array $fn): bool|string
    {
        $route = $this->api()->patch("/api/gateways/{$this->apiGateway['id']}/routes/{$this->apiRouteId}", ['auth_mode' => 'public', 'gateway_only' => true]);
        if (! $route->successful()) {
            return 'gateway_only answered '.$route->status().': '.mb_substr($route->body(), 0, 300);
        }

        $own = $this->admWaitHttp($fn['slug'], ['404'], 120);
        if ($own !== true) {
            return 'the function\'s own URL: '.$own;
        }

        $through = $this->apiWaitGateway([], ['200'], 120, '"consumer"');

        return $through === true ? true : 'through the gateway: '.$through;
    }

    /** runr-gateway-auth has a ready pod. */
    private function apiWaitDeployment(int $timeout): bool|string
    {
        $deadline = time() + $timeout;
        $ready = 0;
        while (time() < $deadline) {
            $deployment = $this->getE2eKubernetesClient()->getResourceStatus('apps/v1', 'Deployment', GatewayAuthManifests::NAME, $this->getE2eNamespace());
            $ready = (int) ($deployment['status']['readyReplicas'] ?? 0);
            if ($ready > 0) {
                return true;
            }
            sleep(5);
        }

        return "runr-gateway-auth not ready within {$timeout}s ({$ready} ready)";
    }

    /** @param  list<string>  $headers */
    private function apiWaitGateway(array $headers, array $codes, int $timeout, ?string $needle = null, string $path = '/e2e-api'): bool|string
    {
        $deadline = time() + $timeout;
        $last = 'no answer';
        while (time() < $deadline) {
            $res = $this->apiCurl($headers, 1, $path)[0] ?? 'no answer';
            if (is_array($res)) {
                if (in_array($res['status'], $codes, true) && ($needle === null || str_contains($res['body'], $needle))) {
                    return true;
                }
                $last = "HTTP {$res['status']} ".mb_substr(trim($res['body']), 0, 160);
            } else {
                $last = $res;
            }
            sleep(5);
        }

        return 'want HTTP '.implode('/', $codes).($needle ? " with \"{$needle}\"" : '')." within {$timeout}s, last: {$last}";
    }

    /**
     * $count calls of the route (path $path) through the gateway host from inside the cluster: the
     * region Traefik's websecure entrypoint (its ClusterIP) with the host resolved to it.
     *
     * @param  list<string>  $headers
     * @return list<array{status: string, headers: string, body: string}|string>
     */
    private function apiCurl(array $headers, int $count, string $path = '/e2e-api'): array
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $region = $this->getE2eRegion();
        $traefik = $k8s->getResourceStatus('v1', 'Service', 'traefik', $region->knative_serving_namespace ?? 'knative-serving');
        $ip = $traefik['spec']['clusterIP'] ?? null;
        if (! $ip) {
            return ['region Traefik has no ClusterIP'];
        }

        $host = (string) $this->apiGateway['host'];
        $flags = implode(' ', array_map(fn ($h) => '-H '.escapeshellarg($h), $headers));
        $one = "curl -sk --max-time 30 --resolve {$host}:443:{$ip} -D /tmp/h -o /tmp/b -w '%{http_code}' {$flags} 'https://{$host}{$path}'";
        $script = "for i in \$(seq 1 {$count}); do echo \"=== \$({$one})\"; cat /tmp/h; echo '---'; cat /tmp/b; echo; done";

        $podName = 'e2e-api-curl-'.substr(md5(uniqid()), 0, 8);
        $pod = $this->buildCurlManifest($podName, "https://{$host}{$path}");
        $pod['spec']['containers'][0]['command'] = ['sh', '-c', $script];
        $k8s->applyResource($pod, $ns);
        $phase = $this->waitForPods($k8s, $ns, [$podName], 120)[$podName] ?? 'Timeout';
        $logs = (string) $k8s->getPodLogs($podName, $ns, 'curl', 2000);
        try {
            $k8s->deleteResource('v1', 'Pod', $podName, $ns);
        } catch (\Throwable) {
        }
        if ($phase !== 'Succeeded') {
            return ["curl pod {$phase}: ".mb_substr($logs, 0, 200)];
        }

        $out = [];
        foreach (array_filter(explode('=== ', $logs)) as $chunk) {
            [$head, $body] = array_pad(explode("\n---\n", $chunk, 2), 2, '');
            [$status, $responseHeaders] = array_pad(explode("\n", $head, 2), 2, '');
            $out[] = ['status' => trim($status), 'headers' => $responseHeaders, 'body' => trim($body)];
        }

        return $out;
    }
}
