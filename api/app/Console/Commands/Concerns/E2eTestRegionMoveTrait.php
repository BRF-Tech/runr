<?php

namespace App\Console\Commands\Concerns;

use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\Observability\RegionTracingConfig;
use App\Models\Project;
use App\Models\QueuedDeploy;
use App\Models\Version;
use App\Services\KubernetesClient;
use App\Services\Projects\QueuedDeploys;
use App\Services\RegionMigrateService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/**
 * E2E of moving a region to another cluster and rolling the move back (test:e2e
 * --suite=region-move, runtime/lib/test-region-move.sh; docs/regions.md#moving-a-region-to-another-cluster).
 * The e2e region runs on Kind cluster A; --move-kubeconfig is a second Kind cluster B, one node,
 * nothing of RunR on it. Three projects on A:
 *
 *  - rm-built    FaaS Python with a pinned dependency (six): built by Kaniko into A's Zot; it
 *                answers with the dependency's version, so it runs from an image that has it
 *  - rm-caas     CaaS nginx (an image of ghcr.io, which every cluster pulls)
 *  - rm-stopped  CaaS nginx, stopped while the region is on B
 *
 * Each answers through A's Traefik address (the region's external_ip) with its host
 * <slug>.<function domain>, as a client of the function domain's DNS record reaches it. Then:
 *
 *  - rm-move              POST /knative-regions/{id}/migrate with B's kubeconfig reaches dns_check:
 *                         the region uses B's kubeconfig, its registry address is B's Zot (B has
 *                         another service range: another ClusterIP), its external address B's
 *                         Traefik, its dedicated worker (a node of A) is cleared, and the move kept
 *                         A's kubeconfig, address and registry address for a rollback
 *  - rm-move-rebuilt      rm-built is built again into B's registry and deployed on B once built
 *                         (QueuedDeploys): report outcome deployed / active, the queued deploy keeps
 *                         A's image as previous_build_image, one live deployment
 *  - rm-move-report       GET migration-status lists every project with its outcome: rm-built
 *                         deployed, rm-caas and rm-stopped moved, all v1 and active, nothing else
 *  - rm-move-serves       the functions answer through B's Traefik address; A, which the DNS record
 *                         still points at, still answers for rm-caas (the move removes nothing there)
 *  - rm-stop-on-b         rm-stopped is stopped: its Knative Service leaves B, A keeps its own from
 *                         before the move, no live deployment is left
 *  - rm-build-on-b        a new version of rm-built saved on B builds there: B's node is cordoned,
 *                         so its Kaniko pod waits Pending and the build stays "building"
 *  - rm-rollback          POST rollback-migration (a bindings Secret of rm-caas — a data service's
 *                         credentials — is put on B first): kubeconfig, external address, registry
 *                         address and every other RegionMigrateService::CLUSTER_STATE column
 *                         (dedicated workers, Knative / Redis install state, the agent's token, …)
 *                         and the tracing backend's status are what they were before the move; the
 *                         answer says the projects leave B in the background
 *  - rm-rollback-builds   the build still running on B ends cancelled with BUILD_CANCELLED and its
 *                         Kaniko Job and source ConfigMap leave B; v1 of rm-built runs from its image
 *                         in A's registry again
 *  - rm-rollback-serves   rm-built and rm-caas are deployed on A again by the rollback (active) and
 *                         answer through A's Traefik address
 *  - rm-rollback-metered  nothing serves on A unmetered: rm-stopped's Knative Service (left there by
 *                         the move) is removed, and every Knative Service in the region's namespace
 *                         on A belongs to a project with exactly one live deployment
 *  - rm-rollback-left     B has no Knative Service of the region's projects left
 *                         (RemoveLeftClusterWorkloadsJob), and keeps the bindings Secret and what
 *                         the move installed (Knative Serving, the Zot registry); the operator's
 *                         notification says the two workloads were removed
 *  - rm-rollback-report   migration-status: no move, A's address, no project rows
 *
 * Not on Kind: the DNS cut-over. The status check resolves dns-check.<function domain>, which no
 * record points at B here; as the base suite's Cluster Migration step, the move is taken out of
 * dns_check through the API (the rollback), and the functions are called through the address the
 * record would name. Preview environments need a git provider (--suite=git-deploy), uploaded
 * images the receiver (--suite=images) and Kata's Firecracker pool /dev/kvm: their move is left to
 * the unit tests.
 */
trait E2eTestRegionMoveTrait
{
    private const RM_PYTHON = <<<'PY'
import six


def handler(event, context):
    return {"statusCode": 200, "body": {"suite": "region-move", "six": six.__version__}}
PY;

    /** The pinned dependency of rm-built: its answer names the version the image has */
    private const RM_SIX = '1.16.0';

    private const RM_NGINX = 'ghcr.io/brf-tech/runr/reqs/nginx:1.27-alpine';

    private function runRegionMoveSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Region move (another cluster, rebuilds, report, rollback) ──');

        $file = (string) $this->option('move-kubeconfig');
        $target = $file !== '' && is_file($file) ? trim((string) file_get_contents($file)) : '';
        if ($target === '') {
            return [$this->rmCheck('rm-move', fn () => "no kubeconfig of the cluster to move to (--move-kubeconfig={$file}); runtime/lib/test-region-move.sh creates that cluster")];
        }

        // ── The projects on A ──
        $tests = [
            $this->makeTestEntry('rm-built', [
                'service_type' => 'faas',
                'runtime' => 'python',
                'runtime_version' => null,
                'trigger_type' => 'http',
                'faas_files' => self::rmFiles('region-move'),
            ], $regionId),
            $this->makeTestEntry('rm-caas', ['service_type' => 'caas', 'trigger_type' => 'http', 'image' => self::RM_NGINX], $regionId),
            $this->makeTestEntry('rm-stopped', ['service_type' => 'caas', 'trigger_type' => 'http', 'image' => self::RM_NGINX], $regionId),
        ];
        $this->runTestsSequentially($tests);

        $region = KnativeRegion::query()->findOrFail($regionId);
        foreach ($tests as &$test) {
            $test['steps']['verify'] = $test['steps']['ready'] === true
                ? $this->rmServes((string) $region->external_ip, $test)
                : 'skipped';
            $this->line('  '.($test['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>')." {$test['name']} answers on A"
                .($test['steps']['verify'] === true ? '' : ': '.$test['steps']['verify']));
        }
        unset($test);

        $names = ['rm-move', 'rm-move-rebuilt', 'rm-move-report', 'rm-move-serves', 'rm-stop-on-b', 'rm-build-on-b',
            'rm-rollback', 'rm-rollback-builds', 'rm-rollback-serves', 'rm-rollback-metered', 'rm-rollback-left', 'rm-rollback-report'];
        $built = Version::query()->find($tests[0]['versionId']);
        $registryA = rtrim((string) $region->registry_address, '/');
        if (array_filter($tests, fn (array $t) => $t['steps']['verify'] !== true) || ! $built) {
            return array_merge($tests, array_map(fn (string $name) => $this->rmSkip($name), $names));
        }
        if (! str_starts_with((string) $built->build_image, $registryA.'/')) {
            $tests[0]['steps']['verify'] = "v{$built->number} image {$built->build_image} is not in A's registry {$registryA}";

            return array_merge($tests, array_map(fn (string $name) => $this->rmSkip($name), $names));
        }

        // What the region holds for A, right before the move; the checks add what they learn
        /** @var array<string, mixed> $c */
        $c = [
            'regionId' => $regionId,
            'namespace' => $region->project_namespace ?? $region->name,
            'target' => $target,
            'b' => new KubernetesClient($target),
            'a' => $this->getE2eKubernetesClient(),
            'serverA' => self::rmServer((string) $region->kubeconfig),
            'ipA' => (string) $region->external_ip,
            'registryA' => $registryA,
            'imageA' => (string) $built->build_image,
            'stateA' => $this->rmClusterState($region),
            'built' => $tests[0],
            'caas' => $tests[1],
            'stopped' => $tests[2],
        ];
        $this->line('  A: '.$c['serverA'].", registry {$registryA}, Traefik {$c['ipA']}, rm-built v{$built->number} from {$c['imageA']}");

        // The checks share $c: what one learns (B's registry and address, the build on B, when the
        // rollback ran) the next ones read
        $check = function (string $name, string $method) use (&$c): array {
            return $this->rmCheck($name, function () use (&$c, $method) {
                return $this->{$method}($c);
            });
        };

        $tests[] = $move = $check('rm-move', 'rmMove');
        if ($move['steps']['verify'] !== true) {
            return array_merge($tests, array_map(fn (string $name) => $this->rmSkip($name), array_slice($names, 1)));
        }
        $tests[] = $check('rm-move-rebuilt', 'rmMoveRebuilt');
        $tests[] = $check('rm-move-report', 'rmMoveReport');
        $tests[] = $check('rm-move-serves', 'rmMoveServes');
        $tests[] = $check('rm-stop-on-b', 'rmStopOnB');
        $tests[] = $check('rm-build-on-b', 'rmBuildOnB');

        $tests[] = $check('rm-rollback', 'rmRollback');
        if (! isset($c['rolledBackAt'])) {
            $this->rmUncordon($c);

            return array_merge($tests, array_map(fn (string $name) => $this->rmSkip($name), array_slice($names, 7)));
        }
        $tests[] = $check('rm-rollback-builds', 'rmRollbackBuilds');
        $tests[] = $check('rm-rollback-serves', 'rmRollbackServes');
        $tests[] = $check('rm-rollback-metered', 'rmRollbackMetered');
        $tests[] = $check('rm-rollback-left', 'rmRollbackLeft');
        $tests[] = $check('rm-rollback-report', 'rmRollbackReport');

        return $tests;
    }

    /** @return list<array{name: string, content: string}> */
    private static function rmFiles(string $suite): array
    {
        return [
            ['name' => 'main.py', 'content' => str_replace('"region-move"', '"'.$suite.'"', self::RM_PYTHON)],
            ['name' => 'requirements.txt', 'content' => 'six=='.self::RM_SIX."\n"],
        ];
    }

    // ── The move ──

    private function rmMove(array &$c): bool|string
    {
        $start = $this->api()->timeout(60)->post("/api/knative-regions/{$c['regionId']}/migrate", ['kubeconfig' => $c['target']]);
        if (! $start->successful()) {
            return 'migrate answered '.$start->status().': '.mb_substr($start->body(), 0, 300);
        }

        // MigrateRegionJob: Knative Operator + Serving (with Traefik, Prometheus / Loki, the
        // agent, the tracing backend), Redis and Zot with its image pre-pull on B, then the
        // redeploys — minutes; the job may run 30
        $started = time();
        $status = null;
        $outcomes = [];
        $response = null;
        while (time() < $started + 1920) {
            $response = $this->api()->get("/api/knative-regions/{$c['regionId']}/migration-status");
            if ($response->json('migration_status') !== $status) {
                $status = $response->json('migration_status');
                $this->line('    move: '.($status ?? 'not started').' after '.(time() - $started).' s');
            }
            $this->rmNoteOutcomes($c, (array) $response->json('projects'), $outcomes, $started);
            if (in_array($status, ['dns_check', 'completed', 'failed'], true)) {
                break;
            }
            sleep(10);
        }
        if ($status !== 'dns_check') {
            return 'the move ended in '.($status ?? 'no status').' after '.(time() - $started).' s (want dns_check): '
                .mb_substr((string) $response?->body(), 0, 600).'; the API log (api/storage/logs/laravel.log) has the "RegionMigrate" lines';
        }
        $c['movedAt'] = time();

        $region = KnativeRegion::query()->findOrFail($c['regionId']);
        $problems = [];
        if (self::rmServer((string) $region->kubeconfig) !== self::rmServer($c['target'])) {
            $problems[] = 'the region does not use the new kubeconfig';
        }
        $c['registryB'] = rtrim((string) $region->registry_address, '/');
        if ($c['registryB'] === '' || $c['registryB'] === $c['registryA']) {
            $problems[] = "its registry address is {$c['registryB']}, not B's Zot (A's: {$c['registryA']})";
        }
        $saved = (array) ($region->migration_old_state ?? []);
        if (rtrim((string) ($saved['registry_address'] ?? ''), '/') !== $c['registryA']) {
            $problems[] = 'migration_old_state keeps registry_address '.json_encode($saved['registry_address'] ?? null)." (want A's {$c['registryA']})";
        }
        if (self::rmServer((string) $region->migration_old_kubeconfig) !== $c['serverA']) {
            $problems[] = "migration_old_kubeconfig is not A's";
        }
        if ((string) $region->migration_old_ip !== $c['ipA']) {
            $problems[] = "migration_old_ip {$region->migration_old_ip} (want A's {$c['ipA']})";
        }
        if (! empty($region->selected_workers)) {
            $problems[] = 'dedicated workers on B: '.json_encode($region->selected_workers).' (none of A\'s nodes is on B: want none)';
        }

        // B's Traefik address: the Serving install reads it, the scheduler's refresh when the load
        // balancer got it later
        $c['ipB'] = '';
        $deadline = time() + 300;
        do {
            $ip = (string) KnativeRegion::query()->find($c['regionId'])?->external_ip;
            if ($ip !== '' && $ip !== $c['ipA']) {
                $c['ipB'] = $ip;
                break;
            }
            sleep(10);
        } while (time() < $deadline);
        if ($c['ipB'] === '') {
            $problems[] = "the region's external address is still ".json_encode($ip)." 300 s after dns_check (want B's Traefik, not A's {$c['ipA']})";
        }

        $this->line("    B: registry {$c['registryB']}, Traefik ".($c['ipB'] ?: '?'));

        return $problems === [] ? true : implode('; ', $problems);
    }

    /** Prints each project's report row when it changes (the outcomes the move goes through). */
    private function rmNoteOutcomes(array $c, array $rows, array &$seen, int $started): void
    {
        $names = [$c['built']['projectId'] => 'rm-built', $c['caas']['projectId'] => 'rm-caas', $c['stopped']['projectId'] => 'rm-stopped'];
        foreach ($rows as $row) {
            $id = (string) ($row['project_id'] ?? '');
            $now = ($row['outcome'] ?? '?').' v'.($row['version'] ?? '?').' '.($row['deployment_status'] ?? '-');
            if (($seen[$id] ?? null) !== $now) {
                $seen[$id] = $now;
                $this->line('      '.($names[$id] ?? $id).": {$now} after ".(time() - $started).' s'
                    .(! empty($row['message']) ? ' — '.mb_substr((string) $row['message'], 0, 160) : ''));
            }
        }
    }

    private function rmMoveRebuilt(array $c): bool|string
    {
        $built = $c['built'];
        $row = $this->rmWaitRow($c, $built['projectId'], fn (array $row) => in_array($row['outcome'] ?? null, ['not_moved', 'cancelled'], true)
            || (($row['outcome'] ?? null) === 'deployed' && in_array($row['deployment_status'] ?? null, ['active', 'failed', 'shut_down'], true)), 900);
        if (! is_array($row)) {
            return $row;
        }
        if ($row['outcome'] !== 'deployed' || $row['deployment_status'] !== 'active' || (int) $row['version'] !== 1) {
            return 'report row of rm-built: '.json_encode($row).' (want deployed, v1, active)';
        }

        $problems = [];
        $version = Version::query()->find($built['versionId']);
        if ($version?->build_status !== 'ready' || ! str_starts_with((string) $version->build_image, $c['registryB'].'/')) {
            $problems[] = 'v1 is '.($version->build_status ?? 'gone').' from '.($version->build_image ?? '-')." (want ready, built into B's registry {$c['registryB']})";
        }
        $queued = QueuedDeploy::query()->where('project_id', $built['projectId'])
            ->where('reason', QueuedDeploys::REASON_REGION_MIGRATION)->latest('created_at')->first();
        if (! $queued || $queued->status !== QueuedDeploy::DEPLOYED || $queued->previous_build_image !== $c['imageA']) {
            $problems[] = 'queued deploy of the move: '.json_encode($queued?->only(['status', 'previous_build_image', 'message']))." (want deployed, previous_build_image {$c['imageA']})";
        }
        $live = $this->rmLive($built['projectId']);
        if ($live->count() !== 1 || $live->first()->deployed_by !== 'region-migration' || $live->first()->status !== 'active') {
            $problems[] = 'live deployments: '.json_encode($live->map->only(['status', 'deployed_by', 'version_id'])->all()).' (want one, active, deployed_by region-migration)';
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    private function rmMoveReport(array $c): bool|string
    {
        $want = [
            $c['built']['projectId'] => ['rm-built', 'deployed'],
            $c['caas']['projectId'] => ['rm-caas', 'moved'],
            $c['stopped']['projectId'] => ['rm-stopped', 'moved'],
        ];

        // A moved deployment starts pending: every row final
        $deadline = time() + 300;
        do {
            $rows = collect((array) $this->api()->get("/api/knative-regions/{$c['regionId']}/migration-status")->json('projects'))->keyBy('project_id');
            $settled = $rows->count() >= count($want) && $rows->every(fn (array $row) => ! in_array($row['deployment_status'] ?? null, ['pending', 'running'], true));
            if ($settled) {
                break;
            }
            sleep(10);
        } while (time() < $deadline);

        $problems = [];
        foreach ($want as $id => [$name, $outcome]) {
            $row = $rows->get($id);
            if (! $row || $row['outcome'] !== $outcome || (int) $row['version'] !== 1 || $row['deployment_status'] !== 'active' || $row['organization'] !== null) {
                $problems[] = "{$name}: ".json_encode($row)." (want {$outcome}, v1, active, no organization)";
            }
        }
        foreach ($rows->except(array_keys($want)) as $id => $row) {
            $problems[] = "a row of a project the suite did not create: {$id} ".json_encode($row);
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    private function rmMoveServes(array $c): bool|string
    {
        if ($c['ipB'] === '') {
            return "B's Traefik address is unknown (rm-move)";
        }
        $problems = [];
        foreach (['built', 'caas', 'stopped'] as $key) {
            $served = $this->rmServes($c['ipB'], $c[$key]);
            if ($served !== true) {
                $problems[] = "on B: {$served}";
            }
        }
        // Until DNS points at B the function domain reaches A, where the move removed nothing
        $served = $this->rmServes($c['ipA'], $c['caas'], 60);
        if ($served !== true) {
            $problems[] = "on A during the move: {$served}";
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    private function rmStopOnB(array $c): bool|string
    {
        $stopped = $c['stopped'];
        $stop = $this->api()->post("/api/projects/{$stopped['projectId']}/stop", ['reason' => 'e2e: stopped while the region is on the new cluster']);
        if ($stop->status() !== 200) {
            return 'stop answered '.$stop->status().': '.mb_substr($stop->body(), 0, 300);
        }

        // ProcessTeardown removes it from the region's cluster: B
        $deadline = time() + 180;
        while ($c['b']->getResourceStatus('serving.knative.dev/v1', 'Service', $stopped['slug'], $c['namespace']) !== null && time() < $deadline) {
            sleep(5);
        }
        $problems = [];
        if ($c['b']->getResourceStatus('serving.knative.dev/v1', 'Service', $stopped['slug'], $c['namespace']) !== null) {
            $problems[] = 'its Knative Service is still on B after 180 s';
        }
        if ($c['a']->getResourceStatus('serving.knative.dev/v1', 'Service', $stopped['slug'], $c['namespace']) === null) {
            $problems[] = 'A no longer has its Knative Service from before the move (the move removes nothing there)';
        }
        if ($this->rmLive($stopped['projectId'])->isNotEmpty()) {
            $problems[] = 'it still has a live deployment';
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    /**
     * A version of rm-built saved on B: B's nodes are cordoned first, so its Kaniko pod waits
     * Pending and the build is still running on B when the rollback comes.
     */
    private function rmBuildOnB(array &$c): bool|string
    {
        foreach ($c['b']->listClusterResources('v1', 'Node')['items'] ?? [] as $node) {
            if (! $c['b']->cordonNode((string) $node['metadata']['name'])) {
                return 'cordoning '.$node['metadata']['name'].' on B failed';
            }
        }
        $c['cordoned'] = true;

        $save = $this->api()->post("/api/projects/{$c['built']['projectId']}/files", [
            'files' => self::rmFiles('region-move-v2'),
            'message' => 'e2e: saved while the region is on the new cluster',
        ]);
        $id = $save->json('version.id');
        if (! $save->successful() || ! $id) {
            return 'saving files answered '.$save->status().': '.mb_substr($save->body(), 0, 300);
        }

        $deadline = time() + 180;
        $version = null;
        $job = null;
        do {
            $version = Version::query()->find($id);
            if ($version && $version->build_status === 'building') {
                $job = $c['b']->getResourceStatus('batch/v1', 'Job', "build-{$c['built']['slug']}-v{$version->number}", $c['namespace']);
                if ($job !== null) {
                    break;
                }
            }
            sleep(3);
        } while (time() < $deadline);
        if ($version?->build_status !== 'building' || $job === null) {
            return 'v'.($version->number ?? '?').' is '.($version->build_status ?? 'gone')
                .($job === null ? ', no build Job on B' : '').' after 180 s (want building, its Kaniko Job on B)'
                .($version?->build_error ? ': '.mb_substr((string) $version->build_error, 0, 200) : '');
        }
        $c['v2'] = ['id' => $version->id, 'number' => $version->number];
        $this->line("    v{$version->number} builds on B (Job build-{$c['built']['slug']}-v{$version->number}, pod held Pending)");

        return true;
    }

    // ── The rollback ──

    private function rmRollback(array &$c): bool|string
    {
        // A data service's credentials of rm-caas on B, as BindingManifests::projectSecret() writes
        // them: the rollback removes the projects' workloads from B, not this
        $c['bindingsSecret'] = $c['caas']['slug'].'-bindings';
        $planted = $c['b']->applyResource([
            'apiVersion' => 'v1', 'kind' => 'Secret',
            'metadata' => ['name' => $c['bindingsSecret'], 'namespace' => $c['namespace'], 'labels' => [
                'app.kubernetes.io/managed-by' => 'runr', 'runr/project-id' => $c['caas']['projectId'], 'runr.dev/bindings' => 'true',
            ]],
            'type' => 'Opaque',
            'data' => ['E2E_DB_URL' => base64_encode('postgres://e2e@db.invalid/e2e')],
        ], $c['namespace']);
        if (! $planted) {
            unset($c['bindingsSecret']);
        }

        $at = now()->subSecond();
        $began = microtime(true);
        $rollback = $this->api()->timeout(600)->post("/api/knative-regions/{$c['regionId']}/rollback-migration");
        $this->line('    rollback answered '.$rollback->status().' after '.round(microtime(true) - $began, 1).' s: '.mb_substr((string) $rollback->json('message'), 0, 200));
        if (! $rollback->successful()) {
            return 'rollback-migration answered '.$rollback->status().': '.mb_substr($rollback->body(), 0, 400);
        }
        $c['rolledBackAt'] = $at;

        $region = KnativeRegion::query()->findOrFail($c['regionId']);
        $problems = [];
        if (! str_contains((string) $rollback->json('message'), 'removed from it in the background')) {
            $problems[] = 'the answer does not say the projects leave B: '.mb_substr((string) $rollback->json('message'), 0, 200);
        }
        if (! $planted) {
            $problems[] = "the bindings Secret {$c['caas']['slug']}-bindings could not be put on B";
        }
        if (self::rmServer((string) $region->kubeconfig) !== $c['serverA']) {
            $problems[] = 'the region does not use A\'s kubeconfig';
        }
        if ($region->migration_status !== null || $region->migration_old_kubeconfig !== null || $region->migration_old_state !== null || $region->migration_old_ip !== null) {
            $problems[] = 'the move\'s state is left: '.json_encode($region->only(['migration_status', 'migration_old_ip']));
        }
        $now = $this->rmClusterState($region);
        foreach ($c['stateA'] as $column => $before) {
            if (($now[$column] ?? null) !== $before) {
                $problems[] = "{$column} is ".mb_substr((string) ($now[$column] ?? 'null'), 0, 120).' (before the move: '.mb_substr((string) $before, 0, 120).')';
            }
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    private function rmRollbackBuilds(array &$c): bool|string
    {
        $problems = [];
        try {
            if (! isset($c['v2'])) {
                $problems[] = 'no build on B to cancel (rm-build-on-b)';
            } else {
                $v2 = Version::query()->find($c['v2']['id']);
                if ($v2?->build_status !== Version::STATUS_CANCELLED || $v2->build_error !== RegionMigrateService::BUILD_CANCELLED) {
                    $problems[] = "v{$c['v2']['number']} is ".($v2->build_status ?? 'gone').': '.mb_substr((string) $v2?->build_error, 0, 200).' (want cancelled with BUILD_CANCELLED)';
                }
                $name = "build-{$c['built']['slug']}-v{$c['v2']['number']}";
                $deadline = time() + 90;
                while ($c['b']->getResourceStatus('batch/v1', 'Job', $name, $c['namespace']) !== null && time() < $deadline) {
                    sleep(5);
                }
                if ($c['b']->getResourceStatus('batch/v1', 'Job', $name, $c['namespace']) !== null) {
                    $problems[] = "the Kaniko Job {$name} is still on B after 90 s";
                }
                if ($c['b']->getResourceStatus('v1', 'ConfigMap', $name, $c['namespace']) !== null) {
                    $problems[] = "the source ConfigMap {$name} is still on B";
                }
            }

            $v1 = Version::query()->find($c['built']['versionId']);
            if ($v1?->build_status !== 'ready' || $v1->build_image !== $c['imageA']) {
                $problems[] = 'v1 is '.($v1->build_status ?? 'gone').' from '.($v1->build_image ?? '-')." (want ready from A's {$c['imageA']})";
            }
        } finally {
            $this->rmUncordon($c);
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    private function rmRollbackServes(array $c): bool|string
    {
        $problems = [];
        foreach (['built', 'caas'] as $key) {
            $test = $c[$key];
            $deadline = time() + $this->timeout;
            do {
                $live = $this->rmLive($test['projectId']);
                $deployment = $live->first();
                if ($live->count() === 1 && $deployment->status === 'active' && $deployment->created_at >= $c['rolledBackAt']) {
                    break;
                }
                sleep(5);
            } while (time() < $deadline);
            if ($live->count() !== 1 || $deployment->status !== 'active' || $deployment->created_at < $c['rolledBackAt'] || $deployment->deployed_by !== 'region-migration') {
                $problems[] = "{$test['name']}: live deployments ".json_encode($live->map->only(['status', 'deployed_by', 'created_at', 'knative_message'])->all())
                    .' (want one, active, deployed_by region-migration by the rollback)';

                continue;
            }
            $served = $this->rmServes($c['ipA'], $test);
            if ($served !== true) {
                $problems[] = "on A: {$served}";
            }
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    private function rmRollbackMetered(array $c): bool|string
    {
        $problems = [];
        $stopped = $c['stopped'];

        $deadline = time() + 120;
        while ($c['a']->getResourceStatus('serving.knative.dev/v1', 'Service', $stopped['slug'], $c['namespace']) !== null && time() < $deadline) {
            sleep(5);
        }
        if ($c['a']->getResourceStatus('serving.knative.dev/v1', 'Service', $stopped['slug'], $c['namespace']) !== null) {
            $problems[] = 'rm-stopped (stopped while the region was on B) still has its Knative Service on A';
        }
        if (! Project::query()->find($stopped['projectId'])?->isStopped() || $this->rmLive($stopped['projectId'])->isNotEmpty()) {
            $problems[] = 'rm-stopped is not stopped, or has a live deployment';
        }

        // Every Knative Service on A belongs to a project with one live deployment (metered)
        $projects = Project::query()->where('knative_region_id', $c['regionId'])->get()->keyBy('slug');
        foreach ($c['a']->listResources('serving.knative.dev/v1', 'Service', $c['namespace'])['items'] ?? [] as $ksvc) {
            $name = (string) ($ksvc['metadata']['name'] ?? '');
            $project = $projects->get($name);
            $live = $project ? $this->rmLive($project->id)->count() : 0;
            if ($live !== 1) {
                $problems[] = "Knative Service {$name} on A: ".($project ? "{$live} live deployments" : 'no project of the region');
            }
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    /**
     * What the move deployed for the projects on B leaves it after the rollback, in the background
     * (RemoveLeftClusterWorkloadsJob): no Knative Service of the region's projects is left there.
     * The data service credentials (the bindings Secret rm-rollback put there) and what the move
     * installed stay, and the operator's notification names what was removed.
     */
    private function rmRollbackLeft(array $c): bool|string
    {
        $projectIds = Project::query()->where('knative_region_id', $c['regionId'])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $ours = function () use ($c, $projectIds): array {
            $names = [];
            foreach ($c['b']->listResources('serving.knative.dev/v1', 'Service', $c['namespace'])['items'] ?? [] as $ksvc) {
                $labels = (array) ($ksvc['metadata']['labels'] ?? []);
                if (in_array($labels['runr/project-id'] ?? null, $projectIds, true) || in_array($labels['runr/preview-of'] ?? null, $projectIds, true)) {
                    $names[] = (string) ($ksvc['metadata']['name'] ?? '');
                }
            }

            return $names;
        };

        $deadline = time() + 300;
        while (($left = $ours()) !== [] && time() < $deadline) {
            sleep(5);
        }
        $all = array_map(fn (array $ksvc) => (string) ($ksvc['metadata']['name'] ?? ''), $c['b']->listResources('serving.knative.dev/v1', 'Service', $c['namespace'])['items'] ?? []);
        $this->line('    left on B: '.($all === [] ? 'no Knative Service' : 'Knative Services '.implode(', ', $all)));

        $problems = [];
        if ($left !== []) {
            $problems[] = 'Knative Services of the region\'s projects still on B 300 s after the rollback: '.implode(', ', $left);
        }
        if (isset($c['bindingsSecret']) && $c['b']->getResourceStatus('v1', 'Secret', $c['bindingsSecret'], $c['namespace']) === null) {
            $problems[] = "the bindings Secret {$c['bindingsSecret']} (a data service's credentials) left B";
        }
        $region = KnativeRegion::query()->findOrFail($c['regionId']);
        $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
        if ($c['b']->getResourceStatus('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $servingNs) === null) {
            $problems[] = "B's KnativeServing ({$servingNs}) is gone";
        }
        $registryNs = $region->registry_namespace ?? 'runr-registry';
        if (($c['b']->listResources('v1', 'Pod', $registryNs)['items'] ?? []) === []) {
            $problems[] = "B's registry namespace {$registryNs} runs no pod";
        }

        // The operator who rolled back is told what left B (two Knative Services: rm-built, rm-caas)
        $notice = null;
        $deadline = time() + 60;
        do {
            $notice = Notification::query()->whereIn('type', ['region.left_cluster_cleaned', 'region.left_cluster_cleanup_failed'])
                ->where('created_at', '>=', $c['rolledBackAt'])->latest('created_at')->first();
            if ($notice) {
                break;
            }
            sleep(5);
        } while (time() < $deadline);
        $want = "Left cluster cleanup of {$region->name}: removed 2 project workloads";
        if (! $notice || $notice->title !== $want) {
            $problems[] = 'notification: '.($notice ? json_encode($notice->only(['type', 'title', 'body'])) : 'none')." (want \"{$want}\")";
        } else {
            $this->line('    '.mb_substr((string) $notice->body, 0, 300));
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    private function rmRollbackReport(array $c): bool|string
    {
        $status = $this->api()->get("/api/knative-regions/{$c['regionId']}/migration-status");
        $want = ['migration_status' => null, 'migration_old_ip' => null, 'current_ip' => $c['ipA'], 'is_migrating' => false, 'projects' => []];
        $got = array_intersect_key((array) $status->json(), $want);

        return $status->successful() && $got == $want
            ? true
            : 'migration-status answered '.$status->status().': '.json_encode($got).' (want '.json_encode($want).')';
    }

    // ── Helpers ──

    /**
     * Polls the project through a Traefik address with its host <slug>.<function domain> — as a
     * client of the function domain's DNS record reaches it — until it answers as it should
     * (rm-built: 200 with the pinned six version; nginx: its page). True, or the last answer.
     */
    private function rmServes(string $ip, array $test, int $timeout = 240): bool|string
    {
        $domain = KnativeRegion::query()->find($test['regionId'])?->effective_settings['knative_domain'] ?? null;
        if ($ip === '' || ! $domain) {
            return "{$test['name']}: no address ({$ip}) or function domain (".json_encode($domain).') to call it with';
        }
        $host = "{$test['slug']}.{$domain}";
        $built = ($test['config']['service_type'] ?? '') === 'faas';

        $deadline = time() + $timeout;
        $last = 'no answer';
        do {
            try {
                $response = Http::timeout(20)->withHeaders(['Host' => $host])->get("http://{$ip}/");
                if ($response->status() === 200 && ($built ? $response->json('six') === self::RM_SIX : str_contains($response->body(), 'nginx'))) {
                    return true;
                }
                $last = 'HTTP '.$response->status().' '.mb_substr(trim($response->body()), 0, 160);
            } catch (\Throwable $e) {
                $last = mb_substr($e->getMessage(), 0, 200);
            }
            sleep(5);
        } while (time() < $deadline);

        return "{$host} at {$ip}: want 200 ".($built ? 'with six '.self::RM_SIX : 'from nginx')." within {$timeout} s, last: {$last}";
    }

    /** Polls migration-status until the project's row satisfies $done; the row, or why not. */
    private function rmWaitRow(array $c, string $projectId, callable $done, int $timeout): array|string
    {
        $deadline = time() + $timeout;
        $row = null;
        $seen = [];
        $started = $c['movedAt'] ?? time();
        do {
            $rows = (array) $this->api()->get("/api/knative-regions/{$c['regionId']}/migration-status")->json('projects');
            $this->rmNoteOutcomes($c, $rows, $seen, $started);
            $row = collect($rows)->firstWhere('project_id', $projectId);
            if (is_array($row) && $done($row)) {
                return $row;
            }
            sleep(10);
        } while (time() < $deadline);

        return "no final report row within {$timeout} s, last: ".json_encode($row);
    }

    /** @return Collection<int, Deployment> the project's live deployments, newest first */
    private function rmLive(string $projectId): Collection
    {
        return Deployment::query()->where('project_id', $projectId)->whereIn('status', Deployment::LIVE)
            ->latest('created_at')->get()->toBase();
    }

    /**
     * The region's RegionMigrateService::CLUSTER_STATE columns and its tracing backend's status,
     * each as JSON (keys sorted: jsonb keeps its own order) to compare before and after.
     *
     * @return array<string, string>
     */
    private function rmClusterState(KnativeRegion $region): array
    {
        $json = function (mixed $value) use (&$json): string {
            if ($value instanceof \DateTimeInterface) {
                return json_encode($value->format(DATE_ATOM));
            }
            if (is_array($value)) {
                if (! array_is_list($value)) {
                    ksort($value);
                }

                return '['.implode(',', array_map(fn ($k, $v) => json_encode($k).':'.$json($v), array_keys($value), $value)).']';
            }

            return (string) json_encode($value);
        };

        $state = [];
        foreach (RegionMigrateService::CLUSTER_STATE as $column) {
            if (array_key_exists($column, $region->getAttributes())) {
                $state[$column] = $json($region->getAttribute($column));
            }
        }
        $tracing = RegionTracingConfig::query()->where('region_id', $region->getKey())->first();
        $state['region_tracing_configs.status'] = $json($tracing?->status);

        return $state;
    }

    private function rmUncordon(array &$c): void
    {
        if (empty($c['cordoned'])) {
            return;
        }
        foreach ($c['b']->listClusterResources('v1', 'Node')['items'] ?? [] as $node) {
            $c['b']->uncordonNode((string) $node['metadata']['name']);
        }
        $c['cordoned'] = false;
    }

    /** The API server of a kubeconfig's first cluster. */
    private static function rmServer(string $kubeconfig): ?string
    {
        try {
            $server = Yaml::parse($kubeconfig)['clusters'][0]['cluster']['server'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($server) ? rtrim($server, '/') : null;
    }

    private function rmCheck(string $name, callable $check): array
    {
        $entry = $this->makeTestEntry($name, ['service_type' => 'region-move', 'trigger_type' => 'none'], (string) ($this->e2eRegion->id ?? ''));
        $entry['startTime'] = microtime(true);
        try {
            $result = $check();
        } catch (\Throwable $e) {
            $result = $e::class.': '.$e->getMessage();
        }
        $entry['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $result === true ? true : (string) $result];
        $entry = $this->finishTest($entry);

        $this->line('  '.($result === true ? '<fg=green>✓</>' : '<fg=red>✗</>')." {$name}".($result === true ? '' : ': '.mb_substr((string) $result, 0, 600)));

        return $entry;
    }

    private function rmSkip(string $name): array
    {
        $entry = $this->makeTestEntry($name, ['service_type' => 'region-move', 'trigger_type' => 'none'], (string) ($this->e2eRegion->id ?? ''));
        $entry['steps'] = ['version' => 'skipped', 'deploy' => 'skipped', 'ready' => 'skipped', 'verify' => 'skipped'];

        return $entry;
    }
}
