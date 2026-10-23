<?php

namespace App\Console\Commands\Concerns;

use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\RegionSecretSetting;
use App\Services\KubernetesClient;
use App\Services\Secrets\External\ExternalSecretManifests;
use App\Services\Secrets\OpenBao\OpenBaoAccess;
use App\Services\Secrets\OpenBaoClients;
use Illuminate\Support\Facades\Http;

/**
 * The secret values of a region's projects in a move to another cluster and its rollback (test:e2e
 * --suite=region-move, E2eTestRegionMoveTrait; docs/regions.md#moving-a-region-to-another-cluster,
 * docs/secret-stores.md "OpenBao in the region" → Operations). RunR keeps no copy of a value (the
 * e2e installation is a fresh one, finalized from the start — RollbackCopies): the cluster the
 * region runs on has the only one, so the move copies them to the new cluster and the rollback
 * copies them back. One more project on A, rm-secrets — a Python function that answers
 * the SHA-256 of APP_SECRET, NEW_SECRET, EXT_SECRET and BAO_SECRET; the values are random, in this
 * run's memory only, never printed:
 *
 *  - rm-sec-setup             before the move, on A: APP_SECRET in the region's Secret
 *                             <slug>-secret-env (set before OpenBao runs), External Secrets Operator
 *                             installed, EXT_SECRET a reference to a store — an OpenBao dev server
 *                             with Kubernetes auth, a copy of it on each cluster at the same address
 *                             (a store both clusters trust) —, the region's OpenBao installed and
 *                             BAO_SECRET in it; the function on A sees the three
 *  - rm-move-secrets          after the move, on B: the values Secret holds APP_SECRET, B runs an
 *                             OpenBao of its own (another unseal key, another CA) that holds
 *                             BAO_SECRET — read back through the API —, the ExternalSecrets of the
 *                             reference and of the OpenBao values and the store's SecretStore are
 *                             there, the function on B sees the three values; A's values Secret is
 *                             as it was
 *  - rm-secrets-on-b          values set while the region runs on B (new values default to the
 *                             Kubernetes Secret for a moment, then to OpenBao again): APP_SECRET
 *                             changed and NEW_SECRET in B's values Secret, BAO_SECRET changed in
 *                             B's OpenBao — A's values Secret and A's OpenBao keep the old ones
 *  - rm-rollback-secrets      after the rollback, on A: the values set meanwhile were copied back —
 *                             A's values Secret holds the new APP_SECRET and NEW_SECRET, A's OpenBao
 *                             the new BAO_SECRET (read back through the API) —, the region has A's
 *                             OpenBao CA again, and the function the rollback deployed on A sees the
 *                             new values and the reference
 *  - rm-rollback-secrets-left B, after RemoveLeftClusterWorkloadsJob: no values Secret, no
 *                             <slug>-ext-env / <slug>-bao-env with their ExternalSecrets, and nothing
 *                             of the project in B's OpenBao (its values, policy and role); the store's
 *                             SecretStore and B's OpenBao install stay
 *
 * The secret cases do not hold up the rest of the suite: when rm-sec-setup fails, rm-secrets is
 * stopped (a stopped project is not deployed by the move) and the other secret cases are skipped.
 */
trait E2eTestRegionMoveSecretsTrait
{
    private const RM_SECRETS_PYTHON = <<<'PY'
import hashlib, os


def handler(event, context):
    def digest(name):
        value = os.environ.get(name)
        return hashlib.sha256(value.encode()).hexdigest() if value is not None else None
    return {"statusCode": 200, "body": {"suite": "region-move-secrets", "app": digest("APP_SECRET"), "new": digest("NEW_SECRET"),
                                        "ext": digest("EXT_SECRET"), "bao": digest("BAO_SECRET")}}
PY;

    /** The store's namespace on both clusters: its copies answer at the same in-cluster address */
    private const RM_BAO_NS = 'runr-e2e-rm-bao';

    private const RM_BAO_IMAGE = 'ghcr.io/brf-tech/runr/reqs/openbao:2.4.1';

    private function rmSecSetup(array &$c): bool|string
    {
        $c['sec'] = [];
        foreach (['APP_SECRET', 'APP_SECRET_B', 'NEW_SECRET', 'EXT_SECRET', 'BAO_SECRET', 'BAO_SECRET_B', 'root'] as $key) {
            $c['sec'][$key] = bin2hex(random_bytes(16));
        }
        $c['kubeconfigA'] = (string) KnativeRegion::query()->findOrFail($c['regionId'])->kubeconfig;

        $list = [$this->makeTestEntry('rm-secrets', [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'http',
            'code' => self::RM_SECRETS_PYTHON,
        ], $c['regionId'])];
        $this->runTestsSequentially($list);
        $entry = $list[0];
        if ($entry['steps']['ready'] !== true) {
            return 'rm-secrets is not deployed on A: '.json_encode($entry['steps']);
        }
        // The project runs on A from here on: the move moves it
        $c['secrets'] = $entry;
        $id = $entry['projectId'];

        // Before OpenBao runs: the value goes into the region's Secret
        $put = $this->rmPutSecret($id, 'APP_SECRET', $c['sec']['APP_SECRET'], 'region');
        if ($put !== true) {
            return $put;
        }

        $eso = $this->secInstallOperator($c['regionId']);
        if ($eso !== true) {
            return "External Secrets Operator on A: {$eso}";
        }

        $store = $this->api()->post('/api/secret-stores', [
            'name' => 'rm-e2e-bao',
            'provider' => 'vault',
            'auth' => 'kubernetes',
            'settings' => ['server' => 'http://bao.'.self::RM_BAO_NS.'.svc:8200', 'role' => 'runr-e2e', 'mount' => 'secret', 'kv_version' => 'v2'],
            'refresh_interval' => 60,
        ]);
        if ($store->status() !== 201) {
            return 'the store answered '.$store->status().': '.mb_substr($store->body(), 0, 300);
        }
        $c['storeId'] = (string) $store->json('data.id');
        $c['storeAccount'] = 'runr-ss-'.strtolower($c['storeId']);
        foreach (['A' => $c['a'], 'B' => $c['b']] as $cluster => $k8s) {
            $ready = $this->rmDevStore($k8s, $c['storeAccount'], $c['namespace'], $c['sec']);
            if ($ready !== true) {
                return "the store's copy on {$cluster}: {$ready}";
            }
        }
        $verify = $this->api()->post("/api/secret-stores/{$c['storeId']}/verify", ['region_id' => $c['regionId']]);
        $deadline = time() + 120;
        while ($verify->json('data.status') === 'pending' && time() < $deadline) {
            sleep(5);
            $verify = $this->api()->post("/api/secret-stores/{$c['storeId']}/verify", ['region_id' => $c['regionId']]);
        }
        if ($verify->json('data.status') !== 'ready') {
            return 'verifying the store on A answered '.$verify->status().' '.$verify->json('data.status').': '.$verify->json('data.message');
        }

        $reference = $this->api()->put("/api/projects/{$id}/secrets/EXT_SECRET", [
            'reference' => ['store_id' => $c['storeId'], 'path' => 'e2e/app', 'key' => 'token'],
        ]);
        if ($reference->status() !== 201 || $reference->json('data.source') !== 'external') {
            return 'the reference answered '.$reference->status().': '.$reference->json('message');
        }

        $bao = $this->secInstallOpenBao($c['regionId']);
        if ($bao !== true) {
            return "OpenBao on A: {$bao}";
        }
        $put = $this->rmPutSecret($id, 'BAO_SECRET', $c['sec']['BAO_SECRET'], 'openbao');
        if ($put !== true) {
            return $put;
        }
        $c['baoCaA'] = (string) RegionSecretSetting::forRegion(KnativeRegion::query()->findOrFail($c['regionId']))->openbao_ca;
        $c['unsealA'] = $this->rmUnsealKey($c['a']);

        $redeploy = $entry;
        $redeploy['steps']['deploy'] = null;
        $redeploy['steps']['ready'] = null;
        if (! $this->deploySingleTest($redeploy)) {
            return 'deploy on A: '.$redeploy['steps']['deploy'];
        }
        sleep(5);
        $this->waitSingleReady($redeploy);
        if ($redeploy['steps']['ready'] !== true) {
            return 'ready on A: '.$redeploy['steps']['ready'];
        }
        $seen = $this->rmSecretsSeen($c['ipA'], $entry, $this->rmDigests($c, ['app' => 'APP_SECRET', 'ext' => 'EXT_SECRET', 'bao' => 'BAO_SECRET']));
        if ($seen !== true) {
            return "on A: {$seen}";
        }

        $c['secretsReady'] = true;
        $this->line('    rm-secrets on A: a value in the region\'s Secret, a reference, a value in OpenBao');

        return true;
    }

    /** A failed setup: rm-secrets stays out of the move (stopped: the move does not deploy it). */
    private function rmSecretsAside(array &$c): void
    {
        if (! isset($c['secrets'])) {
            return;
        }
        $stop = $this->api()->post("/api/projects/{$c['secrets']['projectId']}/stop", ['reason' => 'e2e: rm-sec-setup failed, kept out of the region move']);
        $this->line('    rm-secrets stopped ('.$stop->status().'): the secret cases are skipped');
        unset($c['secrets']);
    }

    private function rmMoveSecrets(array $c): bool|string
    {
        if ($c['ipB'] === '') {
            return "B's Traefik address is unknown (rm-move)";
        }
        $test = $c['secrets'];
        $problems = [];

        // The values reach the function on B: copied before the move deployed it there, the
        // reference read by B's operator from the store's copy on B
        $seen = $this->rmSecretsSeen($c['ipB'], $test, $this->rmDigests($c, ['app' => 'APP_SECRET', 'ext' => 'EXT_SECRET', 'bao' => 'BAO_SECRET']), 300);
        if ($seen !== true) {
            $problems[] = "on B: {$seen}";
        }

        if ($this->rmSecretValue($c['b'], "{$test['slug']}-secret-env", $c['namespace'], 'APP_SECRET') !== $c['sec']['APP_SECRET']) {
            $problems[] = "B's values Secret {$test['slug']}-secret-env does not hold APP_SECRET";
        }
        if ($this->rmSecretValue($c['a'], "{$test['slug']}-secret-env", $c['namespace'], 'APP_SECRET') !== $c['sec']['APP_SECRET']) {
            $problems[] = "A's values Secret changed during the move (the move removes nothing there)";
        }

        $settings = RegionSecretSetting::forRegion(KnativeRegion::query()->findOrFail($c['regionId']));
        if (! $settings->openbaoReady() || ! $settings->esoReady()) {
            $problems[] = "the region's OpenBao is {$settings->openbao_status}, its operator {$settings->eso_status} (want both ready on B)";
        }
        if ((string) $settings->openbao_ca === '' || (string) $settings->openbao_ca === $c['baoCaA']) {
            $problems[] = 'the region keeps A\'s OpenBao CA (want the CA of the instance installed on B)';
        }
        $unsealB = $this->rmUnsealKey($c['b']);
        if ($unsealB === null || $unsealB === $c['unsealA']) {
            $problems[] = $unsealB === null ? 'B has no runr-openbao-unseal' : 'B\'s OpenBao has A\'s unseal key (want a new instance with its own)';
        }

        $read = $this->api()->get("/api/projects/{$test['projectId']}/secrets/BAO_SECRET/value");
        if ($read->status() !== 200 || ! hash_equals($c['sec']['BAO_SECRET'], (string) $read->json('value')) || $read->json('matches_fingerprint') !== true) {
            $problems[] = 'reading BAO_SECRET back from B\'s OpenBao answered '.$read->status().' (want the value, matches_fingerprint true)';
        }

        foreach (["{$test['slug']}-ext-env", "{$test['slug']}-bao-env"] as $name) {
            if ($c['b']->getResourceStatus(ExternalSecretManifests::API_VERSION, 'ExternalSecret', $name, $c['namespace']) === null) {
                $problems[] = "no ExternalSecret {$name} on B";
            }
        }
        foreach ([$c['storeAccount'], OpenBaoAccess::accountName($this->rmProject($test))] as $name) {
            if ($c['b']->getResourceStatus(ExternalSecretManifests::API_VERSION, 'SecretStore', $name, $c['namespace']) === null) {
                $problems[] = "no SecretStore {$name} on B";
            }
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    /**
     * Values set while the region runs on B are on B only: APP_SECRET and the new NEW_SECRET in
     * B's values Secret (new values default to the Kubernetes Secret while they are set), BAO_SECRET
     * in B's OpenBao.
     */
    private function rmSecretsOnB(array $c): bool|string
    {
        $id = $c['secrets']['projectId'];
        $slug = $c['secrets']['slug'];
        $openbao = "/api/knative-regions/{$c['regionId']}/openbao";

        $off = $this->api()->patch($openbao, ['default_for_values' => false]);
        if ($off->status() !== 200 || $off->json('data.default_for_values') !== false) {
            return 'PATCH default_for_values false answered '.$off->status().': '.mb_substr($off->body(), 0, 200);
        }
        try {
            foreach (['APP_SECRET' => 'APP_SECRET_B', 'NEW_SECRET' => 'NEW_SECRET'] as $name => $key) {
                $put = $this->rmPutSecret($id, $name, $c['sec'][$key], 'region');
                if ($put !== true) {
                    return $put;
                }
            }
        } finally {
            $on = $this->api()->patch($openbao, ['default_for_values' => true]);
        }
        if ($on->status() !== 200 || $on->json('data.default_for_values') !== true) {
            return 'PATCH default_for_values true answered '.$on->status().': '.mb_substr($on->body(), 0, 200);
        }
        $put = $this->rmPutSecret($id, 'BAO_SECRET', $c['sec']['BAO_SECRET_B'], 'openbao');
        if ($put !== true) {
            return $put;
        }

        $problems = [];
        foreach (['APP_SECRET' => 'APP_SECRET_B', 'NEW_SECRET' => 'NEW_SECRET'] as $name => $key) {
            if ($this->rmSecretValue($c['b'], "{$slug}-secret-env", $c['namespace'], $name) !== $c['sec'][$key]) {
                $problems[] = "B's values Secret does not hold the new {$name}";
            }
        }
        if ($this->rmSecretValue($c['a'], "{$slug}-secret-env", $c['namespace'], 'APP_SECRET') !== $c['sec']['APP_SECRET']
            || $this->rmSecretValue($c['a'], "{$slug}-secret-env", $c['namespace'], 'NEW_SECRET') !== null) {
            $problems[] = "A's values Secret changed while the region ran on B";
        }
        $read = $this->api()->get("/api/projects/{$id}/secrets/BAO_SECRET/value");
        if ($read->status() !== 200 || ! hash_equals($c['sec']['BAO_SECRET_B'], (string) $read->json('value'))) {
            $problems[] = 'reading the new BAO_SECRET back from B\'s OpenBao answered '.$read->status();
        }
        try {
            $onA = app(OpenBaoClients::class)->forKubeconfig($c['kubeconfigA'], 'A')->kvRead(OpenBaoAccess::projectPath($this->rmProject($c['secrets']))) ?? [];
            if (! hash_equals($c['sec']['BAO_SECRET'], (string) ($onA['BAO_SECRET'] ?? ''))) {
                $problems[] = 'A\'s OpenBao does not hold the old BAO_SECRET any more';
            }
        } catch (\Throwable $e) {
            $problems[] = 'A\'s OpenBao does not answer: '.mb_substr($e->getMessage(), 0, 200);
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    private function rmRollbackSecrets(array $c): bool|string
    {
        $test = $c['secrets'];
        $problems = [];

        // The rollback deployed it on A again, after it copied the values back
        $deadline = time() + $this->timeout;
        do {
            $live = Deployment::query()->where('project_id', $test['projectId'])->whereIn('status', Deployment::LIVE)->latest('created_at')->get();
            $deployment = $live->first();
            if ($live->count() === 1 && $deployment->status === 'active' && $deployment->created_at >= $c['rolledBackAt']) {
                break;
            }
            sleep(5);
        } while (time() < $deadline);
        if ($live->count() !== 1 || $deployment->status !== 'active' || $deployment->created_at < $c['rolledBackAt']) {
            return 'rm-secrets: live deployments '.json_encode($live->map->only(['status', 'deployed_by', 'created_at', 'knative_message'])->all())
                .' (want one, active, deployed by the rollback)';
        }

        foreach (['APP_SECRET' => 'APP_SECRET_B', 'NEW_SECRET' => 'NEW_SECRET'] as $name => $key) {
            if ($this->rmSecretValue($c['a'], "{$test['slug']}-secret-env", $c['namespace'], $name) !== $c['sec'][$key]) {
                $problems[] = "A's values Secret does not hold the {$name} set on B";
            }
        }
        $read = $this->api()->get("/api/projects/{$test['projectId']}/secrets/BAO_SECRET/value");
        if ($read->status() !== 200 || ! hash_equals($c['sec']['BAO_SECRET_B'], (string) $read->json('value')) || $read->json('matches_fingerprint') !== true) {
            $problems[] = 'reading BAO_SECRET back from A\'s OpenBao answered '.$read->status().' (want the value set on B, matches_fingerprint true)';
        }

        $settings = RegionSecretSetting::forRegion(KnativeRegion::query()->findOrFail($c['regionId']));
        if ((string) $settings->openbao_ca !== $c['baoCaA'] || ! $settings->openbaoReady() || ! $settings->esoReady()) {
            $problems[] = "the region's OpenBao is {$settings->openbao_status}, its operator {$settings->eso_status}, with ".((string) $settings->openbao_ca === $c['baoCaA'] ? 'A\'s CA' : 'another CA than A\'s').' (want both ready, A\'s CA)';
        }

        $seen = $this->rmSecretsSeen($c['ipA'], $test, $this->rmDigests($c, ['app' => 'APP_SECRET_B', 'new' => 'NEW_SECRET', 'ext' => 'EXT_SECRET', 'bao' => 'BAO_SECRET_B']));
        if ($seen !== true) {
            $problems[] = "on A: {$seen}";
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    /**
     * B after the rollback (RemoveLeftClusterWorkloadsJob, in the background): nothing of
     * rm-secrets' values is left — its values Secret, the Secrets of its reference and of its
     * OpenBao values with their ExternalSecrets, its values, policy and role in B's OpenBao. The
     * store's SecretStore and B's OpenBao itself stay (docs/regions.md, what stays).
     */
    private function rmRollbackSecretsLeft(array $c): bool|string
    {
        $test = $c['secrets'];
        $slug = $test['slug'];
        $objects = [
            ['v1', 'Secret', "{$slug}-secret-env"],
            ['v1', 'Secret', "{$slug}-ext-env"],
            ['v1', 'Secret', "{$slug}-bao-env"],
            [ExternalSecretManifests::API_VERSION, 'ExternalSecret', "{$slug}-ext-env"],
            [ExternalSecretManifests::API_VERSION, 'ExternalSecret', "{$slug}-bao-env"],
        ];
        $left = function () use ($c, $objects): array {
            $names = [];
            foreach ($objects as [$apiVersion, $kind, $name]) {
                if ($c['b']->getResourceStatus($apiVersion, $kind, $name, $c['namespace']) !== null) {
                    $names[] = "{$kind} {$name}";
                }
            }

            return $names;
        };
        $deadline = time() + 300;
        while (($still = $left()) !== [] && time() < $deadline) {
            sleep(5);
        }

        $problems = [];
        if ($still !== []) {
            $problems[] = 'still on B 300 s after the rollback: '.implode(', ', $still);
        }

        $project = $this->rmProject($test);
        try {
            $bao = app(OpenBaoClients::class)->forKubeconfig($c['target'], 'B');
            if ($bao->kvRead(OpenBaoAccess::projectPath($project)) !== null) {
                $problems[] = 'B\'s OpenBao still holds the project\'s values';
            }
            if ($bao->read('auth/kubernetes/role/'.OpenBaoAccess::projectName($project)) !== null) {
                $problems[] = 'B\'s OpenBao still has the project\'s role';
            }
            if ($bao->read('sys/policies/acl/'.OpenBaoAccess::projectName($project)) !== null) {
                $problems[] = 'B\'s OpenBao still has the project\'s policy';
            }
        } catch (\Throwable $e) {
            $problems[] = 'B\'s OpenBao does not answer: '.mb_substr($e->getMessage(), 0, 200);
        }

        if ($c['b']->getResourceStatus(ExternalSecretManifests::API_VERSION, 'SecretStore', $c['storeAccount'], $c['namespace']) === null) {
            $problems[] = "the store's SecretStore {$c['storeAccount']} left B (it stays with the operator)";
        }
        if ($c['b']->getResourceStatus('apps/v1', 'StatefulSet', 'openbao', 'runr-openbao') === null) {
            $problems[] = 'B\'s OpenBao install is gone (it stays)';
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    // ── Helpers ──

    /** PUT a value and check where RunR says it lives. */
    private function rmPutSecret(string $projectId, string $name, string $value, string $storedIn): bool|string
    {
        $put = $this->api()->put("/api/projects/{$projectId}/secrets/{$name}", ['value' => $value]);
        if (! in_array($put->status(), [200, 201], true) || $put->json('data.stored_in') !== $storedIn) {
            return "PUT {$name} answered ".$put->status().' stored_in '.json_encode($put->json('data.stored_in'))." (want {$storedIn}): ".mb_substr((string) $put->json('message'), 0, 200);
        }

        return true;
    }

    /**
     * The store's copy on one cluster: an OpenBao dev server (in memory, the root token known to
     * this run only) at bao.<RM_BAO_NS>.svc:8200 with Kubernetes auth against its own cluster
     * (TokenReview, system:auth-delegator), the role bound to the store's service account in the
     * project namespace, and the KV secret e2e/app with token = EXT_SECRET.
     *
     * @param  array<string, string>  $sec
     */
    private function rmDevStore(KubernetesClient $k8s, string $account, string $namespace, array $sec): bool|string
    {
        $ns = self::RM_BAO_NS;
        $k8s->applyClusterResource(['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => $ns]]);
        $k8s->applyResource(['apiVersion' => 'v1', 'kind' => 'ServiceAccount', 'metadata' => ['name' => 'bao', 'namespace' => $ns]], $ns);
        $k8s->applyClusterResource([
            'apiVersion' => 'rbac.authorization.k8s.io/v1', 'kind' => 'ClusterRoleBinding',
            'metadata' => ['name' => 'runr-e2e-rm-bao-auth-delegator'],
            'roleRef' => ['apiGroup' => 'rbac.authorization.k8s.io', 'kind' => 'ClusterRole', 'name' => 'system:auth-delegator'],
            'subjects' => [['kind' => 'ServiceAccount', 'name' => 'bao', 'namespace' => $ns]],
        ]);
        $k8s->applyResource(['apiVersion' => 'v1', 'kind' => 'Secret', 'metadata' => ['name' => 'bao-e2e', 'namespace' => $ns],
            'type' => 'Opaque', 'data' => ['root' => base64_encode($sec['root']), 'ext' => base64_encode($sec['EXT_SECRET'])]], $ns);
        $k8s->applyResource([
            'apiVersion' => 'v1', 'kind' => 'Pod',
            'metadata' => ['name' => 'bao', 'namespace' => $ns, 'labels' => ['app' => 'bao']],
            'spec' => [
                'serviceAccountName' => 'bao',
                'containers' => [[
                    'name' => 'bao', 'image' => self::RM_BAO_IMAGE,
                    'command' => ['bao', 'server', '-dev', '-dev-listen-address=0.0.0.0:8200'],
                    'env' => [
                        ['name' => 'BAO_DEV_ROOT_TOKEN_ID', 'valueFrom' => ['secretKeyRef' => ['name' => 'bao-e2e', 'key' => 'root']]],
                        ['name' => 'VAULT_DEV_ROOT_TOKEN_ID', 'valueFrom' => ['secretKeyRef' => ['name' => 'bao-e2e', 'key' => 'root']]],
                    ],
                    'ports' => [['containerPort' => 8200]],
                ]],
            ],
        ], $ns);
        $k8s->applyResource(['apiVersion' => 'v1', 'kind' => 'Service', 'metadata' => ['name' => 'bao', 'namespace' => $ns],
            'spec' => ['selector' => ['app' => 'bao'], 'ports' => [['port' => 8200, 'targetPort' => 8200]]]], $ns);

        // A server: running, not done
        $running = $this->waitForServerPod($k8s, $ns, 'bao');
        if ($running !== true) {
            return "the OpenBao dev server did not start: {$running}";
        }

        $script = implode(' && ', [
            'export BAO_ADDR=http://bao:8200 BAO_TOKEN=$(cat /e2e/root)',
            'until bao status >/dev/null 2>&1; do sleep 2; done',
            'bao auth enable kubernetes',
            'bao write auth/kubernetes/config kubernetes_host=https://kubernetes.default.svc',
            'printf \'path "secret/data/e2e/*" { capabilities = ["read"] }\' | bao policy write runr-e2e -',
            "bao write auth/kubernetes/role/runr-e2e bound_service_account_names={$account} bound_service_account_namespaces={$namespace} policies=runr-e2e ttl=10m",
            'bao kv put secret/e2e/app token="$(cat /e2e/ext)" >/dev/null',
        ]);
        $k8s->applyResource([
            'apiVersion' => 'v1', 'kind' => 'Pod',
            'metadata' => ['name' => 'bao-config', 'namespace' => $ns],
            'spec' => [
                'restartPolicy' => 'Never',
                'containers' => [[
                    'name' => 'config', 'image' => self::RM_BAO_IMAGE,
                    'command' => ['sh', '-c', $script],
                    'volumeMounts' => [['name' => 'e2e', 'mountPath' => '/e2e', 'readOnly' => true]],
                ]],
                'volumes' => [['name' => 'e2e', 'secret' => ['secretName' => 'bao-e2e']]],
            ],
        ], $ns);
        // the same image, maybe pulled on another node
        $phase = $this->waitForPods($k8s, $ns, ['bao-config'], self::E2E_SERVER_POD_START_SECONDS)['bao-config'] ?? 'Timeout';

        return $phase === 'Succeeded'
            ? true
            : "its configuration {$phase}: ".mb_substr((string) $k8s->getPodLogs('bao-config', $ns, 'config', 30), 0, 300)
                .' — '.$this->podDiagnosisOf($k8s, $ns, 'bao-config');
    }

    /** The dev stores leave both clusters; the values leave this run's memory. */
    private function rmSecretsCleanup(array &$c): void
    {
        if (isset($c['storeId'])) {
            foreach (['a', 'b'] as $cluster) {
                try {
                    $c[$cluster]->deleteClusterResource('v1', 'Namespace', self::RM_BAO_NS);
                    $c[$cluster]->deleteClusterResource('rbac.authorization.k8s.io/v1', 'ClusterRoleBinding', 'runr-e2e-rm-bao-auth-delegator');
                } catch (\Throwable) {
                    // best effort: the Kind clusters go with the run
                }
            }
        }
        $c['sec'] = [];
    }

    /**
     * Polls the function through a Traefik address with its host <slug>.<function domain> until
     * it answers with the wanted digests. True, or the last answer (never a value).
     *
     * @param  array<string, string>  $want  body key => sha256
     */
    private function rmSecretsSeen(string $ip, array $test, array $want, int $timeout = 240): bool|string
    {
        $domain = KnativeRegion::query()->find($test['regionId'])?->effective_settings['knative_domain'] ?? null;
        if ($ip === '' || ! $domain) {
            return "{$test['name']}: no address ({$ip}) or function domain (".json_encode($domain).') to call it with';
        }
        $host = "{$test['slug']}.{$domain}";

        $deadline = time() + $timeout;
        $last = 'no answer';
        while (true) {
            try {
                $response = Http::timeout(20)->withHeaders(['Host' => $host])->get("http://{$ip}/");
                $body = $response->json();
                $body = is_array($body['body'] ?? null) ? $body['body'] : $body;
                $missing = array_keys(array_filter($want, fn (string $digest, string $key) => ! is_array($body) || ($body[$key] ?? null) !== $digest, ARRAY_FILTER_USE_BOTH));
                if ($response->status() === 200 && $missing === []) {
                    return true;
                }
                // The keys only: the answer carries digests of the values
                $last = 'HTTP '.$response->status().($response->status() === 200 ? ', not (yet) the wanted '.implode(', ', $missing) : ' '.mb_substr(trim($response->body()), 0, 160));
            } catch (\Throwable $e) {
                $last = mb_substr($e->getMessage(), 0, 200);
            }
            if (time() >= $deadline) {
                break;
            }
            sleep(5);
        }

        return "{$host} at {$ip}: want the digests of ".implode(', ', array_keys($want))." within {$timeout} s, last: {$last}";
    }

    /**
     * @param  array<string, string>  $keys  body key => key of $c['sec']
     * @return array<string, string> body key => sha256 of the value
     */
    private function rmDigests(array $c, array $keys): array
    {
        return array_map(fn (string $key) => hash('sha256', $c['sec'][$key]), $keys);
    }

    /** One key of a Secret on a cluster, decoded; null when the Secret or the key is not there. */
    private function rmSecretValue(KubernetesClient $k8s, string $name, string $namespace, string $key): ?string
    {
        $secret = $k8s->getResourceStatus('v1', 'Secret', $name, $namespace);
        $data = $secret['data'][$key] ?? null;

        return is_string($data) ? (string) base64_decode($data) : null;
    }

    /** A digest of the cluster's OpenBao unseal key (null: none there) — compared, never printed. */
    private function rmUnsealKey(KubernetesClient $k8s): ?string
    {
        $secret = $k8s->getResourceStatus('v1', 'Secret', 'runr-openbao-unseal', 'runr-openbao');

        return is_array($secret['data'] ?? null) ? hash('sha256', (string) json_encode($secret['data'])) : null;
    }

    private function rmProject(array $test): Project
    {
        return Project::query()->findOrFail($test['projectId']);
    }
}
