<?php

namespace App\Console\Commands\Concerns;

use App\Models\ProjectSecret;
use App\Models\SecretStore;
use App\Models\Setting;
use App\Services\Secrets\RollbackCopies;
use App\Services\Secrets\SecretFingerprint;
use App\Support\Secrets\SecretCipher;
use Illuminate\Support\Facades\Artisan;

/**
 * E2E of project secrets (test:e2e --suite=secrets, runtime/lib/test-secrets.sh;
 * docs/security.md, docs/secret-stores.md), on one Python HTTP function that answers the SHA-256
 * of two variables — the values themselves are random, never printed and never leave the cluster
 * but through the API's own read-back:
 *  - sec-passthrough   PUT …/secrets/APP_SECRET: the value is in the region's Secret
 *                      <slug>-secret-env, RunR's row has no value but a keyed fingerprint; after a
 *                      deploy the function sees it (its hash)
 *  - sec-reveal        GET …/value reads it back from the region (matches_fingerprint true)
 *  - sec-move          a value written the way RunR did before pass-through (in the database) is
 *                      moved by `secrets:move-to-region`: in the region afterwards, gone from the
 *                      database
 *  - sec-rollback-copy an installation upgraded from 0.5.10, not finalized: a value set keeps an
 *                      encrypted copy in the database (what 0.5.10 deploys after a rollback) next
 *                      to the region's; `secrets:move-to-region --finalize` drops it, the region
 *                      keeps the value
 *  - sec-eso           External Secrets Operator installed on the region through the API, ready
 *  - sec-store         a throwaway OpenBao dev server in namespace runr-e2e-bao with Kubernetes
 *                      auth that trusts the cluster; the store registered with settings only and
 *                      its role bound to the store's service account; verify → ready
 *  - sec-reference     EXT_SECRET as a reference (store + path + key): after a deploy the
 *                      function sees the value from OpenBao, `secrets:sync-status` marks it synced
 *  - sec-openbao       the region's own OpenBao installed through the API (needs the operator):
 *                      initialized, unsealed, configured, ready
 *  - sec-openbao-value BAO_SECRET set while OpenBao runs: stored in OpenBao (stored_in openbao),
 *                      nothing in the Kubernetes Secret; after a deploy the function sees it
 *  - sec-env-openbao   an environment "staging" with an APP_SECRET of its own (pass-through into
 *                      <slug>-staging-secret-env): deployed, its function sees its own APP_SECRET,
 *                      the inherited reference EXT_SECRET and the inherited OpenBao value BAO_SECRET
 *                      (copied into its own Secret); the project's value in OpenBao stays; deleting
 *                      the environment removes its Secret
 *  - sec-db-dynamic    (RUNR_E2E_SECRETS_DB=1: installs the Postgres data service) a Postgres
 *                      binding attached with dynamic credentials: the function gets a user issued
 *                      by OpenBao (v-…), not the binding's own
 *  - sec-teardown      deleting the project removes its values Secret from the region
 */
trait E2eTestSecretsTrait
{
    private const SEC_PYTHON = <<<'PY'
import hashlib, os

def handler(event, context):
    def digest(name):
        value = os.environ.get(name)
        return hashlib.sha256(value.encode()).hexdigest() if value is not None else None
    user = os.environ.get("DB_PGUSER", "")
    return {"statusCode": 200, "body": {"app": digest("APP_SECRET"), "ext": digest("EXT_SECRET"),
                                        "bao": digest("BAO_SECRET"), "db_user_prefix": user[:2]}}
PY;

    private const SEC_BAO_NS = 'runr-e2e-bao';

    private const SEC_BAO_IMAGE = 'ghcr.io/brf-tech/runr/reqs/openbao:2.4.1';

    /** @var array<string, string> name => value, in memory only */
    private array $secValues = [];

    private ?SecretStore $secStore = null;

    private function runSecretsSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Project secrets (pass-through, move, External Secrets Operator + OpenBao reference) ──');

        $this->secValues = [
            'APP_SECRET' => bin2hex(random_bytes(16)),
            'LEGACY_SECRET' => bin2hex(random_bytes(16)),
            'EXT_SECRET' => bin2hex(random_bytes(16)),
            'BAO_SECRET' => bin2hex(random_bytes(16)),
            'STAGING_APP_SECRET' => bin2hex(random_bytes(16)),
            'bao_root' => bin2hex(random_bytes(16)),
        ];

        $tests = [$this->makeTestEntry('sec-function', [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'http',
            'code' => self::SEC_PYTHON,
        ], $regionId)];
        $this->runTestsSequentially($tests);
        $tests[0]['steps']['verify'] = $tests[0]['steps']['ready'] === true ? true : 'skipped';
        $test = $tests[0];
        if ($test['steps']['ready'] !== true) {
            return $tests;
        }

        $tests[] = $this->admCheck('sec-passthrough', fn () => $this->secPassThrough($test));
        $tests[] = $this->admCheck('sec-reveal', fn () => $this->secReveal($test));
        $tests[] = $this->admCheck('sec-move', fn () => $this->secMove($test));
        $tests[] = $this->admCheck('sec-rollback-copy', fn () => $this->secRollbackCopy($test));
        $tests[] = $this->admCheck('sec-eso', fn () => $this->secInstallOperator($regionId));
        $tests[] = $this->admCheck('sec-store', fn () => $this->secStore($regionId));
        $tests[] = $this->admCheck('sec-reference', fn () => $this->secReference($test));
        $tests[] = $this->admCheck('sec-openbao', fn () => $this->secInstallOpenBao($regionId));
        $tests[] = $this->admCheck('sec-openbao-value', fn () => $this->secOpenBaoValue($test));
        $tests[] = $this->admCheck('sec-env-openbao', fn () => $this->secEnvironmentOpenBao($test));
        if ($this->option('secrets-db') || getenv('RUNR_E2E_SECRETS_DB') === '1') {
            $tests[] = $this->admCheck('sec-db-dynamic', fn () => $this->secDynamicDb($test, $regionId));
        }
        $tests[] = $this->admCheck('sec-teardown', fn () => $this->secTeardown($test));

        $this->secCleanupBao();

        return $tests;
    }

    private function secPassThrough(array $test): bool|string
    {
        $put = $this->api()->put("/api/projects/{$test['projectId']}/secrets/APP_SECRET", ['value' => $this->secValues['APP_SECRET']]);
        if ($put->status() !== 201 || $put->json('data.stored_in') !== 'region') {
            return 'PUT answered '.$put->status().' '.$put->json('message');
        }

        $secret = $this->e2eK8s->getResourceStatus('v1', 'Secret', "{$test['slug']}-secret-env", $this->e2eNamespace);
        if (base64_decode((string) ($secret['data']['APP_SECRET'] ?? '')) !== $this->secValues['APP_SECRET']) {
            return 'the region Secret does not hold the value';
        }

        $row = ProjectSecret::query()->where('project_id', $test['projectId'])->where('name', 'APP_SECRET')->first();
        if (! $row || $row->getRawOriginal('value') !== null) {
            return 'RunR\'s database holds a value';
        }
        if (! SecretFingerprint::matches($this->secValues['APP_SECRET'], $row->fingerprint)) {
            return 'the fingerprint does not match the value';
        }

        return $this->secRedeployAndSee($test, ['app' => hash('sha256', $this->secValues['APP_SECRET'])]);
    }

    private function secReveal(array $test): bool|string
    {
        $read = $this->api()->get("/api/projects/{$test['projectId']}/secrets/APP_SECRET/value");

        return $read->status() === 200 && hash_equals($this->secValues['APP_SECRET'], (string) $read->json('value')) && $read->json('matches_fingerprint') === true
            ? true
            : 'read-back answered '.$read->status().' (value matches: '.var_export(hash_equals($this->secValues['APP_SECRET'], (string) $read->json('value')), true).')';
    }

    private function secMove(array $test): bool|string
    {
        // Written the way RunR did before pass-through: encrypted in the database
        ProjectSecret::create(['project_id' => $test['projectId'], 'name' => 'LEGACY_SECRET', 'value' => $this->secValues['LEGACY_SECRET']]);

        $exit = Artisan::call('secrets:move-to-region', ['--project' => $test['projectId']]);
        if ($exit !== 0) {
            return 'secrets:move-to-region exited '.$exit.': '.mb_substr(Artisan::output(), 0, 300);
        }

        $secret = $this->e2eK8s->getResourceStatus('v1', 'Secret', "{$test['slug']}-secret-env", $this->e2eNamespace);
        if (base64_decode((string) ($secret['data']['LEGACY_SECRET'] ?? '')) !== $this->secValues['LEGACY_SECRET']) {
            return 'the moved value is not in the region';
        }
        $raw = ProjectSecret::query()->where('project_id', $test['projectId'])->where('name', 'LEGACY_SECRET')->value('value');

        return $raw === null ? true : 'the database still holds the moved value';
    }

    /**
     * The downgrade guard (RollbackCopies): with the upgrade not finalized, a value keeps an
     * encrypted copy RunR 0.5.10 would deploy; finalizing drops it. The installation's own state
     * is put back afterwards.
     */
    private function secRollbackCopy(array $test): bool|string
    {
        $finalizedAt = Setting::platform(RollbackCopies::SETTING);
        Setting::query()->whereKey(RollbackCopies::SETTING)->delete();
        $value = bin2hex(random_bytes(16));

        try {
            $put = $this->api()->put("/api/projects/{$test['projectId']}/secrets/COPY_SECRET", ['value' => $value]);
            if ($put->status() !== 201 || $put->json('data.rollback_copy') !== true) {
                return 'PUT answered '.$put->status().' (rollback_copy '.var_export($put->json('data.rollback_copy'), true).')';
            }
            $secret = $this->e2eK8s->getResourceStatus('v1', 'Secret', "{$test['slug']}-secret-env", $this->e2eNamespace);
            if (base64_decode((string) ($secret['data']['COPY_SECRET'] ?? '')) !== $value) {
                return 'the region Secret does not hold the value';
            }
            $raw = ProjectSecret::query()->where('project_id', $test['projectId'])->where('name', 'COPY_SECRET')->value('value');
            if ($raw === null || SecretCipher::decode($raw) !== $value) {
                return 'the database keeps no copy RunR 0.5.10 could deploy';
            }

            $exit = Artisan::call('secrets:move-to-region', ['--finalize' => true]);
            if ($exit !== 0) {
                return 'secrets:move-to-region --finalize exited '.$exit.': '.mb_substr(Artisan::output(), 0, 300);
            }
            if (ProjectSecret::query()->where('project_id', $test['projectId'])->where('name', 'COPY_SECRET')->value('value') !== null) {
                return 'finalizing left the copy in the database';
            }
            $secret = $this->e2eK8s->getResourceStatus('v1', 'Secret', "{$test['slug']}-secret-env", $this->e2eNamespace);

            return base64_decode((string) ($secret['data']['COPY_SECRET'] ?? '')) === $value ? true : 'finalizing lost the value in the region';
        } finally {
            $this->api()->delete("/api/projects/{$test['projectId']}/secrets/COPY_SECRET");
            if ($finalizedAt !== null) {
                Setting::set(RollbackCopies::SETTING, $finalizedAt);
            } elseif (! RollbackCopies::kept()) {
                Setting::query()->whereKey(RollbackCopies::SETTING)->delete();
            }
        }
    }

    private function secInstallOperator(string $regionId): bool|string
    {
        $install = $this->api()->post("/api/knative-regions/{$regionId}/external-secrets");
        if ($install->status() !== 202) {
            return 'install answered '.$install->status().': '.mb_substr($install->body(), 0, 300);
        }

        $deadline = time() + 600;
        $status = null;
        while (time() < $deadline) {
            $status = $this->api()->get("/api/knative-regions/{$regionId}/external-secrets")->json('data');
            if (($status['status'] ?? null) === 'ready') {
                return true;
            }
            if (($status['status'] ?? null) === 'error') {
                break;
            }
            sleep(10);
        }

        return 'External Secrets Operator not ready: '.json_encode($status);
    }

    /**
     * A dev-mode OpenBao (in memory, root token known to this run only) with Kubernetes auth: it
     * checks the store's service account tokens with the cluster's TokenReview (its own service
     * account may: system:auth-delegator). The KV secret e2e/app holds token = EXT_SECRET.
     */
    private function secStore(string $regionId): bool|string
    {
        $create = $this->api()->post('/api/secret-stores', [
            'name' => 'e2e-bao',
            'provider' => 'vault',
            'auth' => 'kubernetes',
            'settings' => ['server' => 'http://bao.'.self::SEC_BAO_NS.'.svc:8200', 'role' => 'runr-e2e', 'mount' => 'secret', 'kv_version' => 'v2'],
            'refresh_interval' => 60,
        ]);
        if ($create->status() !== 201) {
            return 'store answered '.$create->status().': '.mb_substr($create->body(), 0, 300);
        }
        $this->secStore = SecretStore::query()->findOrFail($create->json('data.id'));

        $k8s = $this->e2eK8s;
        $k8s->applyClusterResource(['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => self::SEC_BAO_NS]]);
        $k8s->applyResource(['apiVersion' => 'v1', 'kind' => 'ServiceAccount', 'metadata' => ['name' => 'bao', 'namespace' => self::SEC_BAO_NS]], self::SEC_BAO_NS);
        $k8s->applyClusterResource([
            'apiVersion' => 'rbac.authorization.k8s.io/v1', 'kind' => 'ClusterRoleBinding',
            'metadata' => ['name' => 'runr-e2e-bao-auth-delegator'],
            'roleRef' => ['apiGroup' => 'rbac.authorization.k8s.io', 'kind' => 'ClusterRole', 'name' => 'system:auth-delegator'],
            'subjects' => [['kind' => 'ServiceAccount', 'name' => 'bao', 'namespace' => self::SEC_BAO_NS]],
        ]);
        $k8s->applyResource(['apiVersion' => 'v1', 'kind' => 'Secret', 'metadata' => ['name' => 'bao-e2e', 'namespace' => self::SEC_BAO_NS],
            'type' => 'Opaque', 'data' => ['root' => base64_encode($this->secValues['bao_root']), 'ext' => base64_encode($this->secValues['EXT_SECRET'])]], self::SEC_BAO_NS);
        $k8s->applyResource([
            'apiVersion' => 'v1', 'kind' => 'Pod',
            'metadata' => ['name' => 'bao', 'namespace' => self::SEC_BAO_NS, 'labels' => ['app' => 'bao']],
            'spec' => [
                'serviceAccountName' => 'bao',
                'containers' => [[
                    'name' => 'bao', 'image' => self::SEC_BAO_IMAGE,
                    'command' => ['bao', 'server', '-dev', '-dev-listen-address=0.0.0.0:8200'],
                    'env' => [
                        ['name' => 'BAO_DEV_ROOT_TOKEN_ID', 'valueFrom' => ['secretKeyRef' => ['name' => 'bao-e2e', 'key' => 'root']]],
                        // the name Vault-compatible releases read
                        ['name' => 'VAULT_DEV_ROOT_TOKEN_ID', 'valueFrom' => ['secretKeyRef' => ['name' => 'bao-e2e', 'key' => 'root']]],
                    ],
                    'ports' => [['containerPort' => 8200]],
                ]],
            ],
        ], self::SEC_BAO_NS);
        $k8s->applyResource(['apiVersion' => 'v1', 'kind' => 'Service', 'metadata' => ['name' => 'bao', 'namespace' => self::SEC_BAO_NS],
            'spec' => ['selector' => ['app' => 'bao'], 'ports' => [['port' => 8200, 'targetPort' => 8200]]]], self::SEC_BAO_NS);

        // a server: running, not done
        $running = $this->waitForServerPod($k8s, self::SEC_BAO_NS, 'bao');
        if ($running !== true) {
            return "the OpenBao dev server did not start: {$running}";
        }

        // Kubernetes auth for the store's service account in the project namespace, a read policy
        // for secret/e2e/*, and the value
        $account = $this->secStore->kubernetesName();
        $script = implode(' && ', [
            'export BAO_ADDR=http://bao:8200 BAO_TOKEN=$(cat /e2e/root)',
            'until bao status >/dev/null 2>&1; do sleep 2; done',
            'bao auth enable kubernetes',
            'bao write auth/kubernetes/config kubernetes_host=https://kubernetes.default.svc',
            'printf \'path "secret/data/e2e/*" { capabilities = ["read"] }\' | bao policy write runr-e2e -',
            "bao write auth/kubernetes/role/runr-e2e bound_service_account_names={$account} bound_service_account_namespaces={$this->e2eNamespace} policies=runr-e2e ttl=10m",
            'bao kv put secret/e2e/app token="$(cat /e2e/ext)" >/dev/null',
        ]);
        $k8s->applyResource([
            'apiVersion' => 'v1', 'kind' => 'Pod',
            'metadata' => ['name' => 'bao-config', 'namespace' => self::SEC_BAO_NS],
            'spec' => [
                'restartPolicy' => 'Never',
                'containers' => [[
                    'name' => 'config', 'image' => self::SEC_BAO_IMAGE,
                    'command' => ['sh', '-c', $script],
                    'volumeMounts' => [['name' => 'e2e', 'mountPath' => '/e2e', 'readOnly' => true]],
                ]],
                'volumes' => [['name' => 'e2e', 'secret' => ['secretName' => 'bao-e2e']]],
            ],
        ], self::SEC_BAO_NS);
        // the same image, maybe pulled on another node
        $phase = $this->waitForPods($k8s, self::SEC_BAO_NS, ['bao-config'], self::E2E_SERVER_POD_START_SECONDS)['bao-config'] ?? 'Timeout';
        if ($phase !== 'Succeeded') {
            return "OpenBao configuration {$phase}: ".mb_substr((string) $k8s->getPodLogs('bao-config', self::SEC_BAO_NS, 'config', 30), 0, 300)
                .' — '.$this->podDiagnosisOf($k8s, self::SEC_BAO_NS, 'bao-config');
        }

        $verify = $this->api()->post("/api/secret-stores/{$this->secStore->id}/verify", ['region_id' => $regionId]);
        $deadline = time() + 120;
        while ($verify->json('data.status') === 'pending' && time() < $deadline) {
            sleep(5);
            $verify = $this->api()->post("/api/secret-stores/{$this->secStore->id}/verify", ['region_id' => $regionId]);
        }

        return $verify->json('data.status') === 'ready'
            ? true
            : 'verify answered '.$verify->status().' '.$verify->json('data.status').': '.$verify->json('data.message');
    }

    private function secReference(array $test): bool|string
    {
        if (! $this->secStore) {
            return 'no store (sec-store failed)';
        }

        $put = $this->api()->put("/api/projects/{$test['projectId']}/secrets/EXT_SECRET", [
            'reference' => ['store_id' => $this->secStore->id, 'path' => 'e2e/app', 'key' => 'token'],
        ]);
        if ($put->status() !== 201 || $put->json('data.source') !== 'external') {
            return 'PUT reference answered '.$put->status().': '.$put->json('message');
        }

        $seen = $this->secRedeployAndSee($test, [
            'app' => hash('sha256', $this->secValues['APP_SECRET']),
            'ext' => hash('sha256', $this->secValues['EXT_SECRET']),
        ]);
        if ($seen !== true) {
            return $seen;
        }

        Artisan::call('secrets:sync-status');
        $row = ProjectSecret::query()->where('project_id', $test['projectId'])->where('name', 'EXT_SECRET')->first();

        return $row?->sync_status === 'synced' ? true : 'sync status '.($row->sync_status ?? 'none').': '.($row->sync_message ?? '');
    }

    private function secInstallOpenBao(string $regionId): bool|string
    {
        $install = $this->api()->post("/api/knative-regions/{$regionId}/openbao");
        if ($install->status() !== 202) {
            return 'install answered '.$install->status().': '.mb_substr($install->body(), 0, 300);
        }

        $deadline = time() + 600;
        $status = null;
        while (time() < $deadline) {
            $status = $this->api()->get("/api/knative-regions/{$regionId}/openbao")->json('data');
            if (in_array($status['status'] ?? null, ['ready', 'error'], true)) {
                break;
            }
            sleep(10);
        }
        if (($status['status'] ?? null) !== 'ready') {
            return 'OpenBao not ready: '.json_encode($status);
        }

        // The unseal key lives in the region only, the root token is gone
        $unseal = $this->e2eK8s->getResourceStatus('v1', 'Secret', 'runr-openbao-unseal', 'runr-openbao');

        return $unseal !== null ? true : 'no unseal key in the region';
    }

    private function secOpenBaoValue(array $test): bool|string
    {
        $put = $this->api()->put("/api/projects/{$test['projectId']}/secrets/BAO_SECRET", ['value' => $this->secValues['BAO_SECRET']]);
        if ($put->status() !== 201 || $put->json('data.stored_in') !== 'openbao') {
            return 'PUT answered '.$put->status().' stored_in '.$put->json('data.stored_in').': '.$put->json('message');
        }

        $secret = $this->e2eK8s->getResourceStatus('v1', 'Secret', "{$test['slug']}-secret-env", $this->e2eNamespace);
        if (array_key_exists('BAO_SECRET', (array) ($secret['data'] ?? []))) {
            return 'the value is in the Kubernetes Secret as well';
        }
        $read = $this->api()->get("/api/projects/{$test['projectId']}/secrets/BAO_SECRET/value");
        if (! hash_equals($this->secValues['BAO_SECRET'], (string) $read->json('value'))) {
            return 'reading it back from OpenBao answered '.$read->status();
        }

        return $this->secRedeployAndSee($test, ['bao' => hash('sha256', $this->secValues['BAO_SECRET'])]);
    }

    private function secEnvironmentOpenBao(array $test): bool|string
    {
        $base = "/api/projects/{$test['projectId']}/environments";
        $created = $this->api()->post($base, ['name' => 'staging', 'requires_approval' => false]);
        if ($created->status() !== 201) {
            return 'environment answered '.$created->status().': '.mb_substr($created->body(), 0, 300);
        }
        $workload = $test['slug'].'-staging';

        $put = $this->api()->put("{$base}/staging/secrets/APP_SECRET", ['value' => $this->secValues['STAGING_APP_SECRET']]);
        if ($put->status() !== 201 || $put->json('data.stored_in') !== 'region') {
            return 'staging secret answered '.$put->status().': '.$put->json('message');
        }

        $version = $this->api()->get("{$base}/production")->json('data.deployment.version.id') ?? ($test['versionId'] ?? null);
        $deploy = $this->api()->post("/api/projects/{$test['projectId']}/deploy", ['version_id' => $version, 'environment' => 'staging']);
        if ($deploy->status() !== 202) {
            return 'deploy to staging answered '.$deploy->status().': '.mb_substr($deploy->body(), 0, 300);
        }

        $want = [
            'app' => hash('sha256', $this->secValues['STAGING_APP_SECRET']),
            'ext' => hash('sha256', $this->secValues['EXT_SECRET']),
            'bao' => hash('sha256', $this->secValues['BAO_SECRET']),
        ];
        $deadline = time() + $this->timeout;
        $last = 'no answer';
        $seen = false;
        while (time() < $deadline && ! $seen) {
            $res = $this->curlFunction($workload, '/', false);
            if (is_array($res)) {
                $body = json_decode((string) $res[2], true);
                $body = is_array($body['body'] ?? null) ? $body['body'] : $body;
                $missing = array_keys(array_filter($want, fn ($digest, $key) => ($body[$key] ?? null) !== $digest, ARRAY_FILTER_USE_BOTH));
                $seen = $res[0] === '200' && $missing === [];
                $last = "HTTP {$res[0]}, not yet: ".implode(', ', $missing);
            } else {
                $last = $res;
            }
            if (! $seen) {
                sleep(5);
            }
        }
        if (! $seen) {
            $live = $this->api()->get("{$base}/staging")->json('data.deployment');

            return "staging does not see its values ({$last}; deployment ".($live['status'] ?? 'none').')';
        }

        $secret = (array) ($this->e2eK8s->getResourceStatus('v1', 'Secret', "{$workload}-secret-env", $this->e2eNamespace)['data'] ?? []);
        if (base64_decode((string) ($secret['BAO_SECRET'] ?? '')) !== $this->secValues['BAO_SECRET']
            || base64_decode((string) ($secret['APP_SECRET'] ?? '')) !== $this->secValues['STAGING_APP_SECRET']) {
            return "{$workload}-secret-env does not hold the inherited OpenBao value and its own";
        }
        $read = $this->api()->get("/api/projects/{$test['projectId']}/secrets/BAO_SECRET/value");
        if (! hash_equals($this->secValues['BAO_SECRET'], (string) $read->json('value'))) {
            return 'the project\'s value in OpenBao changed after the staging deploy ('.$read->status().')';
        }

        $deleted = $this->api()->delete("{$base}/staging");
        if (! $deleted->successful()) {
            return 'environment delete answered '.$deleted->status();
        }
        $deadline = time() + 120;
        while (time() < $deadline) {
            if ($this->e2eK8s->getResourceStatus('v1', 'Secret', "{$workload}-secret-env", $this->e2eNamespace) === null) {
                return true;
            }
            sleep(5);
        }

        return "{$workload}-secret-env stays after the environment was deleted";
    }

    private function secDynamicDb(array $test, string $regionId): bool|string
    {
        $install = $this->api()->post("/api/knative-regions/{$regionId}/data-services/postgres", ['mode' => 'managed']);
        if (! in_array($install->status(), [200, 202], true)) {
            return 'Postgres data service answered '.$install->status().': '.mb_substr($install->body(), 0, 300);
        }
        $deadline = time() + 900;
        while (time() < $deadline) {
            if ($this->api()->get("/api/knative-regions/{$regionId}/data-services")->json('data.services.postgres.status') === 'ready') {
                break;
            }
            sleep(15);
        }

        // Dynamic credentials need the binding's database and role: created first, attached once ready
        $create = $this->api()->post('/api/bindings', ['name' => 'sec-db', 'type' => 'postgres', 'knative_region_id' => $regionId]);
        $bindingId = $create->json('data.id');
        if (! in_array($create->status(), [200, 201, 202], true) || ! is_string($bindingId)) {
            return 'binding answered '.$create->status().': '.mb_substr($create->body(), 0, 300);
        }
        $deadline = time() + 600;
        while (time() < $deadline && $this->api()->get("/api/bindings/{$bindingId}")->json('data.status') !== 'ready') {
            sleep(10);
        }

        $attach = $this->api()->post("/api/projects/{$test['projectId']}/bindings", [
            'binding_id' => $bindingId,
            'env_prefix' => 'DB_',
            'credentials' => 'dynamic',
        ]);
        if ($attach->status() !== 201) {
            return 'attach answered '.$attach->status().': '.mb_substr($attach->body(), 0, 300);
        }

        $redeploy = $test;
        $redeploy['steps']['deploy'] = null;
        $redeploy['steps']['ready'] = null;
        $this->deploySingleTest($redeploy);
        sleep(5);
        $this->waitSingleReady($redeploy);

        // OpenBao's users are named v-<auth>-<role>-…; the binding's own is b_<name>
        $deadline = time() + 300;
        $last = 'no answer';
        while (time() < $deadline) {
            $res = $this->curlFunction($test['slug'], '/', false);
            if (is_array($res)) {
                $body = json_decode((string) $res[2], true);
                $body = is_array($body['body'] ?? null) ? $body['body'] : $body;
                if (($body['db_user_prefix'] ?? null) === 'v-') {
                    return true;
                }
                $last = 'user prefix '.json_encode($body['db_user_prefix'] ?? null);
            }
            sleep(10);
        }

        return "the function has no user from OpenBao ({$last})";
    }

    private function secTeardown(array $test): bool|string
    {
        $delete = $this->api()->delete("/api/projects/{$test['projectId']}");
        if (! $delete->successful()) {
            return 'project delete answered '.$delete->status();
        }

        $deadline = time() + 120;
        while (time() < $deadline) {
            if ($this->e2eK8s->getResourceStatus('v1', 'Secret', "{$test['slug']}-secret-env", $this->e2eNamespace) === null) {
                return true;
            }
            sleep(5);
        }

        return 'the values Secret stays after the project was deleted';
    }

    /**
     * Deploy the project again and poll the function until its answer has the expected digests.
     *
     * @param  array<string, string>  $want  body key => sha256
     */
    private function secRedeployAndSee(array $test, array $want): bool|string
    {
        $redeploy = $test;
        $redeploy['steps']['deploy'] = null;
        $redeploy['steps']['ready'] = null;
        if (! $this->deploySingleTest($redeploy)) {
            return 'deploy: '.$redeploy['steps']['deploy'];
        }
        sleep(5);
        $this->waitSingleReady($redeploy);
        if ($redeploy['steps']['ready'] !== true) {
            return 'ready: '.$redeploy['steps']['ready'];
        }

        $deadline = time() + 180;
        $last = 'no answer';
        while (time() < $deadline) {
            $res = $this->curlFunction($test['slug'], '/', false);
            if (is_array($res)) {
                $body = json_decode((string) $res[2], true);
                $body = is_array($body['body'] ?? null) ? $body['body'] : $body;
                $missing = array_filter($want, fn ($digest, $key) => ($body[$key] ?? null) !== $digest, ARRAY_FILTER_USE_BOTH);
                if ($res[0] === '200' && $missing === []) {
                    return true;
                }
                $last = "HTTP {$res[0]}, not yet: ".implode(', ', array_keys($missing));
            } else {
                $last = $res;
            }
            sleep(5);
        }

        return "the function does not see the values within 180s ({$last})";
    }

    private function secCleanupBao(): void
    {
        try {
            $this->e2eK8s->deleteClusterResource('v1', 'Namespace', self::SEC_BAO_NS);
            $this->e2eK8s->deleteClusterResource('rbac.authorization.k8s.io/v1', 'ClusterRoleBinding', 'runr-e2e-bao-auth-delegator');
        } catch (\Throwable) {
            // best effort: the Kind cluster goes with the run
        }
        $this->secValues = [];
    }
}
