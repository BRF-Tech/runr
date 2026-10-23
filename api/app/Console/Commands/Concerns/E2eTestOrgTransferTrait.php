<?php

namespace App\Console\Commands\Concerns;

use App\Models\Binding;
use App\Models\Project;
use App\Models\User;
use App\Services\Bindings\BindingDrivers;
use App\Services\Bindings\DataServices\KvService;
use App\Services\Bindings\Kv\RedisScripts;
use App\Services\Bindings\Objects\Rclone;
use App\Services\Bindings\Ops\BindingOpsRunner;
use App\Services\Bindings\Ops\OpsTask;
use App\Services\GatewayService;
use Illuminate\Support\Facades\Http;

/**
 * Organization export / import on Kind (docs/org-export.md; `ci.sh test-org-transfer`,
 * --suite=org-transfer): an organization with a deployed function (two versions, a secret, a
 * custom domain) and a Postgres, a KV and an object binding with data is exported through the API
 * — dumps and the image made by transfer agent Jobs in the region — the package downloaded
 * (checksum, ranges), uploaded again in chunks, planned (slug and binding name collisions: the Kind
 * stack is single-tenant, so "the other organization" is this one with renamed copies; the
 * tenant-to-tenant import runs in test:e2e-tenancy) and applied with data restore, images and
 * deploy. The copies must hold the source's data and the copied function must answer.
 *
 * The export carries the run history (include_history): the plan shows it per project, the copy
 * gets the source's deployments as imported history that is never live. The source function has a
 * key route on the region's gateway (auth_mode key): the import creates the copy's route as a key
 * route — no call without a key reaches the copy from its creation on (401, or 503 while the
 * region's gateway-auth is not ready: closed). In this single-tenant copy the importing member's
 * gateway is the source's, so the source route is removed before the apply to free its path. The KV dump records its
 * Redis version, which the plan shows next to the target's — the same Redis here, so nothing
 * blocks. Last, a second export of the source function goes into the object binding and an import
 * reads it straight from there (source binding): fetched in ranges through the API server,
 * verified and planned like an upload, then cancelled.
 */
trait E2eTestOrgTransferTrait
{
    private const OT_PASSPHRASE = 'e2e org transfer passphrase';

    private const OT_HANDLER = <<<'PY'
def handler(event, context=None):
    return {"statusCode": 200, "body": "org-transfer-ok"}
PY;

    /**
     * A dependency file: the source's versions are built into images, which the export takes (a
     * handler without dependencies runs on the shared executor image and has no image to export)
     */
    private const OT_REQUIREMENTS = "six==1.16.0\n";

    private function runOrgTransferSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Organization export / import ──');
        $tests = [];
        /** @var array{export?: array<string, mixed>, file?: string, import?: array<string, mixed>, renames?: array<string, string>, binding_renames?: array<string, string>, applied?: array<string, mixed>} $state */
        $state = [];

        // 1. Data services and one binding of each kind with data
        foreach ([
            'kv' => ['mode' => 'managed', 'maxmemory_mb' => 128, 'storage' => '256Mi'],
            'object' => ['mode' => 'managed', 'storage' => '1Gi'],
            'postgres' => ['instances' => 1, 'storage' => '1Gi'],
        ] as $service => $options) {
            $tests[] = $this->otCheck("ot-service-{$service}", $regionId, fn () => $this->installDataService($regionId, $service, $options));
        }
        foreach (['ot-db' => ['type' => 'postgres', 'plan' => 'shared', 'settings' => ['storage_gb' => 1]], 'ot-cache' => ['type' => 'kv', 'settings' => ['memory_mb' => 16]], 'ot-files' => ['type' => 'object', 'settings' => ['quota_gb' => 1]]] as $name => $payload) {
            $tests[] = $this->otCheck("ot-binding-{$name}", $regionId, fn () => $this->createBinding($regionId, $name, $payload));
        }
        if ($this->otFailed($tests)) {
            return $tests;
        }
        $tests[] = $this->otCheck('ot-seed-data', $regionId, fn () => $this->otSeed());

        // 2. The source function: deployed, a second version, a secret, a domain, the db attached
        $function = $this->makeTestEntry('ot-source', [
            'service_type' => 'faas', 'runtime' => 'python', 'trigger_type' => 'http',
            'faas_files' => [['name' => 'main.py', 'content' => self::OT_HANDLER], ['name' => 'requirements.txt', 'content' => self::OT_REQUIREMENTS]],
        ], $regionId);
        $deployed = [$function];
        $this->runTestsSequentially($deployed);
        $function = $deployed[0];
        $function['steps']['verify'] = $function['steps']['ready'] === true ? $this->otDecorate($function) : 'skipped';
        $tests[] = $this->finishTest($function);
        if ($this->otFailed($tests)) {
            return $tests;
        }
        $tests[] = $this->otCheck('ot-gateway-route', $regionId, function () use (&$state, $function) {
            return $this->otKeyRoute($function, $state);
        });

        // 3. Export with data, images and sealed secrets
        $tests[] = $this->otCheck('ot-export', $regionId, function () use (&$state) {
            $res = $this->api()->post('/api/org-transfers/export', [
                'binding_data' => 'all', 'include_images' => true, 'include_members' => true,
                'include_secrets' => true, 'passphrase' => self::OT_PASSPHRASE, 'include_history' => true,
            ]);
            if ($res->status() !== 202) {
                return "start: HTTP {$res->status()} ".mb_substr($res->body(), 0, 300);
            }
            $done = $this->otWait((string) $res->json('data.id'), ['completed', 'failed', 'cancelled'], 1800);
            if (! is_array($done)) {
                return $done;
            }
            if ($done['status'] !== 'completed') {
                return "export {$done['status']}: ".($done['message'] ?? '');
            }
            if (($done['report']['contents']['binding_data'] ?? 0) !== 3) {
                return 'binding dumps: '.json_encode($done['report']['skipped'] ?? []);
            }
            if (($done['report']['contents']['images'] ?? 0) < 1) {
                return 'no image exported: '.json_encode($done['report']['skipped'] ?? []);
            }
            if (($done['report']['contents']['history_deployments'] ?? 0) < 1) {
                return 'no deployment history exported: '.json_encode($done['report']['contents'] ?? []);
            }
            if (! empty($state['route']) && ($done['report']['contents']['gateways'] ?? 0) < 1) {
                return 'the gateway with the key route not exported: '.json_encode($done['report']['contents'] ?? []);
            }
            $state['export'] = $done;

            return true;
        });

        // 4. Download (whole, checksum) and a range
        $tests[] = $this->otCheck('ot-download', $regionId, function () use (&$state) {
            $export = $state['export'] ?? null;
            if (! $export) {
                return 'skipped: no export';
            }
            $file = sys_get_temp_dir().'/runr-e2e-org-package.tar.zst';
            @unlink($file);
            $res = $this->api()->withOptions(['sink' => $file])->timeout(600)->get("/api/org-transfers/{$export['id']}/download");
            if ($res->status() !== 200) {
                return "download: HTTP {$res->status()}";
            }
            if (hash_file('sha256', $file) !== $export['package']['sha256']) {
                return 'downloaded package does not match its SHA-256';
            }
            $range = $this->api()->withHeaders(['Range' => 'bytes=10-'])->get("/api/org-transfers/{$export['id']}/download");
            if ($range->status() !== 206 || strlen($range->body()) !== filesize($file) - 10) {
                return "range: HTTP {$range->status()}, ".strlen($range->body()).' bytes';
            }
            $state['file'] = $file;

            return true;
        });

        // 5. Upload in chunks (resumed once), verify, plan: collisions block
        $tests[] = $this->otCheck('ot-upload-plan', $regionId, function () use (&$state) {
            if (empty($state['file'])) {
                return 'skipped: no package';
            }
            $file = $state['file'];
            $start = $this->api()->post('/api/org-transfers/import', ['filename' => basename($file), 'size' => filesize($file), 'sha256' => hash_file('sha256', $file)]);
            if ($start->status() !== 201) {
                return "import: HTTP {$start->status()} ".mb_substr($start->body(), 0, 300);
            }
            $id = (string) $start->json('data.id');
            $chunk = (int) $start->json('data.upload.chunk_size');
            if (($sent = $this->otUpload($id, $file, $chunk)) !== true) {
                return $sent;
            }
            $complete = $this->api()->post("/api/org-transfers/{$id}/complete");
            if ($complete->status() !== 202) {
                return "complete: HTTP {$complete->status()} ".mb_substr($complete->body(), 0, 300);
            }
            $planned = $this->otWait($id, ['planned', 'failed'], 600);
            if (! is_array($planned) || $planned['status'] !== 'planned') {
                return is_array($planned) ? 'verify: '.($planned['message'] ?? '') : $planned;
            }
            $codes = array_merge(...array_map(fn ($p) => array_column($p['issues'] ?? [], 'code'), $planned['plan']['projects']));
            if (! in_array('slug_taken', $codes, true) || $planned['plan']['can_apply']) {
                return 'the plan should block on the slug collision: '.json_encode($planned['plan']['projects']);
            }
            // The run history per project, and the KV dump's Redis version next to the target's
            $history = collect($planned['plan']['history']['projects'] ?? [])->first(fn ($p) => ($p['deployments'] ?? 0) > 0);
            if (! $history || ($history['deployments'] ?? 0) < 1) {
                return 'the plan shows no deployment history: '.json_encode($planned['plan']['history'] ?? null);
            }
            $kv = collect($planned['plan']['bindings'] ?? [])->firstWhere('source_id', $this->e2eBindings['ot-cache']);
            if (! $kv || ! preg_match('/^\d+(\.\d+)*$/', (string) ($kv['data_redis_version'] ?? ''))) {
                return 'the KV dump records no Redis version: '.json_encode($kv);
            }
            if (array_intersect(['kv_version_older', 'kv_version_unknown'], array_column($kv['issues'] ?? [], 'code'))) {
                return 'the same Redis blocks the KV data: '.json_encode($kv['issues']);
            }
            $state['import'] = $planned;

            return true;
        });

        // 6. Renames + passphrase → applicable; apply with data, images, deploy. The source's key
        // route leaves the gateway first: the copy's route takes its path on the same gateway
        if (! empty($state['route']) && ! empty($state['import'])) {
            $tests[] = $this->otCheck('ot-route-path-freed', $regionId, function () use (&$state) {
                $removed = $this->api()->delete("/api/gateways/{$state['route']['gateway_id']}/routes/{$state['route']['id']}");

                return $removed->status() === 204 ? true : 'removing the source route answered '.$removed->status().': '.mb_substr($removed->body(), 0, 300);
            });
        }
        $tests[] = $this->otCheck('ot-apply', $regionId, function () use (&$state) {
            $import = $state['import'] ?? null;
            if (! $import) {
                return 'skipped: no plan';
            }
            $choices = [
                'renames' => (object) collect($import['plan']['projects'])->filter(fn ($p) => $p['collision'] ?? false)->mapWithKeys(fn ($p) => [$p['source_id'] => $p['suggested_name']])->all(),
                'binding_renames' => (object) collect($import['plan']['bindings'])->filter(fn ($b) => $b['collision'] ?? false)->mapWithKeys(fn ($b) => [$b['source_id'] => $b['suggested_name']])->all(),
                'passphrase' => self::OT_PASSPHRASE,
            ];
            $plan = $this->api()->post("/api/org-transfers/{$import['id']}/plan", $choices);
            if (! $plan->json('data.plan.can_apply')) {
                return 'still blocked: '.mb_substr(json_encode($plan->json('data.plan')), 0, 600);
            }
            if ($plan->json('data.plan.contents.secrets') !== 'unlocked') {
                return 'the passphrase did not open the secrets';
            }
            $apply = $this->api()->post("/api/org-transfers/{$import['id']}/apply", $choices + ['restore_data' => true, 'images' => true, 'deploy' => true]);
            if ($apply->status() !== 202) {
                return "apply: HTTP {$apply->status()} ".mb_substr($apply->body(), 0, 300);
            }
            $done = $this->otWait($import['id'], ['completed', 'failed'], 2400);
            if (! is_array($done)) {
                return $done;
            }
            if ($done['status'] !== 'completed') {
                $failed = array_filter($done['report']['items'] ?? [], fn ($i) => $i['status'] === 'failed');

                return 'import failed: '.mb_substr(json_encode(array_values($failed)), 0, 800);
            }
            $state['renames'] = (array) $choices['renames'];
            $state['binding_renames'] = (array) $choices['binding_renames'];
            $state['applied'] = $done;

            return true;
        });

        // 7. The copies hold the source's data
        $tests[] = $this->otCheck('ot-data-restored', $regionId, function () use (&$state) {
            if (empty($state['applied'])) {
                return 'skipped: not applied';
            }

            return $this->otVerifyData($state['binding_renames']);
        });

        // 8. The copied function: versions, secret, domain waiting for DNS, deployed and answering
        $tests[] = $this->otCheck('ot-project-copy', $regionId, function () use (&$state, $function) {
            if (empty($state['applied'])) {
                return 'skipped: not applied';
            }

            return $this->otVerifyProject($function, (string) ($state['renames'][$function['projectId']] ?? ''));
        });

        // 9. The copy's run history: the source's deployments, imported, none of them live
        $tests[] = $this->otCheck('ot-history', $regionId, function () use (&$state, $function) {
            if (empty($state['applied'])) {
                return 'skipped: not applied';
            }

            return $this->otVerifyHistory((string) ($state['renames'][$function['projectId']] ?? ''));
        });

        // 10. The copy's route: imported as a key route, closed from its creation on
        $tests[] = $this->otCheck('ot-route-auth', $regionId, function () use (&$state, $function) {
            if (empty($state['applied']) || empty($state['route'])) {
                return 'skipped: not applied';
            }

            return $this->otVerifyKeyRoute($state, (string) ($state['renames'][$function['projectId']] ?? ''));
        });

        // 11. An import straight from the object binding an export wrote to
        $tests[] = $this->otCheck('ot-source-binding', $regionId, fn () => $this->otImportFromBinding($function));

        return $tests;
    }

    /**
     * The source function's key route on the admin's gateway in the e2e region ($state['route']:
     * id, gateway_id, path). Calls without a key do not reach the function.
     *
     * @param  array<string, mixed>  $state
     */
    private function otKeyRoute(array $function, array &$state): bool|string
    {
        $admin = User::query()->where('username', config('app.admin.username'))->first() ?? User::query()->first();
        $gateway = app(GatewayService::class)->findOrCreateForRegion($admin, $this->getE2eRegion());
        $route = $this->api()->post("/api/gateways/{$gateway->id}/routes", ['project_id' => $function['projectId'], 'path' => '/ot-source', 'auth_mode' => 'key']);
        if ($route->status() !== 201 || $route->json('data.auth_mode') !== 'key') {
            return 'the key route answered '.$route->status().': '.mb_substr($route->body(), 0, 300);
        }
        // the gateway's host for apiCurl() (E2eTestApiProductTrait)
        $this->apiGateway = (array) $this->api()->get("/api/gateways/{$gateway->id}")->json('data');
        $state['route'] = ['id' => (string) $route->json('data.id'), 'gateway_id' => (string) $gateway->id, 'path' => '/ot-source'];

        return $this->otRouteClosed('/ot-source');
    }

    /** @param array<string, mixed> $state */
    private function otVerifyKeyRoute(array $state, string $copyName): bool|string
    {
        $copy = Project::query()->where('name', $copyName)->first();
        if (! $copy) {
            return "no project {$copyName}";
        }
        $item = collect($state['applied']['report']['items'] ?? [])->first(fn ($i) => ($i['section'] ?? null) === 'gateway_route');
        if (($item['status'] ?? null) !== 'created') {
            return 'the import did not create the route: '.json_encode($item);
        }
        $routes = collect($this->api()->get("/api/gateways/{$state['route']['gateway_id']}/routes")->json('data') ?? []);
        $route = $routes->first(fn ($r) => ($r['path'] ?? null) === $state['route']['path'] && (string) ($r['project']['id'] ?? $r['project_id'] ?? '') === (string) $copy->id);
        if (! $route) {
            return "no route {$state['route']['path']} to {$copyName}: ".mb_substr(json_encode($routes->all()), 0, 400);
        }
        if (($route['auth_mode'] ?? null) !== 'key') {
            return 'the copy\'s route was imported '.($route['auth_mode'] ?? '?').', not key';
        }
        $closed = $this->otRouteClosed($state['route']['path']);
        $this->api()->delete("/api/gateways/{$state['route']['gateway_id']}/routes/{$route['id']}");

        return $closed;
    }

    /**
     * Calls of the route without a key until the gateway refuses them (401, or 503 while gateway-auth
     * is not ready): never the function's answer on the way.
     */
    private function otRouteClosed(string $path): bool|string
    {
        $deadline = time() + 180;
        $last = 'no answer';
        while (time() < $deadline) {
            $res = $this->apiCurl([], 1, $path)[0] ?? 'no answer';
            if (is_array($res)) {
                if ($res['status'] === '200' || str_contains($res['body'], 'org-transfer-ok')) {
                    return "a call without a key reached the function (HTTP {$res['status']})";
                }
                if (in_array($res['status'], ['401', '503'], true)) {
                    return true;
                }
                $last = "HTTP {$res['status']} ".mb_substr(trim($res['body']), 0, 160);
            } else {
                $last = $res;
            }
            sleep(5);
        }

        return "the route did not refuse calls without a key within 180s, last: {$last}";
    }

    private function otVerifyHistory(string $copyName): bool|string
    {
        $projects = collect($this->api()->get('/api/projects')->json('data') ?? []);
        $copy = $projects->firstWhere('name', $copyName);
        if (! $copy) {
            return "no project {$copyName}";
        }
        $deployments = $this->api()->get("/api/projects/{$copy['id']}/deployments")->json('data') ?? [];
        $imported = array_values(array_filter($deployments, fn ($d) => ! empty($d['imported_at'])));
        if ($imported === []) {
            return 'no imported deployment history: '.count($deployments).' deployments';
        }
        $live = array_filter($imported, fn ($d) => in_array($d['status'] ?? null, ['pending', 'running', 'active'], true));

        return $live === [] ? true : 'imported history is live: '.json_encode(array_column($live, 'status'));
    }

    /**
     * Export the source function into the object binding ot-files, import it from there (source
     * binding): fetched, verified and planned like an upload; then cancelled — the apply above
     * covered the rest.
     */
    private function otImportFromBinding(array $function): bool|string
    {
        $files = $this->e2eBindings['ot-files'] ?? null;
        if (! $files) {
            return 'skipped: no object binding';
        }
        $export = $this->api()->post('/api/org-transfers/export', [
            'projects' => [$function['projectId']],
            'destination' => ['type' => 'binding', 'binding_id' => $files, 'prefix' => 'e2e/org-transfer'],
        ]);
        if ($export->status() !== 202) {
            return "export to the binding: HTTP {$export->status()} ".mb_substr($export->body(), 0, 300);
        }
        $written = $this->otWait((string) $export->json('data.id'), ['completed', 'failed', 'cancelled'], 1200);
        if (! is_array($written) || $written['status'] !== 'completed') {
            return 'export to the binding: '.(is_array($written) ? $written['status'].' '.($written['message'] ?? '') : $written);
        }
        $key = (string) ($written['destination']['key'] ?? '');
        if ($key === '') {
            return 'the export names no object key: '.json_encode($written['destination'] ?? null);
        }

        $import = $this->api()->post('/api/org-transfers/import', [
            'source' => ['type' => 'binding', 'binding_id' => $files, 'key' => $key],
            'sha256' => $written['package']['sha256'] ?? null,
        ]);
        if ($import->status() !== 202) {
            return "import from the binding: HTTP {$import->status()} ".mb_substr($import->body(), 0, 300);
        }
        $id = (string) $import->json('data.id');
        try {
            $planned = $this->otWait($id, ['planned', 'failed', 'cancelled'], 1200);
            if (! is_array($planned) || $planned['status'] !== 'planned') {
                return 'fetch / verify: '.(is_array($planned) ? $planned['status'].' '.($planned['message'] ?? '') : $planned);
            }
            if ((int) ($planned['upload']['size_bytes'] ?? 0) !== (int) ($written['package']['size_bytes'] ?? -1)) {
                return 'the fetched package has another size: '.json_encode([$planned['upload'] ?? null, $written['package'] ?? null]);
            }
            $sources = array_column($planned['plan']['projects'] ?? [], 'source_id');

            return in_array($function['projectId'], $sources, true) ? true : 'the plan does not show the exported project: '.json_encode($sources);
        } finally {
            $this->api()->delete("/api/org-transfers/{$id}");
        }
    }

    private function otCheck(string $name, string $regionId, callable $check): array
    {
        return $this->bindingCheck($name, $regionId, $check);
    }

    private function otFailed(array $tests): bool
    {
        return (bool) array_filter($tests, fn ($t) => $t['steps']['verify'] !== true);
    }

    /** Second version, secret, domain, the Postgres binding attached. */
    private function otDecorate(array $function): bool|string
    {
        $id = $function['projectId'];
        $v2 = $this->api()->post("/api/projects/{$id}/files", ['files' => [['name' => 'main.py', 'content' => self::OT_HANDLER."\n# v2\n"], ['name' => 'requirements.txt', 'content' => self::OT_REQUIREMENTS]], 'message' => 'second version']);
        if (! $v2->successful()) {
            return "v2: HTTP {$v2->status()}";
        }
        $secret = $this->api()->put("/api/projects/{$id}/secrets/OT_SECRET", ['value' => 'org-transfer-secret']);
        if (! $secret->successful()) {
            return "secret: HTTP {$secret->status()} ".mb_substr($secret->body(), 0, 200);
        }
        $domain = $this->api()->post("/api/projects/{$id}/domains", ['domain' => 'ot-source.e2e.example.test']);
        if (! $domain->successful()) {
            return "domain: HTTP {$domain->status()} ".mb_substr($domain->body(), 0, 200);
        }
        $attach = $this->api()->post("/api/projects/{$id}/bindings", ['binding_id' => $this->e2eBindings['ot-db'], 'env_prefix' => null]);

        return $attach->status() === 201 ? true : "attach: HTTP {$attach->status()} ".mb_substr($attach->body(), 0, 200);
    }

    /** Rows, keys and objects in the source bindings. */
    private function otSeed(): bool|string
    {
        $db = Binding::query()->with('region')->findOrFail($this->e2eBindings['ot-db']);
        if (! str_contains((string) $this->otPsql($db, "CREATE TABLE ot_items (v text); INSERT INTO ot_items VALUES ('exported-row'); SELECT 'seeded';"), 'seeded')) {
            return 'postgres seed failed';
        }
        $cache = Binding::query()->with('region')->findOrFail($this->e2eBindings['ot-cache']);
        if (! str_contains((string) $this->otRedis($cache, 'SET', 'greeting', 'exported-value'), 'OK')) {
            return 'kv seed failed';
        }
        $files = Binding::query()->with('region')->findOrFail($this->e2eBindings['ot-files']);

        return $this->otObject($files, 'put') !== false ? true : 'object seed failed';
    }

    /** @param array<string, string> $bindingRenames source binding id => copy name */
    private function otVerifyData(array $bindingRenames): bool|string
    {
        $copies = collect($this->api()->get('/api/bindings')->json('data') ?? [])->keyBy('name');
        $byKind = ['ot-db' => 'postgres', 'ot-cache' => 'kv', 'ot-files' => 'object'];
        foreach ($byKind as $source => $type) {
            $name = $bindingRenames[$this->e2eBindings[$source]] ?? null;
            $copy = $name ? $copies->get($name) : null;
            if (! $copy || ($copy['status'] ?? null) !== 'ready') {
                return "{$source}: no ready copy ({$name})";
            }
            $binding = Binding::query()->with('region')->findOrFail($copy['id']);
            $ok = match ($type) {
                'postgres' => str_contains((string) $this->otPsql($binding, 'SELECT v FROM ot_items;'), 'exported-row'),
                'kv' => str_contains((string) $this->otRedis($binding, 'GET', 'greeting'), 'exported-value'),
                'object' => str_contains((string) $this->otObject($binding, 'get'), 'exported-object'),
            };
            if (! $ok) {
                return "{$source}: data not restored into {$name}";
            }
        }

        return true;
    }

    private function otVerifyProject(array $function, string $copyName): bool|string
    {
        $projects = collect($this->api()->get('/api/projects')->json('data') ?? $this->api()->get('/api/projects')->json() ?? []);
        $copy = $projects->firstWhere('name', $copyName);
        if (! $copy) {
            return "no project {$copyName}";
        }
        $versions = $this->api()->get("/api/projects/{$copy['id']}/versions")->json('data') ?? $this->api()->get("/api/projects/{$copy['id']}/versions")->json() ?? [];
        if (count($versions) < 2) {
            return 'versions: '.count($versions);
        }
        $secrets = array_column($this->api()->get("/api/projects/{$copy['id']}/secrets")->json('data') ?? [], 'name');
        if (! in_array('OT_SECRET', $secrets, true)) {
            return 'secret not restored';
        }
        $domains = $this->api()->get("/api/projects/{$copy['id']}/domains")->json('data') ?? [];
        // the source keeps its domain on this installation: the copy reports it as a manual step
        if (array_filter($domains, fn ($d) => ($d['domain'] ?? null) === 'ot-source.e2e.example.test')) {
            return 'the source domain moved to the copy';
        }

        $deadline = time() + 900;
        while (time() < $deadline) {
            $deployments = $this->api()->get("/api/projects/{$copy['id']}/deployments")->json('data') ?? [];
            if (array_filter($deployments, fn ($d) => ($d['status'] ?? null) === 'active')) {
                $answer = $this->curlFunction((string) $copy['slug'], '/');
                if (is_array($answer) && $answer[0] === '200' && str_contains($answer[2], 'org-transfer-ok')) {
                    return true;
                }
            }
            if (array_filter($deployments, fn ($d) => ($d['status'] ?? null) === 'failed')) {
                return 'the copy\'s deployment failed';
            }
            sleep(10);
        }

        return 'the copy did not become active and answer';
    }

    /** @return array<string, mixed>|string the transfer once in one of $statuses */
    private function otWait(string $id, array $statuses, int $timeout): array|string
    {
        $deadline = time() + $timeout;
        $last = null;
        while (time() < $deadline) {
            $last = $this->api()->get("/api/org-transfers/{$id}")->json('data') ?? [];
            if (in_array($last['status'] ?? null, $statuses, true)) {
                return $last;
            }
            sleep(5);
        }

        return "transfer {$id}: ".($last['status'] ?? '?').' / '.($last['phase'] ?? '?')." after {$timeout}s";
    }

    /** Chunked upload; the first pass stops half-way, the second resumes from uploaded_bytes. */
    private function otUpload(string $id, string $file, int $chunk): bool|string
    {
        $size = (int) filesize($file);
        foreach ([intdiv($size, 2), $size] as $until) {
            $offset = (int) ($this->api()->get("/api/org-transfers/{$id}")->json('data.upload.uploaded_bytes') ?? 0);
            $in = fopen($file, 'rb');
            while ($offset < $until) {
                fseek($in, $offset);
                $bytes = (string) fread($in, min($chunk, $size - $offset));
                $res = Http::withToken($this->token)->accept('application/json')->baseUrl($this->baseUrl)
                    ->withBody($bytes, 'application/octet-stream')->timeout(120)->put("/api/org-transfers/{$id}/chunk?offset={$offset}");
                if (! $res->successful()) {
                    fclose($in);

                    return "chunk at {$offset}: HTTP {$res->status()} ".mb_substr($res->body(), 0, 200);
                }
                $offset = (int) $res->json('uploaded_bytes');
            }
            fclose($in);
        }

        return true;
    }

    private function otPsql(Binding $binding, string $sql): string|false
    {
        $env = app(BindingDrivers::class)->for($binding)->env($binding);
        $lines = [];
        foreach (['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE'] as $key) {
            $lines[] = $key.'='.OpsTask::quote((string) ($env[$key] ?? ''));
        }
        $task = new OpsTask('ot-psql', (string) config('bindings.postgres.image'),
            "set -a; . /ops/pg.env; set +a\nexport HOME=/tmp\npsql -tA -v ON_ERROR_STOP=1 -f /ops/q.sql\n",
            ['pg.env' => implode("\n", $lines)."\n", 'q.sql' => $sql]);
        $result = app(BindingOpsRunner::class)->run($binding->region, $task);

        return $result->ok ? $result->output : false;
    }

    private function otRedis(Binding $binding, string $command, string $key, ?string $value = null): string|false
    {
        $endpoint = app(KvService::class)->endpoint($binding->region);
        $db = $binding->credential('mode') === 'db' ? (int) $binding->credential('db') : 0;
        $prefix = $binding->credential('mode') === 'db' ? '' : $binding->kvPrefix();
        $result = app(BindingOpsRunner::class)->run($binding->region, RedisScripts::commands($endpoint, (string) config('bindings.kv.image'), 'ot-kv', [
            trim("{$command} {$prefix}{$key} ".($value ?? '')),
        ], $db));

        return $result->ok ? $result->output : false;
    }

    private function otObject(Binding $binding, string $mode): string|false
    {
        $env = app(BindingDrivers::class)->for($binding)->env($binding);
        $rcloneEnv = Rclone::envFile((string) $env['S3_ENDPOINT'], (string) $env['S3_REGION'], (string) $env['S3_ACCESS_KEY_ID'], (string) $env['S3_SECRET_ACCESS_KEY'], 'Other', ($env['S3_FORCE_PATH_STYLE'] ?? 'true') === 'true');
        $path = 't:'.$env['S3_BUCKET'].'/ot/hello.txt';
        $task = $mode === 'put'
            ? Rclone::task('ot-object', $rcloneEnv, ["rcat {$path} < /ops/hello.txt"], ['hello.txt' => 'exported-object'])
            : Rclone::task('ot-object', $rcloneEnv, ["cat {$path}"]);
        $result = app(BindingOpsRunner::class)->run($binding->region, $task);

        return $result->ok ? $result->output : false;
    }
}
