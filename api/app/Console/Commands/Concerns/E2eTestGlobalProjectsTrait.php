<?php

namespace App\Console\Commands\Concerns;

use App\Jobs\Bindings\BindingsMaintenanceJob;
use App\Jobs\GlobalProjects\GlobalProjectsMaintenanceJob;
use App\Models\AuditEvent;
use App\Models\Binding;
use App\Models\BindingReplica;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\ProjectRegion;
use App\Models\RegionGlobalDns;
use App\Models\UploadedImage;
use App\Models\User;
use App\Services\Bindings\BindingsCluster;
use App\Services\Bindings\DataServices\PostgresService;
use App\Services\Bindings\Ops\BindingOpsRunner;
use App\Services\Bindings\Ops\OpsTask;
use App\Services\GatewayService;
use App\Services\GlobalDns\DnsWire;
use App\Services\GlobalDns\GlobalDnsServerService;
use App\Services\GlobalDns\GlobalDnsZone;
use App\Services\GlobalDns\PowerDnsClient;
use App\Services\GlobalDns\PowerDnsManifests;
use App\Services\GlobalProjects\Data\DataLinkManifests;
use App\Services\GlobalProjects\Data\RegionalPostgresManifests;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\InternalNetwork\InternalNetworkManifests;
use App\Services\InternalNetwork\InternalNetworkService;
use App\Services\KubernetesClient;
use App\Services\Manifests\ProjectDomainManifests;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * E2E of global projects (test:e2e --suite=global-projects, runtime/lib/test-global-projects.sh;
 * docs/global-projects.md). The e2e region runs on Kind cluster A; --global-kubeconfig is a second
 * Kind cluster B with nothing of RunR on it, which becomes a second region. Two regions, not two
 * regions on one cluster: a region's copy of a global project is told apart by its own Traefik
 * address, its own agent and its own DNS server, which regions sharing a cluster share.
 *
 *  - gp-zone          the platform has a base domain (the suite sets one when it has none): the
 *                     zone is g.<base domain>
 *  - gp-region-b      region B is created from B's kubeconfig and Knative Serving is installed there
 *  - gp-dns-servers   global DNS is enabled on A and B: both servers become ready with a load
 *                     balancer address (Cloud Provider KIND); the agents carry the global-health
 *                     listener (with --global-agent-image: the agent built from this checkout)
 *  - gp-create        a global CaaS project (nginx), HA, A primary and B, through POST /projects
 *  - gp-rollout       its deploy becomes active on A, then B's copy is ready with the same version
 *  - gp-serves        <slug>.<zone> answers through A's and through B's Traefik address
 *  - gp-access        each copy answers its internal name (IngressRoute of
 *                     <project>.<organization>.runr.internal in A and B); access mode allowlist
 *                     192.0.2.0/24 puts the allow-list middleware on the global routes of both
 *                     regions (403 through A and B), 0.0.0.0/0 admits (200), public again (200)
 *  - gp-health        the health route of both regions answers "ok" for the project, 404 for another
 *  - gp-zone-sync     both servers hold the same zone: NS of both, the project's LUA record
 *  - gp-dns-ha        both servers answer the project's name with A's address (HA: the primary),
 *                     asked over TCP on their load balancer addresses
 *  - gp-failover      A's Knative Service goes: A's health check fails and both servers answer B's
 *                     address within a minute and a half
 *  - gp-proximity     mode proximity: the record picks the closest region (pickclosest)
 *  - gp-delete        the project is deleted: its copies leave A and B, its record leaves the zone
 *
 * Phase 2 (triggers, gateways, domains, uploaded images and data across regions; a case whose
 * prerequisite failed is skipped):
 *
 *  - gp-triggers         a cron-only global CaaS project (a schedule every five minutes): its CronJob
 *                        <slug>-trigger runs in A (not suspended), is suspended in B; GET /global
 *                        says triggers_active for A only
 *  - gp-p2-project       a second global nginx project (HA, A primary and B), deployed and ready in
 *                        both regions — the project of the cases below
 *  - gp-gateway          a gateway of region B with a route to it: https://<gateway host>/e2e-gp/
 *                        through B's Traefik answers nginx (the route hands requests to B's copy)
 *  - gp-domain           a custom domain (taken as verified: no DNS on Kind; TLS none) routed in
 *                        both regions: its IngressRoutes in A and B, the domain answers through A's
 *                        and B's address, the domain resource lists both regions active
 *  - gp-uploaded-image   the nginx image (a docker save archive prepared by
 *                        runtime/lib/test-global-projects.sh) uploaded into A; a global CaaS project
 *                        running it: B's copy is ready, B has the upload (same digest) as an uploaded
 *                        image of its own, B's Knative Service runs it from B's registry
 *  - gp-data-services    KV and Postgres data services on A, Postgres on B (the CloudNativePG
 *                        operator of the replica cluster)
 *  - gp-data-link        a KV binding of A attached (KV_): rows home A / link B ready, B's forwarder
 *                        runr-link-<binding> available, B's KV_REDIS_URL names the link; redis-cli in
 *                        B's data namespace: PING → PONG, SET / GET under the prefix — through A's
 *                        Traefik with mutual TLS
 *  - gp-postgres-replica a dedicated Postgres binding of A attached (PGD_): B's replica cluster
 *                        streams (row ready); a row written through A's PGD_DATABASE_URL is read
 *                        through B's PGD_DATABASE_URL_RO (B's own replica), a row written through B's
 *                        PGD_DATABASE_URL (the link) is read in A
 *  - gp-switchover       PUT /global primary B: the binding's home and the project's primary are B,
 *                        B's cluster is out of recovery, A's is in recovery and streams, both DNS
 *                        servers answer B's address
 *  - gp-data-failover    B's Knative Service deleted, POST /global/failover A: A's cluster promoted,
 *                        B fenced; B rejoins (GlobalProjectsMaintenanceJob, run here every loop):
 *                        its row ready with data_status ready, its cluster rebuilt as a replica
 *  - gp-geoip            region B's GeoIP source none → off, an unreachable URL → missing with the
 *                        download error, file → missing (no file on the volume), default again
 *  - gp-cleanup          everything the cases created (domain, gateway route, bindings purged,
 *                        projects, uploaded images, data services), global DNS disabled on both
 *                        regions, region B removed
 *
 * Not on Kind: the delegation of the zone (no public parent zone) and the ACME certificates (the
 * wildcard and a custom domain's DNS-01 through the zone: no ACME server reaches the cluster): the
 * suite asks the servers directly and calls over HTTP.
 */
trait E2eTestGlobalProjectsTrait
{
    private const GP_NGINX = 'ghcr.io/brf-tech/runr/reqs/nginx:1.27-alpine';

    /** A case's answer when what it needs cannot be had on this cluster: "SKIP: <why>". */
    private const GP_SKIP = 'SKIP: ';

    /** The custom domain of gp-domain (never resolved: the suite sends it as the Host). */
    private const GP_DOMAIN = 'shop.gp-e2e.test';

    private function runGlobalProjectsSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Global projects (two regions, PowerDNS, failover) ──');

        $file = (string) $this->option('global-kubeconfig');
        $kubeconfigB = $file !== '' && is_file($file) ? trim((string) file_get_contents($file)) : '';
        if ($kubeconfigB === '') {
            return [$this->gpCheck('gp-region-b', fn () => "no kubeconfig of the second cluster (--global-kubeconfig={$file}); runtime/lib/test-global-projects.sh creates it")];
        }

        $c = [
            'regionA' => $regionId,
            'regionB' => null,
            'kubeconfigB' => $kubeconfigB,
            'agentImage' => (string) $this->option('global-agent-image'),
            'zone' => null,
            'projectId' => null,
            'slug' => null,
            'baseDomainSet' => false,
            // phase 2: what the cases created (gp-cleanup removes it)
            'p2Id' => null,
            'p2Slug' => null,
            'projects' => [],
            'uploads' => [],
            'gatewayId' => null,
            'gatewayRouteId' => null,
            'domainId' => null,
            'bindings' => [],
            'services' => [],
            'geoipTouched' => false,
        ];

        // the steps fill $c (region B, zone, project) for the ones after them: by reference
        $steps = [
            'gp-zone' => function () use (&$c) {
                return $this->gpZone($c);
            },
            'gp-region-b' => function () use (&$c) {
                return $this->gpRegionB($c);
            },
            'gp-dns-servers' => function () use (&$c) {
                return $this->gpDnsServers($c);
            },
            'gp-create' => function () use (&$c) {
                return $this->gpCreate($c);
            },
            'gp-rollout' => function () use (&$c) {
                return $this->gpRollout($c);
            },
            'gp-serves' => function () use (&$c) {
                return $this->gpServes($c);
            },
            'gp-access' => function () use (&$c) {
                return $this->gpAccess($c);
            },
            'gp-health' => function () use (&$c) {
                return $this->gpHealth($c);
            },
            'gp-zone-sync' => function () use (&$c) {
                return $this->gpZoneSync($c);
            },
            'gp-dns-ha' => function () use (&$c) {
                return $this->gpDnsAnswers($c, 'A');
            },
            'gp-failover' => function () use (&$c) {
                return $this->gpFailover($c);
            },
            'gp-proximity' => function () use (&$c) {
                return $this->gpProximity($c);
            },
            'gp-delete' => function () use (&$c) {
                return $this->gpDelete($c);
            },
            // phase 2 (docs/global-projects.md: triggers, gateways, custom domains, uploaded
            // images, data across regions, failover, offline GeoIP)
            'gp-triggers' => function () use (&$c) {
                return $this->gpTriggers($c);
            },
            'gp-p2-project' => function () use (&$c) {
                return $this->gpPhase2Project($c);
            },
            'gp-gateway' => function () use (&$c) {
                return $this->gpGateway($c);
            },
            'gp-domain' => function () use (&$c) {
                return $this->gpDomain($c);
            },
            'gp-uploaded-image' => function () use (&$c) {
                return $this->gpUploadedImage($c);
            },
            'gp-data-services' => function () use (&$c) {
                return $this->gpDataServices($c);
            },
            'gp-data-link' => function () use (&$c) {
                return $this->gpDataLink($c);
            },
            'gp-postgres-replica' => function () use (&$c) {
                return $this->gpPostgresReplica($c);
            },
            'gp-switchover' => function () use (&$c) {
                return $this->gpSwitchover($c);
            },
            'gp-data-failover' => function () use (&$c) {
                return $this->gpDataFailover($c);
            },
            'gp-geoip' => function () use (&$c) {
                return $this->gpGeoip($c);
            },
        ];
        // a step these depend on failed: the rest is skipped (gp-cleanup always runs)
        $essential = ['gp-zone', 'gp-region-b', 'gp-dns-servers', 'gp-create', 'gp-rollout'];
        // phase 2: a case runs only when the cases it builds on passed
        $needs = [
            'gp-gateway' => ['gp-p2-project'],
            'gp-domain' => ['gp-p2-project'],
            'gp-data-link' => ['gp-p2-project', 'gp-data-services'],
            'gp-postgres-replica' => ['gp-p2-project', 'gp-data-services'],
            'gp-switchover' => ['gp-postgres-replica'],
            'gp-data-failover' => ['gp-switchover'],
        ];

        // --global-cases / RUNR_E2E_GLOBAL_CASES: only these cases, the setup and what they build on
        $selected = self::gpSelectCases((string) ($this->option('global-cases') ?: (getenv('RUNR_E2E_GLOBAL_CASES') ?: '')), array_keys($steps), $essential, $needs);
        if (is_string($selected)) {
            return [$this->gpCheck('gp-zone', fn () => $selected)];
        }
        if ($selected !== null) {
            $this->line('  cases: '.implode(', ', $selected).' (--global-cases / RUNR_E2E_GLOBAL_CASES, with the setup and what they need)');
        }

        $results = [];
        $blocked = false;
        $passed = [];
        foreach ($steps as $name => $step) {
            if ($selected !== null && ! in_array($name, $selected, true)) {
                continue;
            }
            $missing = array_values(array_diff($needs[$name] ?? [], $passed));
            if ($blocked || $missing !== []) {
                $results[] = $this->gpSkip($name);
                if (! $blocked) {
                    $this->line("  <fg=yellow>─</> {$name}: skipped (needs ".implode(', ', $missing).')');
                }

                continue;
            }
            $entry = $this->gpCheck($name, $step);
            $results[] = $entry;
            if ($entry['steps']['verify'] === true) {
                $passed[] = $name;
            } elseif (in_array($name, $essential, true)) {
                $blocked = true;
            }
        }
        $results[] = $this->gpCheck('gp-cleanup', function () use (&$c) {
            return $this->gpCleanup($c);
        });

        return $results;
    }

    /**
     * The cases --global-cases / RUNR_E2E_GLOBAL_CASES names (comma- or space-separated) with the
     * setup cases ($essential) and, transitively, the cases they build on ($needs), in the suite's
     * order; null: every case; a string: names that are no case of the suite.
     *
     * @param  list<string>  $all
     * @param  list<string>  $essential
     * @param  array<string, list<string>>  $needs
     * @return list<string>|string|null
     */
    public static function gpSelectCases(string $spec, array $all, array $essential, array $needs): array|string|null
    {
        $names = array_values(array_filter(preg_split('/[\s,]+/', trim($spec)) ?: [], fn ($n) => $n !== ''));
        if ($names === []) {
            return null;
        }
        $unknown = array_values(array_diff($names, $all));
        if ($unknown !== []) {
            return 'RUNR_E2E_GLOBAL_CASES names no case of the suite: '.implode(', ', $unknown).' (the cases: '.implode(', ', $all).')';
        }

        $want = array_fill_keys([...$essential, ...$names], true);
        do {
            $before = count($want);
            foreach (array_keys($want) as $name) {
                foreach ($needs[$name] ?? [] as $need) {
                    $want[$need] = true;
                }
            }
        } while (count($want) !== $before);

        return array_values(array_filter($all, fn ($name) => isset($want[$name])));
    }

    private function gpZone(array &$c): bool|string
    {
        $zone = app(GlobalDnsZone::class);
        if ($zone->baseDomain() === null) {
            $set = $this->api()->patch('/api/settings', ['base_domain' => 'e2e.runr.test']);
            if (! $set->successful()) {
                return 'base domain not set: '.$set->status().' '.mb_substr($set->body(), 0, 200);
            }
            $c['baseDomainSet'] = true;
        }

        $c['zone'] = $zone->name();

        return $c['zone'] !== null ? true : 'no global zone after setting the base domain';
    }

    private function gpRegionB(array &$c): bool|string
    {
        // with its own internal registry (Zot), as the suite's region A: the per-region image
        // store of global copies and uploaded images (gp-uploaded-image)
        $regionB = $this->createRegionWithRegistry('e2e-global-b', $c['kubeconfigB']);
        if ($regionB === null) {
            return 'region B with its Zot registry not created (see the registry setup above)';
        }
        $c['regionB'] = $regionB;
        $registry = KnativeRegion::query()->find($regionB);
        if ($registry?->registry_mode !== 'internal' || ! $registry->registry_address) {
            return 'region B has no internal registry after its setup: '.json_encode($registry?->only(['registry_mode', 'registry_address']));
        }

        $install = $this->api()->timeout(60)->post("/api/knative-regions/{$c['regionB']}/install-knative");
        if (! $install->successful()) {
            return 'Knative install on B not started: '.$install->status().' '.mb_substr($install->body(), 0, 300);
        }

        // Knative Operator + Serving with Traefik, the agent, Prometheus / Loki: minutes
        $deadline = time() + 1500;
        $state = ['status' => null, 'message' => null, 'address' => null];
        do {
            sleep(15);
            $region = KnativeRegion::query()->find($c['regionB']);
            if ($region !== null) {
                $state = ['status' => $region->knative_serving_status, 'message' => $region->knative_serving_status_message, 'address' => $region->external_ip];
            }
            if ($state['status'] === 'ready' && $state['address']) {
                return true;
            }
        } while (time() < $deadline && $state['status'] !== 'error');

        return 'Knative Serving on B: '.json_encode($state);
    }

    private function gpDnsServers(array &$c): bool|string
    {
        foreach ([$c['regionA'], $c['regionB']] as $regionId) {
            $enable = $this->api()->timeout(60)->post("/api/knative-regions/{$regionId}/global-dns");
            if (! $enable->successful()) {
                return "global DNS of {$regionId} not enabled: ".$enable->status().' '.mb_substr($enable->body(), 0, 300);
            }
        }

        $deadline = time() + 600;
        $servers = [];
        do {
            sleep(10);
            $servers = RegionGlobalDns::query()->whereIn('knative_region_id', [$c['regionA'], $c['regionB']])->get();
            if ($servers->count() === 2 && $servers->every(fn (RegionGlobalDns $s) => $s->isReady())) {
                break;
            }
        } while (time() < $deadline && ! $servers->contains(fn (RegionGlobalDns $s) => $s->status === 'error'));

        if (! ($servers->count() === 2 && $servers->every(fn (RegionGlobalDns $s) => $s->isReady()))) {
            return 'servers: '.json_encode($servers->map->only(['knative_region_id', 'status', 'status_message', 'address'])->all());
        }

        // the agents of both regions with the global-health listener
        foreach ([$c['regionA'], $c['regionB']] as $regionId) {
            $problem = $this->gpAgent(KnativeRegion::query()->findOrFail($regionId), $c['agentImage']);
            if ($problem !== true) {
                return $problem;
            }
        }

        return true;
    }

    /**
     * Waits for the agent RunR deployed again with its global-health listener; with an image of
     * this checkout (Kind-loaded) the agent is switched to it.
     */
    private function gpAgent(KnativeRegion $region, string $image): bool|string
    {
        $k8s = new KubernetesClient((string) $region->kubeconfig);
        $deadline = time() + 300;
        $listening = false;
        do {
            $agent = $k8s->getResourceStatus('apps/v1', 'Deployment', 'runr-agent', GlobalDnsServerService::AGENT_NAMESPACE);
            $listening = collect($agent['spec']['template']['spec']['containers'][0]['env'] ?? [])->pluck('value', 'name')->has('RUNR_GLOBAL_HEALTH_ADDR');
            if ($listening) {
                break;
            }
            sleep(5);
        } while (time() < $deadline);

        if (! $listening) {
            return "the agent of {$region->name} was not deployed again with its global-health listener";
        }

        if ($image !== '') {
            $k8s->strategicMergePatch('apps/v1', 'Deployment', 'runr-agent', GlobalDnsServerService::AGENT_NAMESPACE, [
                'spec' => ['template' => ['spec' => ['containers' => [['name' => 'agent', 'image' => $image, 'imagePullPolicy' => 'IfNotPresent']]]]],
            ]);
        }

        do {
            $agent = $k8s->getResourceStatus('apps/v1', 'Deployment', 'runr-agent', GlobalDnsServerService::AGENT_NAMESPACE);
            if ((int) ($agent['status']['updatedReplicas'] ?? 0) >= 1 && (int) ($agent['status']['availableReplicas'] ?? 0) >= 1
                && (int) ($agent['status']['observedGeneration'] ?? 0) >= (int) ($agent['metadata']['generation'] ?? 0)) {
                return true;
            }
            sleep(5);
        } while (time() < $deadline);

        return "the agent of {$region->name} did not become available";
    }

    private function gpCreate(array &$c): bool|string
    {
        $created = $this->api()->timeout(60)->post('/api/projects', [
            'name' => 'gp-shop',
            'service_type' => 'caas',
            'knative_region_id' => $c['regionA'],
            'placement' => 'global',
            'global_mode' => 'ha',
            'region_ids' => [$c['regionA'], $c['regionB']],
            'config' => ['image' => self::GP_NGINX, 'trigger_type' => 'http', 'ports' => [80]],
        ]);
        if (! $created->successful()) {
            return 'project not created: '.$created->status().' '.mb_substr($created->body(), 0, 400);
        }

        $c['projectId'] = (string) $created->json('id');
        $c['slug'] = (string) $created->json('slug');
        $want = "{$c['slug']}.{$c['zone']}";

        return $created->json('global.hostname') === $want ? true : 'hostname '.json_encode($created->json('global.hostname'))." (want {$want})";
    }

    private function gpRollout(array &$c): bool|string
    {
        // a deploy names its version: the one the project was created with
        $version = $this->api()->get("/api/projects/{$c['projectId']}")->json('data.latest_version.id');
        if (! is_string($version) || $version === '') {
            return 'the project has no version to deploy';
        }
        $deploy = $this->api()->timeout(60)->post("/api/projects/{$c['projectId']}/deploy", ['version_id' => $version]);
        if (! $deploy->successful()) {
            return 'deploy not started: '.$deploy->status().' '.mb_substr($deploy->body(), 0, 300);
        }

        $deadline = time() + 900;
        $global = [];
        do {
            sleep(10);
            $global = (array) $this->api()->get("/api/projects/{$c['projectId']}/global")->json();
            $regions = collect($global['regions'] ?? [])->keyBy('region_id');
            if (($regions[$c['regionA']]['status'] ?? null) === 'ready' && ($regions[$c['regionB']]['status'] ?? null) === 'ready'
                && ($regions[$c['regionB']]['version'] ?? null) === ($regions[$c['regionA']]['version'] ?? -1)) {
                return true;
            }
            if (($regions[$c['regionB']]['status'] ?? null) === 'failed') {
                break;
            }
        } while (time() < $deadline);

        return 'regions: '.json_encode($global['regions'] ?? null);
    }

    private function gpServes(array $c): bool|string
    {
        $host = "{$c['slug']}.{$c['zone']}";
        foreach ([$c['regionA'], $c['regionB']] as $regionId) {
            $region = KnativeRegion::query()->findOrFail($regionId);
            $answer = $this->gpGet((string) $region->external_ip, $host, '/', fn ($r) => $r->status() === 200 && str_contains($r->body(), 'nginx'));
            if ($answer !== true) {
                return "{$host} through {$region->name} ({$region->external_ip}): {$answer}";
            }
        }

        return true;
    }

    /**
     * The global routes follow the project's access mode in every region (GlobalRouteService), and
     * every copy answers its internal name in its region.
     */
    private function gpAccess(array $c): bool|string
    {
        $host = "{$c['slug']}.{$c['zone']}";
        $regions = [KnativeRegion::query()->findOrFail($c['regionA']), KnativeRegion::query()->findOrFail($c['regionB'])];
        $project = Project::query()->findOrFail($c['projectId']);
        $name = InternalNetwork::nameOf($project);

        foreach ($regions as $region) {
            $k8s = new KubernetesClient((string) $region->kubeconfig);
            $route = $k8s->getResourceStatus(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', InternalNetworkManifests::internalRouteName($c['slug']), (string) ($region->project_namespace ?: $region->name));
            if (! str_contains((string) json_encode($route['spec']['routes'] ?? []), InternalNetwork::hostFor($name))) {
                return "{$region->name} has no route of {$name}: ".json_encode($route['spec']['routes'] ?? null);
            }
        }

        $network = app(InternalNetworkService::class);
        $expect = function (int $status, string $label) use ($regions, $host): ?string {
            foreach ($regions as $region) {
                $ok = $this->gpGet((string) $region->external_ip, $host, '/', fn ($r) => $r->status() === $status, 120);
                if ($ok !== true) {
                    return "{$label}: {$host} through {$region->name}: {$ok} (want {$status})";
                }
            }

            return null;
        };

        try {
            // TEST-NET-1: no client is in it
            $network->setAccess($project, Project::ACCESS_ALLOWLIST, ['192.0.2.0/24']);
            if ($problem = $expect(403, 'allowlist 192.0.2.0/24')) {
                return $problem;
            }
            $network->setAccess($project->fresh(), Project::ACCESS_ALLOWLIST, ['0.0.0.0/0']);
            if ($problem = $expect(200, 'allowlist 0.0.0.0/0')) {
                return $problem;
            }
        } finally {
            $network->setAccess(Project::query()->findOrFail($c['projectId']), Project::ACCESS_PUBLIC, null);
        }

        return $expect(200, 'public again') ?? true;
    }

    private function gpHealth(array $c): bool|string
    {
        $host = 'runr-health.'.$c['zone'];
        foreach ([$c['regionA'], $c['regionB']] as $regionId) {
            $region = KnativeRegion::query()->findOrFail($regionId);
            $ok = $this->gpGet((string) $region->external_ip, $host, "/global-health/{$c['slug']}", fn ($r) => $r->status() === 200 && trim($r->body()) === 'ok');
            if ($ok !== true) {
                return "health of {$c['slug']} in {$region->name}: {$ok}";
            }
            $other = $this->gpGet((string) $region->external_ip, $host, '/global-health/no-such-project', fn ($r) => $r->status() === 404, 20);
            if ($other !== true) {
                return "health of an unknown project in {$region->name}: {$other} (want 404)";
            }
        }

        return true;
    }

    private function gpZoneSync(array $c): bool|string
    {
        $canonical = $c['zone'].'.';
        $zones = [];
        $deadline = time() + 180;
        do {
            $zones = [];
            foreach ([$c['regionA'], $c['regionB']] as $regionId) {
                $zones[$regionId] = $this->gpZoneRrsets($regionId, $canonical);
            }
            $lua = "{$c['slug']}.{$canonical}|LUA";
            if (isset($zones[$c['regionA']][$lua]) && $zones[$c['regionA']] === $zones[$c['regionB']]) {
                $ns = $zones[$c['regionA']]["{$canonical}|NS"] ?? [];

                return count($ns) === 2 ? true : 'NS of the zone: '.json_encode($ns).' (want both servers)';
            }
            sleep(15);
        } while (time() < $deadline);

        return 'the zones differ or lack the project: '.json_encode($zones);
    }

    /**
     * The zone's rrsets on a region's server, without the SOA: "name|type" => sorted contents.
     *
     * @return array<string, list<string>>
     */
    private function gpZoneRrsets(string $regionId, string $canonical): array
    {
        $region = KnativeRegion::query()->findOrFail($regionId);
        $server = RegionGlobalDns::forRegion($regionId);
        $client = new PowerDnsClient(new KubernetesClient((string) $region->kubeconfig), GlobalDnsServerService::namespace(), (string) $server?->api_key);

        $out = [];
        foreach ((array) ($client->zone($canonical)['rrsets'] ?? []) as $rrset) {
            if (($rrset['type'] ?? '') === 'SOA') {
                continue;
            }
            $records = array_map(fn ($r) => (string) ($r['content'] ?? ''), (array) ($rrset['records'] ?? []));
            sort($records);
            $out["{$rrset['name']}|{$rrset['type']}"] = $records;
        }
        ksort($out);

        return $out;
    }

    /**
     * Both servers answer the project's name with the address of $expect ('A': region A, 'B': region
     * B) — asked over TCP (Cloud Provider KIND's load balancers pass TCP), polled while the
     * servers' health checks settle.
     */
    private function gpDnsAnswers(array $c, string $expect, int $timeout = 120): bool|string
    {
        $want = (string) KnativeRegion::query()->findOrFail($expect === 'A' ? $c['regionA'] : $c['regionB'])->external_ip;
        $name = "{$c['slug']}.{$c['zone']}";
        $wire = app(DnsWire::class);
        $answers = [];

        $deadline = time() + $timeout;
        do {
            $answers = [];
            foreach ([$c['regionA'], $c['regionB']] as $regionId) {
                $server = (string) RegionGlobalDns::forRegion($regionId)?->address;
                $answer = $wire->query($server, $name, 'A', true);
                $answers[$server] = $answer === null ? null : array_column(array_filter($answer['answers'], fn ($a) => $a['type'] === 'A'), 'data');
            }
            if (array_filter($answers, fn ($ips) => $ips !== [$want]) === []) {
                return true;
            }
            sleep(5);
        } while (time() < $deadline);

        return "{$name}: want [{$want}] from both servers, got ".json_encode($answers);
    }

    private function gpFailover(array $c): bool|string
    {
        $regionA = KnativeRegion::query()->findOrFail($c['regionA']);
        (new KubernetesClient((string) $regionA->kubeconfig))->deleteResource('serving.knative.dev/v1', 'Service', $c['slug'], $regionA->project_namespace ?? $regionA->name);

        // ifurlup: 2 failed checks 5 s apart, then the next answers leave A out
        return $this->gpDnsAnswers($c, 'B', 90);
    }

    private function gpProximity(array $c): bool|string
    {
        $changed = $this->api()->put("/api/projects/{$c['projectId']}/global", ['mode' => 'proximity']);
        if (! $changed->successful() || $changed->json('mode') !== 'proximity') {
            return 'mode not changed: '.$changed->status().' '.mb_substr($changed->body(), 0, 300);
        }

        $deadline = time() + 180;
        $record = [];
        do {
            $record = $this->gpZoneRrsets($c['regionB'], $c['zone'].'.')["{$c['slug']}.{$c['zone']}.|LUA"] ?? [];
            if ($record !== [] && str_contains(implode(' ', $record), "selector='pickclosest'")) {
                return true;
            }
            sleep(15);
        } while (time() < $deadline);

        return 'record after the change: '.json_encode($record);
    }

    private function gpDelete(array &$c): bool|string
    {
        $deleted = $this->api()->timeout(120)->delete("/api/projects/{$c['projectId']}");
        if (! $deleted->successful()) {
            return 'project not deleted: '.$deleted->status();
        }

        $problems = [];
        foreach ([$c['regionA'], $c['regionB']] as $regionId) {
            $region = KnativeRegion::query()->findOrFail($regionId);
            $left = (new KubernetesClient((string) $region->kubeconfig))->getResourceStatus('serving.knative.dev/v1', 'Service', $c['slug'], $region->project_namespace ?? $region->name);
            if ($left !== null) {
                $problems[] = "the Knative Service stayed in {$region->name}";
            }
        }
        if (ProjectRegion::query()->where('project_id', $c['projectId'])->exists()) {
            $problems[] = 'project_regions rows stayed';
        }

        $deadline = time() + 180;
        do {
            $records = $this->gpZoneRrsets($c['regionA'], $c['zone'].'.');
            if (! isset($records["{$c['slug']}.{$c['zone']}.|LUA"])) {
                break;
            }
            sleep(15);
        } while (time() < $deadline);
        if (isset($records["{$c['slug']}.{$c['zone']}.|LUA"])) {
            $problems[] = 'the record stayed in the zone';
        }
        $c['projectId'] = null;

        return $problems === [] ? true : implode('; ', $problems);
    }

    // ── Phase 2 ─────────────────────────────────────────────────────────────

    /**
     * A cron-only global project: the CronJob runs in the primary (A) and waits suspended in B; the
     * regions' triggers_active say the same.
     */
    private function gpTriggers(array &$c): bool|string
    {
        [$id, $slug, $problem] = $this->gpCreateProject($c, 'gp-cron', [
            'image' => self::GP_NGINX, 'trigger_type' => 'schedule', 'schedule' => '*/5 * * * *', 'ports' => [80],
        ]);
        if ($problem !== null) {
            return $problem;
        }

        try {
            if (($rollout = $this->gpDeployEverywhere($c, $id, 900)) !== true) {
                return $rollout;
            }

            foreach (['A' => [$c['regionA'], false], 'B' => [$c['regionB'], true]] as $label => [$regionId, $suspended]) {
                $region = KnativeRegion::query()->findOrFail($regionId);
                $cron = (new KubernetesClient((string) $region->kubeconfig))->getResourceStatus('batch/v1', 'CronJob', "{$slug}-trigger", $this->gpNamespace($region));
                if ($cron === null) {
                    return "no CronJob {$slug}-trigger in {$label}";
                }
                if ((bool) ($cron['spec']['suspend'] ?? false) !== $suspended) {
                    return "the CronJob in {$label} is ".(($cron['spec']['suspend'] ?? false) ? 'suspended' : 'active').' (want '.($suspended ? 'suspended' : 'active').')';
                }
            }

            $regions = collect($this->api()->get("/api/projects/{$id}/global")->json('regions') ?? [])->keyBy('region_id');
            if (($regions[$c['regionA']]['triggers_active'] ?? null) !== true || ($regions[$c['regionB']]['triggers_active'] ?? null) !== false) {
                return 'triggers_active: '.json_encode($regions->map(fn ($r) => $r['triggers_active'] ?? null)->all()).' (want A only)';
            }

            return true;
        } finally {
            $this->gpDeleteProject($c, $id);
        }
    }

    /** The project of the phase-2 cases: nginx, HA, A primary and B, ready in both regions. */
    private function gpPhase2Project(array &$c): bool|string
    {
        [$id, $slug, $problem] = $this->gpCreateProject($c, 'gp-data', ['image' => self::GP_NGINX, 'trigger_type' => 'http', 'ports' => [80]]);
        if ($problem !== null) {
            return $problem;
        }
        $c['p2Id'] = $id;
        $c['p2Slug'] = $slug;

        if (($rollout = $this->gpDeployEverywhere($c, $id, 900)) !== true) {
            return $rollout;
        }

        $region = KnativeRegion::query()->findOrFail($c['regionB']);
        $answer = $this->gpGet((string) $region->external_ip, "{$slug}.{$c['zone']}", '/', fn ($r) => $r->status() === 200 && str_contains($r->body(), 'nginx'));

        return $answer === true ? true : "{$slug}.{$c['zone']} through B: {$answer}";
    }

    /** A gateway of region B routes to the global project's copy in B. */
    private function gpGateway(array &$c): bool|string
    {
        $admin = User::query()->where('username', config('app.admin.username'))->first() ?? User::query()->first();
        if ($admin === null) {
            return 'no user to own the gateway';
        }
        $gateway = app(GatewayService::class)->findOrCreateForRegion($admin, KnativeRegion::query()->findOrFail($c['regionB']));
        $c['gatewayId'] = (string) $gateway->id;

        $route = $this->api()->post("/api/gateways/{$gateway->id}/routes", ['project_id' => $c['p2Id'], 'path' => '/e2e-gp']);
        if ($route->status() !== 201) {
            return 'adding the route answered '.$route->status().': '.mb_substr($route->body(), 0, 300);
        }
        $c['gatewayRouteId'] = (string) $route->json('data.id');

        $host = (string) $this->api()->get("/api/gateways/{$gateway->id}")->json('data.host');
        $ip = (string) KnativeRegion::query()->findOrFail($c['regionB'])->external_ip;
        if ($host === '' || $ip === '') {
            return "gateway host {$host}, region B address {$ip}";
        }

        // HTTPS on websecure with the gateway's host as SNI and Host, resolved to B's Traefik
        $last = 'no answer';
        $deadline = time() + 240;
        do {
            try {
                $response = Http::timeout(15)->withOptions(['verify' => false, 'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$ip}"]]])->get("https://{$host}/e2e-gp/");
                if ($response->status() === 200 && str_contains($response->body(), 'nginx')) {
                    return true;
                }
                $last = 'HTTP '.$response->status().' '.mb_substr(trim($response->body()), 0, 160);
            } catch (\Throwable $e) {
                $last = mb_substr($e->getMessage(), 0, 200);
            }
            sleep(5);
        } while (time() < $deadline);

        return "https://{$host}/e2e-gp/ through B ({$ip}): {$last}";
    }

    /**
     * A custom domain of the global project is routed by both regions (TLS none: no ACME on Kind;
     * no DNS either: the domain is taken as verified).
     */
    private function gpDomain(array &$c): bool|string
    {
        $added = $this->api()->post("/api/projects/{$c['p2Id']}/domains", ['domain' => self::GP_DOMAIN]);
        if ($added->status() !== 201) {
            return 'adding the domain answered '.$added->status().': '.mb_substr($added->body(), 0, 300);
        }
        $c['domainId'] = $id = (string) $added->json('data.id');

        $tls = $this->api()->put("/api/projects/{$c['p2Id']}/domains/{$id}/tls", ['mode' => 'none']);
        if (! $tls->successful()) {
            return 'TLS none answered '.$tls->status().': '.mb_substr($tls->body(), 0, 300);
        }
        ProjectDomain::query()->whereKey($id)->update(['status' => ProjectDomain::STATUS_VERIFIED]);
        $this->api()->timeout(120)->post("/api/projects/{$c['p2Id']}/domains/{$id}/verify");

        $name = ProjectDomainManifests::resourceName($c['p2Id'], self::GP_DOMAIN);
        foreach (['A' => $c['regionA'], 'B' => $c['regionB']] as $label => $regionId) {
            $region = KnativeRegion::query()->findOrFail($regionId);
            $route = (new KubernetesClient((string) $region->kubeconfig))->getResourceStatus(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', $name, $this->gpNamespace($region));
            if ($route === null) {
                return "no IngressRoute {$name} in {$label}";
            }
            $answer = $this->gpGet((string) $region->external_ip, self::GP_DOMAIN, '/', fn ($r) => $r->status() === 200 && str_contains($r->body(), 'nginx'));
            if ($answer !== true) {
                return self::GP_DOMAIN." through {$label}: {$answer}";
            }
        }

        $domain = collect($this->api()->get("/api/projects/{$c['p2Id']}/domains")->json('data') ?? [])->firstWhere('id', $id) ?? [];
        $states = (array) ($domain['regions'] ?? []);
        foreach (['A' => $c['regionA'], 'B' => $c['regionB']] as $label => $regionId) {
            if (($states[$regionId]['status'] ?? null) !== 'active') {
                return "the domain's state in {$label}: ".json_encode($states[$regionId] ?? null).' (status '.($domain['status'] ?? '?').': '.($domain['status_message'] ?? '-').')';
            }
        }

        return true;
    }

    /**
     * A global CaaS project running an image uploaded into A: B gets a copy in its own registry
     * (an uploaded image of B with the same digest) and runs it from there.
     */
    private function gpUploadedImage(array &$c): bool|string
    {
        $file = rtrim((string) (getenv('RUNR_E2E_GLOBAL_IMAGES_DIR') ?: '/tmp/runr-global-images'), '/').'/nginx.tar';
        if (! is_file($file)) {
            return "{$file} not found (runtime/lib/test-global-projects.sh saves the nginx image there)";
        }
        foreach ([$c['regionA'], $c['regionB']] as $regionId) {
            $region = KnativeRegion::query()->findOrFail($regionId);
            if ($region->registry_mode !== 'internal' || ! $region->registry_address) {
                return "region {$region->name} has no internal registry (the suite sets up Zot in both)";
            }
        }

        $upload = $this->imageUploadEntry('gp-image-upload', $file, 'gp-nginx:upload', $c['regionA'], 'image');
        if ($upload['steps']['verify'] !== true) {
            return 'upload into A: '.json_encode($upload['steps']);
        }
        $image = (array) $upload['image'];
        $c['uploads'][] = ['region' => $c['regionA'], 'digest' => (string) ($image['digest'] ?? '')];

        [$id, $slug, $problem] = $this->gpCreateProject($c, 'gp-upload', ['image' => (string) $image['image_ref'], 'trigger_type' => 'http', 'ports' => [80]]);
        if ($problem !== null) {
            return $problem;
        }
        $c['uploads'][] = ['region' => $c['regionB'], 'digest' => (string) ($image['digest'] ?? '')];

        try {
            // the copy crosses the control plane twice before B deploys
            if (($rollout = $this->gpDeployEverywhere($c, $id, 1800)) !== true) {
                return $rollout;
            }

            $regionB = KnativeRegion::query()->findOrFail($c['regionB']);
            $copy = UploadedImage::query()->where('knative_region_id', $regionB->id)->where('digest', $image['digest'] ?? '')->first();
            if ($copy === null) {
                return 'B has no uploaded image with the digest '.($image['digest'] ?? '?');
            }
            $service = (new KubernetesClient((string) $regionB->kubeconfig))->getResourceStatus('serving.knative.dev/v1', 'Service', $slug, $this->gpNamespace($regionB));
            $runs = (string) ($service['spec']['template']['spec']['containers'][0]['image'] ?? '');
            if (! str_starts_with($runs, rtrim((string) $regionB->registry_address, '/').'/')) {
                return "B's copy runs {$runs}, not an image of B's registry ({$regionB->registry_address})";
            }

            $answer = $this->gpGet((string) $regionB->external_ip, "{$slug}.{$c['zone']}", '/', fn ($r) => $r->status() === 200 && str_contains($r->body(), 'nginx'));

            return $answer === true ? true : "{$slug}.{$c['zone']} through B: {$answer}";
        } finally {
            $this->gpDeleteProject($c, $id);
        }
    }

    /** KV and Postgres on A (the bindings' home), Postgres on B (the replica's operator). */
    private function gpDataServices(array &$c): bool|string
    {
        foreach ([[$c['regionA'], 'kv', ['mode' => 'managed', 'maxmemory_mb' => 128, 'storage' => '256Mi']],
            [$c['regionA'], 'postgres', ['instances' => 1, 'storage' => '1Gi']],
            [$c['regionB'], 'postgres', ['instances' => 1, 'storage' => '1Gi']]] as [$regionId, $service, $options]) {
            $installed = $this->installDataService($regionId, $service, $options);
            $c['services'][] = [$regionId, $service];
            if ($installed !== true) {
                return "{$service} on ".KnativeRegion::query()->find($regionId)?->name.": {$installed}";
            }
        }

        return true;
    }

    /**
     * A KV binding of A attached to the project: B reaches it through the data link — redis-cli in
     * B's data namespace with B's KV_REDIS_URL answers PING and keeps a key.
     */
    private function gpDataLink(array &$c): bool|string
    {
        $created = $this->createBinding($c['regionA'], 'gp-cache', ['type' => 'kv', 'settings' => ['memory_mb' => 16]]);
        if (isset($this->e2eBindings['gp-cache'])) {
            $c['bindings']['gp-cache'] = $this->e2eBindings['gp-cache'];
        }
        if ($created !== true) {
            return "binding: {$created}";
        }
        $attached = $this->api()->post("/api/projects/{$c['p2Id']}/bindings", ['binding_id' => $c['bindings']['gp-cache'], 'env_prefix' => 'KV_']);
        if ($attached->status() !== 201) {
            return 'attach: HTTP '.$attached->status().' '.mb_substr($attached->body(), 0, 300);
        }

        $binding = Binding::query()->findOrFail($c['bindings']['gp-cache']);
        $rows = $this->gpWaitReplicas($binding, [$c['regionA'] => BindingReplica::KIND_HOME, $c['regionB'] => BindingReplica::KIND_LINK], 600);
        if ($rows !== true) {
            return $rows;
        }

        $regionB = KnativeRegion::query()->findOrFail($c['regionB']);
        $forwarder = (new KubernetesClient((string) $regionB->kubeconfig))->getResourceStatus('apps/v1', 'Deployment', DataLinkManifests::name((string) $binding->resource_name), BindingsCluster::dataNamespace());
        if ((int) ($forwarder['status']['availableReplicas'] ?? 0) < 1) {
            return 'the forwarder in B is not available: '.json_encode($forwarder['status'] ?? null);
        }

        // the attachment's env prefix KV_ before the binding's keys REDIS_URL and KV_PREFIX
        $env = $this->gpBindingEnv($regionB, $c['p2Slug']);
        $url = (string) ($env['KV_REDIS_URL'] ?? '');
        if (! str_contains($url, DataLinkManifests::host((string) $binding->resource_name, BindingsCluster::dataNamespace()))) {
            return "B's KV_REDIS_URL does not name the data link: ".preg_replace('#//[^@]*@#', '//***@', $url);
        }
        $prefix = (string) ($env['KV_KV_PREFIX'] ?? '');
        if ($prefix === '' || $prefix !== $binding->kvPrefix()) {
            return "B's KV_KV_PREFIX is ".json_encode($prefix).' (the binding\'s key prefix: '.$binding->kvPrefix().')';
        }

        $result = app(BindingOpsRunner::class)->run($regionB, new OpsTask('gp-e2e-kv', (string) config('bindings.kv.image'), implode("\n", [
            'set -e',
            'url=$(cat /ops/url); prefix=$(cat /ops/prefix)',
            'echo "PING $(redis-cli --no-auth-warning -u "$url" PING)"',
            'echo "SET $(redis-cli --no-auth-warning -u "$url" SET "${prefix}gp-e2e" linked)"',
            'echo "GET $(redis-cli --no-auth-warning -u "$url" GET "${prefix}gp-e2e")"',
            'echo RUNR_OK',
        ]), ['url' => $url, 'prefix' => $prefix]));

        if (! $result->ok || ($result->marked('PING')[0] ?? null) !== 'PONG' || ($result->marked('GET')[0] ?? null) !== 'linked') {
            return 'redis-cli through the link in B: '.$result->tail();
        }

        $data = collect($this->api()->get("/api/projects/{$c['p2Id']}/global")->json('regions') ?? [])->firstWhere('region_id', $c['regionB']);

        return ($data['data_status'] ?? null) === 'ready' ? true : "B's data_status: ".json_encode($data['data_status'] ?? null).' '.($data['data_message'] ?? '');
    }

    /**
     * A dedicated Postgres binding of A attached to the project: B's replica cluster streams; a row
     * written in A is read from B's replica (DATABASE_URL_RO), a row written in B through the link
     * (DATABASE_URL) is read in A.
     */
    private function gpPostgresReplica(array &$c): bool|string
    {
        $created = $this->createBinding($c['regionA'], 'gp-pgd', ['type' => 'postgres', 'plan' => 'dedicated', 'settings' => ['storage_gb' => 1, 'instances' => 1]]);
        if (isset($this->e2eBindings['gp-pgd'])) {
            $c['bindings']['gp-pgd'] = $this->e2eBindings['gp-pgd'];
        }
        if ($created !== true) {
            return "binding: {$created}";
        }
        $attached = $this->api()->post("/api/projects/{$c['p2Id']}/bindings", ['binding_id' => $c['bindings']['gp-pgd'], 'env_prefix' => 'PGD_']);
        if ($attached->status() !== 201) {
            return 'attach: HTTP '.$attached->status().' '.mb_substr($attached->body(), 0, 300);
        }

        $binding = Binding::query()->findOrFail($c['bindings']['gp-pgd']);
        // B's replica: pg_basebackup through the link, then streaming
        $rows = $this->gpWaitReplicas($binding, [$c['regionA'] => BindingReplica::KIND_HOME, $c['regionB'] => BindingReplica::KIND_REPLICA], 1500);
        if ($rows !== true) {
            return $rows;
        }

        $regionA = KnativeRegion::query()->findOrFail($c['regionA']);
        $regionB = KnativeRegion::query()->findOrFail($c['regionB']);
        $envA = $this->gpBindingEnv($regionA, $c['p2Slug']);
        $envB = $this->gpBindingEnv($regionB, $c['p2Slug']);
        $link = DataLinkManifests::host((string) $binding->resource_name, BindingsCluster::dataNamespace());
        if (! str_contains((string) ($envB['PGD_DATABASE_URL'] ?? ''), $link)) {
            return "B's PGD_DATABASE_URL does not name the data link";
        }
        if (! str_contains((string) ($envB['PGD_DATABASE_URL_RO'] ?? ''), $binding->dedicatedClusterName().'-r.')) {
            return "B's PGD_DATABASE_URL_RO does not name B's replica (PGD_PGHOST_RO ".json_encode($envB['PGD_PGHOST_RO'] ?? null).')';
        }

        $token = 'gp'.bin2hex(random_bytes(4));
        $write = $this->gpPsql($regionA, (string) $envA['PGD_DATABASE_URL'], "CREATE TABLE IF NOT EXISTS gp_e2e (id text PRIMARY KEY, region text, at timestamptz DEFAULT now()); INSERT INTO gp_e2e (id, region) VALUES ('a-{$token}', 'A');");
        if (! is_array($write)) {
            return "write in A: {$write}";
        }

        $read = 'not read';
        $deadline = time() + 90;
        do {
            $rows = $this->gpPsql($regionB, (string) $envB['PGD_DATABASE_URL_RO'], "SELECT count(*) FROM gp_e2e WHERE id = 'a-{$token}';");
            $read = is_array($rows) ? ($rows[0] ?? 'no row') : $rows;
            if ($read === '1') {
                break;
            }
            sleep(5);
        } while (time() < $deadline);
        if ($read !== '1') {
            return "A's row read from B's replica: {$read}";
        }

        $written = $this->gpPsql($regionB, (string) $envB['PGD_DATABASE_URL'], "INSERT INTO gp_e2e (id, region) VALUES ('b-{$token}', 'B');");
        if (! is_array($written)) {
            return "write in B through the link: {$written}";
        }
        $back = $this->gpPsql($regionA, (string) $envA['PGD_DATABASE_URL'], "SELECT count(*) FROM gp_e2e WHERE id = 'b-{$token}';");

        return is_array($back) && ($back[0] ?? null) === '1' ? true : "B's row read in A: ".json_encode($back);
    }

    /**
     * A planned switchover to B: the dedicated binding's primary moves first (demotion token), then
     * the project's primary and the DNS answer.
     */
    private function gpSwitchover(array &$c): bool|string
    {
        $moved = $this->api()->put("/api/projects/{$c['p2Id']}/global", ['primary_region_id' => $c['regionB']]);
        if (! $moved->successful()) {
            return 'PUT /global answered '.$moved->status().': '.mb_substr($moved->body(), 0, 300);
        }

        $binding = Binding::query()->findOrFail($c['bindings']['gp-pgd']);
        $regionA = KnativeRegion::query()->findOrFail($c['regionA']);
        $regionB = KnativeRegion::query()->findOrFail($c['regionB']);

        $state = [];
        $deadline = time() + 1200;
        do {
            sleep(15);
            dispatch_sync(new GlobalProjectsMaintenanceJob('data'));
            $binding->refresh();
            $project = Project::query()->find($c['p2Id']);
            $global = (array) $this->api()->get("/api/projects/{$c['p2Id']}/global")->json();
            $state = [
                'binding_home' => (string) $binding->knative_region_id === (string) $regionB->id,
                'project_primary' => (string) $project?->knative_region_id === (string) $regionB->id,
                'moving' => $global['moving_to'] ?? null,
                'B' => $this->gpPgState($binding, $regionB),
                'A' => $this->gpPgState($binding, $regionA),
            ];
            if ($state['binding_home'] && $state['project_primary'] && $state['moving'] === null
                && ($state['B']['primary'] ?? false) === true && ($state['A']['primary'] ?? true) === false && $state['A']['streaming'] === true) {
                break;
            }
        } while (time() < $deadline);

        if (! $state['binding_home'] || ! $state['project_primary'] || ($state['B']['primary'] ?? false) !== true
            || ($state['A']['primary'] ?? true) !== false || $state['A']['streaming'] !== true) {
            return 'after the switchover: '.json_encode($state);
        }

        return $this->gpDnsAnswers(['slug' => $c['p2Slug']] + $c, 'B', 240);
    }

    /**
     * A failover back to A while B's copy does not answer: B's cluster still answers, so its data
     * is handed over to A (as in a switchover; promoted at once when it does not hand over), B is
     * fenced; B rejoins — its cluster a replica of A again, the active version rolled out.
     */
    private function gpDataFailover(array &$c): bool|string
    {
        $binding = Binding::query()->findOrFail($c['bindings']['gp-pgd']);
        $regionA = KnativeRegion::query()->findOrFail($c['regionA']);
        $regionB = KnativeRegion::query()->findOrFail($c['regionB']);

        (new KubernetesClient((string) $regionB->kubeconfig))->deleteResource('serving.knative.dev/v1', 'Service', $c['p2Slug'], $this->gpNamespace($regionB));

        $since = now()->subSecond();
        $failover = $this->api()->post("/api/projects/{$c['p2Id']}/global/failover", ['region_id' => $c['regionA']]);
        if ($failover->status() !== 202) {
            return 'POST /global/failover answered '.$failover->status().': '.mb_substr($failover->body(), 0, 300);
        }

        // promoted: the binding's home and the project's primary are A, A's cluster writes; B fenced
        $sawFenced = false;
        $state = [];
        $deadline = time() + 900;
        do {
            sleep(10);
            $binding->refresh();
            $rowB = ProjectRegion::query()->where('project_id', $c['p2Id'])->where('knative_region_id', $regionB->id)->first();
            $sawFenced = $sawFenced || $rowB?->status === ProjectRegion::STATUS_FENCED || $rowB?->fenced_at !== null
                || $this->gpRejoined($c['p2Id'], $regionB, $since);
            $state = [
                'binding_home' => (string) $binding->knative_region_id === (string) $regionA->id,
                'project_primary' => (string) Project::query()->find($c['p2Id'])?->knative_region_id === (string) $regionA->id,
                'A' => $this->gpPgState($binding, $regionA),
                'B_row' => $rowB?->status,
            ];
            if ($state['binding_home'] && $state['project_primary'] && ($state['A']['primary'] ?? false) === true && $sawFenced) {
                break;
            }
        } while (time() < $deadline);
        if (! $state['binding_home'] || ! $state['project_primary'] || ($state['A']['primary'] ?? false) !== true || ! $sawFenced) {
            return 'after the failover: '.json_encode($state + ['saw_fenced' => $sawFenced]);
        }

        // the rejoin: RunR's minute jobs, run here as well so the case does not wait for the scheduler
        $deadline = time() + 1800;
        do {
            dispatch_sync(new GlobalProjectsMaintenanceJob('failover'));
            dispatch_sync(new GlobalProjectsMaintenanceJob('data'));
            $rowB = ProjectRegion::query()->where('project_id', $c['p2Id'])->where('knative_region_id', $regionB->id)->first();
            $pg = $this->gpPgState($binding, $regionB);
            $state = ['B_row' => $rowB?->status, 'B_data' => $rowB?->data_status, 'B_message' => $rowB?->status_message, 'B_cluster' => $pg];
            if ($rowB?->status === ProjectRegion::STATUS_READY && $rowB->data_status === ProjectRegion::DATA_READY
                && ($pg['primary'] ?? true) === false && $pg['streaming'] === true) {
                return true;
            }
            sleep(20);
        } while (time() < $deadline);

        return 'B did not rejoin: '.json_encode($state);
    }

    /** RunR rejoined $region's fenced copy of the project since $since (audit project.global.rejoin). */
    private function gpRejoined(string $projectId, KnativeRegion $region, \DateTimeInterface $since): bool
    {
        return AuditEvent::query()->where('action', 'project.global.rejoin')->where('target_id', $projectId)
            ->where('occurred_at', '>=', $since)->get()
            ->contains(fn (AuditEvent $e) => ($e->metadata['region'] ?? null) === $region->name);
    }

    /**
     * Region B's GeoIP source: none → off; an unreachable URL → missing with the download error;
     * file (nothing on the volume) → missing; the default again.
     */
    private function gpGeoip(array &$c): bool|string
    {
        $c['geoipTouched'] = true;
        try {
            foreach ([
                ['none', null, 'off', null],
                // TEST-NET-1: never answers (the download times out)
                ['url', 'http://203.0.113.1/geo.mmdb.gz', 'missing', '203.0.113.1'],
                ['file', null, 'missing', 'geoip.mmdb'],
            ] as [$source, $url, $status, $contains]) {
                $result = $this->gpGeoipSource($c, $source, $url);
                if (! is_array($result)) {
                    return "source {$source}: {$result}";
                }
                if (($result['status'] ?? null) !== $status || ($contains !== null && ! str_contains((string) ($result['message'] ?? ''), $contains))) {
                    return "source {$source}: ".json_encode($result)." (want {$status}".($contains !== null ? " naming {$contains}" : '').')';
                }
            }

            return true;
        } finally {
            $this->api()->put("/api/knative-regions/{$c['regionB']}/global-dns/geoip", ['source' => 'default']);
            $c['geoipTouched'] = false;
        }
    }

    /**
     * Set region B's GeoIP source, wait for the restarted server, read the state its init container
     * reported (as the console does: GET .../global-dns).
     *
     * @return array<string, mixed>|string the geoip object, or what went wrong
     */
    private function gpGeoipSource(array $c, string $source, ?string $url): array|string
    {
        $put = $this->api()->put("/api/knative-regions/{$c['regionB']}/global-dns/geoip", array_filter(['source' => $source, 'url' => $url], fn ($v) => $v !== null));
        if (! $put->successful()) {
            return 'PUT answered '.$put->status().': '.mb_substr($put->body(), 0, 300);
        }

        $region = KnativeRegion::query()->findOrFail($c['regionB']);
        $k8s = new KubernetesClient((string) $region->kubeconfig);
        $server = RegionGlobalDns::forRegion($region);
        $geoip = [];
        // an unreachable URL: two downloads (this month's, the previous one's) time out first
        $deadline = time() + 600;
        do {
            sleep(10);
            $deployment = $k8s->getResourceStatus('apps/v1', 'Deployment', PowerDnsManifests::DEPLOYMENT, GlobalDnsServerService::namespace());
            $rolled = (int) ($deployment['status']['observedGeneration'] ?? 0) >= (int) ($deployment['metadata']['generation'] ?? 1)
                && (int) ($deployment['status']['updatedReplicas'] ?? 0) === 1 && (int) ($deployment['status']['readyReplicas'] ?? 0) === 1
                && (int) ($deployment['status']['replicas'] ?? 0) === 1;
            if (! $rolled || $server === null) {
                continue;
            }
            app(GlobalDnsServerService::class)->refreshGeoip($region, $server->refresh());
            $geoip = (array) $this->api()->get("/api/knative-regions/{$c['regionB']}/global-dns")->json('geoip');
            if (($geoip['source'] ?? null) === $source && in_array($geoip['status'] ?? null, ['loaded', 'missing', 'off'], true)) {
                return $geoip;
            }
        } while (time() < $deadline);

        return 'no state after the restart: '.json_encode($geoip);
    }

    /**
     * What the phase-2 cases left: the domain, the gateway route, the bindings (purged), the
     * projects, the uploaded images, the data services the suite installed, the GeoIP source.
     *
     * @return list<string>
     */
    private function gpPhase2Cleanup(array $c): array
    {
        $problems = [];
        $quiet = function (callable $call, string $what) use (&$problems): void {
            try {
                $call();
            } catch (\Throwable $e) {
                $problems[] = "{$what}: ".mb_substr($e->getMessage(), 0, 200);
            }
        };

        if ($c['geoipTouched'] && $c['regionB'] !== null) {
            $quiet(fn () => $this->api()->put("/api/knative-regions/{$c['regionB']}/global-dns/geoip", ['source' => 'default']), 'GeoIP source');
        }
        if ($c['domainId'] !== null && $c['p2Id'] !== null) {
            $quiet(fn () => $this->api()->timeout(120)->delete("/api/projects/{$c['p2Id']}/domains/{$c['domainId']}"), 'domain');
        }
        if ($c['gatewayRouteId'] !== null && $c['gatewayId'] !== null) {
            $quiet(fn () => $this->api()->timeout(120)->delete("/api/gateways/{$c['gatewayId']}/routes/{$c['gatewayRouteId']}"), 'gateway route');
        }

        // bindings: deleted (detached everywhere: their links and replica clusters go), then purged
        foreach ($c['bindings'] as $name => $id) {
            $quiet(function () use ($id, $name) {
                $this->api()->timeout(120)->delete("/api/bindings/{$id}", ['confirm' => $name]);
                Binding::query()->whereKey($id)->update(['purge_at' => now()->subMinute()]);
            }, "binding {$name}");
        }
        if ($c['bindings'] !== []) {
            $quiet(fn () => dispatch_sync(new BindingsMaintenanceJob('purge')), 'binding purge');
            foreach ($c['bindings'] as $name => $id) {
                if (Binding::query()->whereKey($id)->exists()) {
                    $problems[] = "binding {$name} not purged";
                }
            }
        }

        foreach (array_unique(array_filter(array_merge([$c['p2Id']], $c['projects']))) as $projectId) {
            $quiet(fn () => $this->api()->timeout(180)->delete("/api/projects/{$projectId}"), "project {$projectId}");
        }

        foreach ($c['uploads'] as $upload) {
            $quiet(function () use ($upload) {
                $listed = collect($this->api()->get('/api/images', ['knative_region_id' => $upload['region']])->json('data') ?? [])
                    ->filter(fn ($image) => ($image['digest'] ?? null) === $upload['digest']);
                foreach ($listed as $image) {
                    $this->api()->timeout(120)->delete("/api/images/{$image['id']}");
                }
            }, 'uploaded image');
        }

        // the data services the suite installed (refused while a binding still uses one)
        foreach (array_reverse($c['services']) as [$regionId, $service]) {
            $quiet(function () use ($regionId, $service, &$problems) {
                $removed = $this->api()->timeout(300)->delete("/api/knative-regions/{$regionId}/data-services/{$service}");
                if (! $removed->successful() && $removed->status() !== 404) {
                    $problems[] = "{$service} of {$regionId} not removed: ".$removed->status().' '.mb_substr($removed->body(), 0, 200);
                }
            }, "data service {$service}");
        }

        return $problems;
    }

    // ── Phase 2 helpers ─────────────────────────────────────────────────────

    /**
     * A global project (HA, A primary and B) through POST /projects.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} id, slug, what went wrong
     */
    private function gpCreateProject(array &$c, string $name, array $config): array
    {
        $created = $this->api()->timeout(60)->post('/api/projects', [
            'name' => $name,
            'service_type' => 'caas',
            'knative_region_id' => $c['regionA'],
            'placement' => 'global',
            'global_mode' => 'ha',
            'region_ids' => [$c['regionA'], $c['regionB']],
            'config' => $config,
        ]);
        if (! $created->successful()) {
            return [null, null, "{$name} not created: ".$created->status().' '.mb_substr($created->body(), 0, 400)];
        }

        $id = (string) $created->json('id');
        $c['projects'][] = $id;

        return [$id, (string) $created->json('slug'), null];
    }

    /** Deploy the project's version; both regions' copies serve it. */
    private function gpDeployEverywhere(array $c, string $projectId, int $timeout): bool|string
    {
        $version = $this->api()->get("/api/projects/{$projectId}")->json('data.latest_version.id');
        if (! is_string($version) || $version === '') {
            return 'the project has no version to deploy';
        }
        $deploy = $this->api()->timeout(60)->post("/api/projects/{$projectId}/deploy", ['version_id' => $version]);
        if (! $deploy->successful()) {
            return 'deploy not started: '.$deploy->status().' '.mb_substr($deploy->body(), 0, 300);
        }

        $deadline = time() + $timeout;
        $regions = collect();
        $missed = null;
        do {
            sleep(10);
            try {
                $regions = collect($this->api()->get("/api/projects/{$projectId}/global")->json('regions') ?? [])->keyBy('region_id');
                $missed = null;
            } catch (ConnectionException $e) {
                // an API slowed down by a busy host may leave one answer out: the next look asks again
                $missed = $e->getMessage();

                continue;
            }
            if (($regions[$c['regionA']]['status'] ?? null) === 'ready' && ($regions[$c['regionB']]['status'] ?? null) === 'ready'
                && ($regions[$c['regionB']]['version'] ?? null) === ($regions[$c['regionA']]['version'] ?? -1)) {
                return true;
            }
            if (($regions[$c['regionB']]['status'] ?? null) === 'failed') {
                break;
            }
        } while (time() < $deadline);

        return 'regions: '.json_encode($regions->values()->all()).($missed !== null ? " (last look: {$missed})" : '');
    }

    private function gpDeleteProject(array &$c, ?string $projectId): void
    {
        if ($projectId === null) {
            return;
        }
        try {
            $this->api()->timeout(180)->delete("/api/projects/{$projectId}");
            $c['projects'] = array_values(array_diff($c['projects'], [$projectId]));
        } catch (\Throwable) {
            // gp-cleanup tries again
        }
    }

    private function gpNamespace(KnativeRegion $region): string
    {
        return (string) ($region->project_namespace ?: $region->name);
    }

    /**
     * The binding's rows per region reach their kind and ready (RunR's minute job of regional data
     * is run here as well).
     *
     * @param  array<string, string>  $want  region id => kind
     */
    private function gpWaitReplicas(Binding $binding, array $want, int $timeout): bool|string
    {
        $rows = collect();
        $deadline = time() + $timeout;
        do {
            dispatch_sync(new GlobalProjectsMaintenanceJob('data'));
            $rows = BindingReplica::query()->where('binding_id', $binding->id)->get()->keyBy(fn (BindingReplica $r) => (string) $r->knative_region_id);
            $ok = true;
            foreach ($want as $regionId => $kind) {
                $row = $rows->get($regionId);
                $ok = $ok && $row !== null && $row->kind === $kind && $row->status === BindingReplica::STATUS_READY;
            }
            if ($ok) {
                return true;
            }
            sleep(15);
        } while (time() < $deadline);

        return 'replica rows: '.json_encode($rows->map(fn (BindingReplica $r) => ['kind' => $r->kind, 'status' => $r->status, 'message' => $r->status_message])->all());
    }

    /**
     * The project's bindings env as a region's copy sees it (its Secret <slug>-bindings there).
     *
     * @return array<string, string>
     */
    private function gpBindingEnv(KnativeRegion $region, string $slug): array
    {
        $secret = (new KubernetesClient((string) $region->kubeconfig))->getResourceStatus('v1', 'Secret', "{$slug}-bindings", $this->gpNamespace($region));

        return array_map(fn ($v) => (string) base64_decode((string) $v), (array) ($secret['data'] ?? []));
    }

    /**
     * psql in the region's data namespace with a connection URL (an ops pod): the rows it printed,
     * or what went wrong.
     *
     * @return list<string>|string
     */
    private function gpPsql(KnativeRegion $region, string $url, string $sql): array|string
    {
        if ($url === '') {
            return 'no connection URL';
        }
        $result = app(BindingOpsRunner::class)->run($region, new OpsTask('gp-e2e-psql', (string) config('bindings.postgres.image'), implode("\n", [
            'set -e',
            // to a file first: a failing psql ends the script (no pipeline hides its status)
            'psql "$(cat /ops/url)" -v ON_ERROR_STOP=1 -X -q -At -o /tmp/rows -f /ops/query.sql',
            'sed "s/^/ROW /" /tmp/rows',
            'echo RUNR_OK',
        ]), ['url' => $url, 'query.sql' => $sql."\n"]));

        return $result->ok && $result->hasMarker('RUNR_OK') ? $result->marked('ROW') : $result->tail();
    }

    /**
     * The binding's cluster in a region as its superuser sees it: primary (not in recovery),
     * streaming from the home, replay lag; null while it does not answer.
     *
     * @return array{primary: bool, streaming: bool, lag_seconds: ?int}|null
     */
    private function gpPgState(Binding $binding, KnativeRegion $region): ?array
    {
        try {
            $result = app(PostgresService::class)->sql($region, $binding->dedicatedClusterName(), 'gp-e2e-state', RegionalPostgresManifests::stateSql());

            return $result->ok ? RegionalPostgresManifests::parseState($result->marked('STATE')[0] ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function gpCleanup(array $c): bool|string
    {
        // phase 2 first: what lives in the regions goes before their DNS servers and region B
        $problems = $this->gpPhase2Cleanup($c);
        if ($c['projectId'] !== null) {
            $this->api()->timeout(120)->delete("/api/projects/{$c['projectId']}");
        }

        foreach (array_filter([$c['regionB'], $c['regionA']]) as $regionId) {
            if (RegionGlobalDns::forRegion($regionId) === null) {
                continue;
            }
            $disabled = $this->api()->timeout(60)->delete("/api/knative-regions/{$regionId}/global-dns");
            if (! $disabled->successful()) {
                $problems[] = "global DNS of {$regionId} not disabled: ".$disabled->status().' '.mb_substr($disabled->body(), 0, 200);
            }
        }

        $deadline = time() + 300;
        while (time() < $deadline && RegionGlobalDns::query()->whereIn('knative_region_id', array_filter([$c['regionA'], $c['regionB']]))->exists()) {
            sleep(5);
        }
        if (RegionGlobalDns::query()->whereIn('knative_region_id', array_filter([$c['regionA'], $c['regionB']]))->exists()) {
            $problems[] = 'a DNS server row stayed';
        }

        if ($c['regionB'] !== null) {
            $removed = $this->api()->timeout(120)->delete("/api/knative-regions/{$c['regionB']}");
            if (! $removed->successful()) {
                $problems[] = 'region B not removed: '.$removed->status();
            }
        }

        return $problems === [] ? true : implode('; ', $problems);
    }

    /**
     * GET http://<ip><path> with Host $host until $ok accepts the answer.
     *
     * @param  callable(Response): bool  $ok
     */
    private function gpGet(string $ip, string $host, string $path, callable $ok, int $timeout = 180): bool|string
    {
        if ($ip === '') {
            return 'no address';
        }

        $last = 'no answer';
        $deadline = time() + $timeout;
        do {
            try {
                $response = Http::timeout(15)->withHeaders(['Host' => $host])->get("http://{$ip}{$path}");
                if ($ok($response)) {
                    return true;
                }
                $last = 'HTTP '.$response->status().' '.mb_substr(trim($response->body()), 0, 160);
            } catch (\Throwable $e) {
                $last = mb_substr($e->getMessage(), 0, 200);
            }
            sleep(5);
        } while (time() < $deadline);

        return $last;
    }

    private function gpCheck(string $name, callable $check): array
    {
        $entry = $this->makeTestEntry($name, ['service_type' => 'global-projects', 'trigger_type' => 'none'], (string) ($this->e2eRegion->id ?? ''));
        $entry['startTime'] = microtime(true);
        try {
            $result = $check();
        } catch (\Throwable $e) {
            $result = $e::class.': '.$e->getMessage();
        }

        // "SKIP: <why>": what the case needs cannot be had here (shown, not passed)
        if (is_string($result) && str_starts_with($result, self::GP_SKIP)) {
            $entry['steps'] = ['version' => 'skipped', 'deploy' => 'skipped', 'ready' => 'skipped', 'verify' => 'skipped'];
            $this->line("  <fg=yellow>─</> {$name}: ".mb_substr(substr($result, strlen(self::GP_SKIP)), 0, 600));

            return $this->finishTest($entry);
        }

        $entry['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $result === true ? true : (string) $result];
        $entry = $this->finishTest($entry);

        $this->line('  '.($result === true ? '<fg=green>✓</>' : '<fg=red>✗</>')." {$name}".($result === true ? '' : ': '.mb_substr((string) $result, 0, 600)));

        return $entry;
    }

    private function gpSkip(string $name): array
    {
        $entry = $this->makeTestEntry($name, ['service_type' => 'global-projects', 'trigger_type' => 'none'], (string) ($this->e2eRegion->id ?? ''));
        $entry['steps'] = ['version' => 'skipped', 'deploy' => 'skipped', 'ready' => 'skipped', 'verify' => 'skipped'];

        return $entry;
    }
}
