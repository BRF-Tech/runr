<?php

namespace App\Services\GlobalProjects;

use App\Jobs\Bindings\SyncProjectBindingsJob;
use App\Jobs\GlobalDns\SyncGlobalZoneJob;
use App\Jobs\GlobalProjects\RemoveGlobalReplicaJob;
use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\BindingReplica;
use App\Models\Deployment;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Models\PrivateDatabaseBinding;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Models\RegionGlobalDns;
use App\Services\Ai\Gpu\GpuValidation;
use App\Services\Bindings\BindingEnv;
use App\Services\GlobalDns\GlobalDnsServerService;
use App\Services\GlobalDns\GlobalDnsZone;
use App\Services\GlobalProjects\Data\RegionalData;
use App\Services\Images\UploadedImages;
use App\Services\Residency\RegionPolicy;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Wasm\WasmRuntimes;
use App\Triggers\ProjectTriggers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Global projects (docs/global-projects.md): one project running in several regions behind the
 * global host name <slug>.<zone>.
 *
 *  - the primary region is the project's knative_region_id: builds, logs, metrics, custom domains,
 *    bindings and its Deployment rows stay there, as for a regional project;
 *  - every region — the primary included — has a ProjectRegion row with the state of its copy;
 *  - a deploy goes to the primary; when it is ready GlobalReplicaDeployer rolls the version out
 *    to the other regions; GlobalZoneSync answers <slug>.<zone> with the regions whose copy serves.
 *
 * What a global project may be: xaas / faas / caas with any trigger — HTTP and workflow triggers
 * answer in every region, the other triggers run in the primary only (GlobalTriggers) — any
 * runtime its regions offer (WebAssembly too: every region builds it), an uploaded CaaS image
 * (copied into every region's registry, GlobalImageCopy), GPUs where every region admits them,
 * gateway routes from a gateway in any of its regions and custom domains served by every region
 * (GlobalDomains). Data (docs/global-projects.md, "Data"): external bindings as they are; managed
 * bindings through the data link of their region (RegionalData), a dedicated Postgres binding
 * with a replica cluster in every region.
 */
class GlobalProjectService
{
    /** Seconds a health check of a project's regions is reused (GET /projects/{project}/global). */
    public const HEALTH_CACHE_SECONDS = 30;

    public function __construct(
        private readonly GlobalDnsZone $zone,
        private readonly RegionPolicy $policy,
    ) {}

    // ── Eligibility ─────────────────────────────────────────────────────────

    /** Why a region cannot take part in global projects, or null when it can. */
    public function regionIneligibility(?KnativeRegion $region): ?string
    {
        if (! $region) {
            return __('api.global.region_not_found');
        }
        if ($region->is_demo) {
            return __('api.global.region_demo', ['region' => $region->name]);
        }
        if (! $region->is_active) {
            return __('api.global.region_inactive', ['region' => $region->name]);
        }
        if (! self::globalDnsReady((string) $region->getKey())) {
            return __('api.global.region_no_dns', ['region' => $region->name]);
        }

        return null;
    }

    /** Whether the region runs a ready PowerDNS server of the global zone (central). */
    public static function globalDnsReady(string $regionId): bool
    {
        return (bool) RegionGlobalDns::forRegion($regionId)?->isReady();
    }

    /**
     * Validation of a new global project (StoreProjectRequest): field => message, empty when valid.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function creationErrors(array $input): array
    {
        $errors = [];

        if (! $this->zone->configured()) {
            $errors['placement'] = __('api.global.no_zone');

            return $errors;
        }

        $mode = $input['global_mode'] ?? 'ha';
        if (! in_array($mode, Project::GLOBAL_MODES, true)) {
            $errors['global_mode'] = __('api.global.mode_invalid');
        }

        $ids = $input['region_ids'] ?? null;
        $max = max(2, (int) config('global_dns.max_regions', 8));
        if (! is_array($ids) || count($ids) < 2 || count($ids) > $max || count(array_unique(array_map('strval', $ids))) !== count($ids)) {
            $errors['region_ids'] = __('api.global.region_count', ['max' => $max]);
        } else {
            $primary = (string) ($input['knative_region_id'] ?? '');
            if (! in_array($primary, array_map('strval', $ids), true)) {
                $errors['knative_region_id'] = __('api.global.primary_not_listed');
            }
            foreach (array_values($ids) as $i => $id) {
                $region = is_string($id) ? KnativeRegion::query()->find($id) : null;
                if ($reason = $this->regionIneligibility($region)) {
                    $errors["region_ids.{$i}"] = $reason;

                    continue;
                }
                $check = $this->policy->check($region, RegionPolicy::GLOBAL_REGION);
                if (! $check['allowed']) {
                    $errors["region_ids.{$i}"] = (string) $check['reason'];
                }
            }
        }

        $errors += $this->shapeErrors(
            (string) ($input['service_type'] ?? ''),
            is_string($input['runtime'] ?? null) ? $input['runtime'] : null,
            (array) ($input['config'] ?? []),
            is_string($input['endpoint_type'] ?? null) ? $input['endpoint_type'] : null,
            is_string($input['knative_region_id'] ?? null) ? KnativeRegion::query()->find($input['knative_region_id']) : null,
        );

        if (! isset($errors['region_ids']) && is_array($ids)) {
            $config = (array) ($input['config'] ?? []);
            $trigger = is_string($config['trigger_type'] ?? null) && $config['trigger_type'] !== '' ? $config['trigger_type'] : 'http';
            $primary = is_string($input['knative_region_id'] ?? null) ? KnativeRegion::query()->find($input['knative_region_id']) : null;
            $errors += $this->regionErrors(
                KnativeRegion::query()->whereIn('id', array_values(array_filter($ids, 'is_string')))->get()->all(),
                (string) ($input['service_type'] ?? ''),
                is_string($input['runtime'] ?? null) ? $input['runtime'] : null,
                is_string($input['runtime_version'] ?? null) ? $input['runtime_version'] : null,
                $config,
                $trigger === 'queue' || ($trigger === 'workflow' && ! empty($config['queue_stream'])),
                $primary,
            );
        }

        return $errors;
    }

    /**
     * What every region of a global project must offer for it (docs/global-projects.md, "What
     * each region needs"): its WebAssembly runtime, Redis when a trigger reads a stream (the
     * triggers run in the primary, which can move to any region), the GPUs the region admits (GPU
     * nodes, sandbox, the organization's GPU quota there) and, for an uploaded CaaS image, a
     * registry RunR copies it into.
     *
     * @param  list<KnativeRegion>  $regions
     * @return array<string, string> field => message
     */
    public function regionErrors(array $regions, string $serviceType, ?string $runtime, ?string $runtimeVersion, array $config, bool $needsRedis, ?KnativeRegion $primary, ?string $excludeProjectId = null, string $field = 'region_ids'): array
    {
        $errors = [];
        $manifest = $serviceType !== 'caas' && $runtime ? app(RuntimeRegistry::class)->manifest($runtime) : null;
        $image = $serviceType === 'caas' && is_string($config['image'] ?? null) && $config['image'] !== '' ? $config['image'] : null;
        $uploaded = $image !== null && $primary !== null && app(UploadedImages::class)->forImageRef($primary, $image) !== null;

        foreach ($regions as $region) {
            $problems = [];
            if ($manifest !== null && WasmRuntimes::isWasm($manifest) && ($reason = app(WasmRuntimes::class)->unavailableReason($manifest, (string) $region->getKey()))) {
                $problems[] = $reason;
            }
            if ($needsRedis && ! $region->has_redis && ! $region->is_demo) {
                $problems[] = __('api.global.region_needs_redis', ['region' => $region->name]);
            }
            if ($uploaded && (string) $region->getKey() !== (string) $primary->getKey() && ($region->registry_mode !== 'internal' || ! $region->registry_address)) {
                $problems[] = __('api.global.region_no_registry', ['region' => $region->name]);
            }
            // the primary's GPUs are the project's own check (GpuValidation::check)
            if ((string) $region->getKey() !== (string) $primary?->getKey()) {
                array_push($problems, ...GpuValidation::regionProblems($region, $serviceType, $runtime, $runtimeVersion, $config, $excludeProjectId));
            }

            if ($problems !== []) {
                $errors[$field] = trim(($errors[$field] ?? '').' '.implode(' ', array_values(array_unique($problems))));
            }
        }

        return $errors;
    }

    /**
     * regionErrors() for a stored project and $regions (new regions of PUT /projects/{project}/global,
     * a deploy): its runtime, config, triggers and image.
     *
     * @param  list<KnativeRegion>  $regions
     * @return array<string, string>
     */
    public function projectRegionErrors(Project $project, array $regions, string $field = 'region_ids'): array
    {
        $config = match ($project->service_type) {
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            'caas' => $project->containerConfig,
            default => null,
        };
        $values = $config ? $config->toArray() : [];
        $plan = app(ProjectTriggers::class)->plan($project);

        return $this->regionErrors(
            $regions,
            (string) $project->service_type,
            $project->runtime,
            $project->runtime_version,
            $values,
            $plan->requiresRedis(),
            $project->knativeRegion,
            (string) $project->getKey(),
            $field,
        );
    }

    /**
     * What a global project may not be: a type other than xaas / faas / caas.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public function shapeErrors(string $serviceType, ?string $runtime, array $config, ?string $endpointType, ?KnativeRegion $primary): array
    {
        $errors = [];

        // Every trigger, gateway routes, WebAssembly runtimes, uploaded images and GPUs work in a
        // global project; what each region must offer for them is regionErrors()
        if (! in_array($serviceType, Project::SERVICE_TYPES, true)) {
            $errors['service_type'] = __('api.global.type_unsupported');
        }

        return $errors;
    }

    // ── Membership ──────────────────────────────────────────────────────────

    /**
     * Make a new project global: placement, mode and a row per region (the primary at position 0).
     *
     * @param  list<string>  $regionIds
     */
    public function attach(Project $project, array $regionIds, string $mode): void
    {
        $primary = (string) $project->knative_region_id;
        $ordered = array_values(array_unique(array_merge([$primary], array_map('strval', $regionIds))));

        DB::connection($project->getConnectionName())->transaction(function () use ($project, $ordered, $mode) {
            $project->forceFill(['placement' => Project::PLACEMENT_GLOBAL, 'global_mode' => $mode])->save();
            foreach ($ordered as $position => $regionId) {
                ProjectRegion::query()->firstOrCreate(
                    ['project_id' => $project->getKey(), 'knative_region_id' => $regionId],
                    ['position' => $position, 'status' => ProjectRegion::STATUS_PENDING],
                );
            }
        });
    }

    /**
     * Change mode, primary and / or regions (PUT /projects/{project}/global). The request validated
     * the input (updateErrors()).
     *
     * @param  array{mode?: string, failover?: string, primary_region_id?: string, region_ids?: list<string>}  $data
     */
    public function update(Project $project, array $data): void
    {
        if (isset($data['mode']) && $data['mode'] !== $project->global_mode) {
            $project->forceFill(['global_mode' => $data['mode']])->save();
        }
        if (isset($data['failover']) && $data['failover'] !== $project->globalFailover()) {
            $project->forceFill(['global_failover' => $data['failover']])->save();
        }

        $rows = ProjectRegion::query()->where('project_id', $project->getKey())->get()->keyBy(fn (ProjectRegion $r) => (string) $r->knative_region_id);
        $currentPrimary = (string) $project->knative_region_id;
        $primary = (string) ($data['primary_region_id'] ?? $currentPrimary);
        // the regions as asked; the primary moves with a switchover (below), it stays listed first
        $wanted = isset($data['region_ids'])
            ? array_values(array_unique(array_merge([$currentPrimary, $primary], array_map('strval', $data['region_ids']))))
            : array_values(array_unique(array_merge([$currentPrimary, $primary], $rows->keys()->map(fn ($id) => (string) $id)->all())));

        $added = [];
        foreach ($wanted as $position => $regionId) {
            $row = $rows->get($regionId);
            if ($row) {
                // a region on its way out stays on its way out (its removal is queued)
                if ($row->status !== ProjectRegion::STATUS_REMOVING) {
                    $row->update(['position' => $position]);
                }

                continue;
            }
            $added[] = ProjectRegion::query()->create([
                'project_id' => $project->getKey(),
                'knative_region_id' => $regionId,
                'position' => $position,
                'status' => ProjectRegion::STATUS_PENDING,
            ]);
        }

        foreach ($rows as $regionId => $row) {
            if (! in_array((string) $regionId, $wanted, true) && $row->status !== ProjectRegion::STATUS_REMOVING) {
                $row->update(['status' => ProjectRegion::STATUS_REMOVING, 'status_message' => null, 'rollout_token' => null]);
                RemoveGlobalReplicaJob::dispatch($project, (string) $row->getKey());
            }
        }

        // a new region gets the active version
        /** @var Deployment|null $active */
        $active = $project->activeDeployment()->first();
        if ($active && ! $project->isStopped()) {
            foreach ($added as $row) {
                app(GlobalReplicaDeployer::class)->startRollout($project->fresh() ?? $project, $row, $active);
            }
        }

        // the bindings' data links and replica clusters follow the regions (docs/global-projects.md, "Data")
        if ($added !== []) {
            SyncProjectBindingsJob::dispatch((string) $project->getKey());
        }

        // a new primary: a switchover — the replica clusters first, then the project's primary,
        // its triggers and the DNS answer (GlobalFailover; in the background with replicas)
        if ($primary !== $currentPrimary && ($target = KnativeRegion::query()->find($primary))) {
            app(GlobalFailover::class)->start($project->fresh(['knativeRegion']) ?? $project, $target, 'switchover');
        }

        SyncGlobalZoneJob::dispatch();
    }

    /**
     * Validation of a change (PUT /projects/{project}/global): field => message.
     *
     * @param  array{mode?: string, failover?: string, primary_region_id?: string, region_ids?: list<string>}  $data
     * @return array<string, string>
     */
    public function updateErrors(Project $project, array $data): array
    {
        $errors = [];
        $rows = ProjectRegion::query()->where('project_id', $project->getKey())->get()->keyBy(fn (ProjectRegion $r) => (string) $r->knative_region_id);

        if (isset($data['mode']) && ! in_array($data['mode'], Project::GLOBAL_MODES, true)) {
            $errors['mode'] = __('api.global.mode_invalid');
        }

        $ids = isset($data['region_ids'])
            ? array_map('strval', $data['region_ids'])
            : $rows->keys()->map(fn ($id) => (string) $id)->values()->all();
        $max = max(2, (int) config('global_dns.max_regions', 8));
        if (count($ids) < 2 || count($ids) > $max || count(array_unique($ids)) !== count($ids)) {
            $errors['region_ids'] = __('api.global.region_count', ['max' => $max]);
        }

        foreach ($ids as $i => $id) {
            if ($rows->has($id)) {
                continue;
            }
            $region = KnativeRegion::query()->find($id);
            if ($reason = $this->regionIneligibility($region)) {
                $errors["region_ids.{$i}"] = $reason;

                continue;
            }
            $check = $this->policy->check($region, RegionPolicy::GLOBAL_REGION);
            if (! $check['allowed']) {
                $errors["region_ids.{$i}"] = (string) $check['reason'];
            }
        }

        $primary = (string) ($data['primary_region_id'] ?? $project->knative_region_id);
        if (! in_array($primary, $ids, true)) {
            $errors['primary_region_id'] = __('api.global.primary_not_listed');
        } elseif ($primary !== (string) $project->knative_region_id) {
            $row = $rows->get($primary);
            /** @var Deployment|null $active */
            $active = $project->activeDeployment()->first();
            if (! $row || $row->status !== ProjectRegion::STATUS_READY || ($active && $row->served_version_id !== $active->version_id)) {
                $errors['primary_region_id'] = __('api.global.primary_not_ready');
            } elseif (! in_array((string) $project->knative_region_id, $ids, true)) {
                // the switchover needs the region it leaves (its data is demoted there)
                $errors['region_ids'] = __('api.global.primary_leaves_after_move');
            }
        }
        if (GlobalFailover::moving($project) !== null) {
            $errors['primary_region_id'] = __('api.global.move_running');
        }

        // what the new regions must offer (runtime, Redis, GPUs, registry)
        $new = KnativeRegion::query()->whereIn('id', array_values(array_diff($ids, $rows->keys()->map(fn ($id) => (string) $id)->all())))->get()->all();
        if ($new !== [] && ! isset($errors['region_ids'])) {
            $errors += $this->projectRegionErrors($project, $new);
            // a dedicated Postgres binding gets a replica in every new region: its quota
            if (BindingEnv::tablesReady()) {
                foreach (Binding::query()->whereIn('id', BindingAttachment::query()->select('binding_id')->where('project_id', $project->getKey())->whereNull('environment_id'))->get() as $binding) {
                    if ($reason = RegionalData::quotaRefusal($binding, count($ids))) {
                        $errors['region_ids'] = $reason;
                    }
                }
            }
        }

        // a region that leaves: no gateway of it may still route to the project, no binding of
        // the project may live there
        foreach ($rows->filter(fn (ProjectRegion $r) => $r->status !== ProjectRegion::STATUS_REMOVING)->keys()->map(fn ($id) => (string) $id)->diff($ids) as $leaving) {
            if ($reason = $this->leaveRefusal($project, $leaving)) {
                $errors['region_ids'] = $reason;
            }
        }

        return $errors;
    }

    /**
     * Why a gateway route cannot lead to the global project (docs/global-projects.md, "Gateways"):
     * the gateway must be in one of the project's regions (its rule hands requests to that
     * region's copy), and "only through the gateway" would close the global host name.
     */
    public function gatewayRefusal(Project $project, Gateway $gateway, bool $gatewayOnly = false): ?string
    {
        if (! $project->isGlobal()) {
            return null;
        }
        if ($gatewayOnly) {
            return __('api.global.gateway_only_refused');
        }
        $regionIds = array_map(fn (KnativeRegion $r) => (string) $r->getKey(), GlobalProjectRegions::of($project, false));

        return in_array((string) $gateway->knative_region_id, $regionIds, true)
            ? null
            : __('api.global.gateway_outside_regions', ['region' => $gateway->knativeRegion->name ?? (string) $gateway->knative_region_id]);
    }

    /** Why the project cannot leave a region, null when it can. */
    public function leaveRefusal(Project $project, string $regionId): ?string
    {
        $region = KnativeRegion::query()->find($regionId);
        $name = $region->name ?? $regionId;

        if (GatewayRoute::query()->where('project_id', $project->getKey())
            ->whereIn('gateway_id', Gateway::query()->select('id')->where('knative_region_id', $regionId))->exists()) {
            return __('api.global.region_has_gateway_route', ['region' => $name]);
        }

        if (BindingEnv::tablesReady()) {
            $binding = Binding::query()
                ->whereIn('id', BindingAttachment::query()->select('binding_id')->where('project_id', $project->getKey())->whereNull('environment_id'))
                ->where('knative_region_id', $regionId)
                ->where('type', '!=', 'external')
                ->first();
            if ($binding) {
                return __('api.global.region_holds_binding', ['region' => $name, 'binding' => $binding->name]);
            }
        }

        return null;
    }

    // ── What the console shows ──────────────────────────────────────────────

    /** The global host name of the project (<slug>.<zone>), null without a zone. */
    public function hostname(Project $project): ?string
    {
        return $this->zone->hostFor((string) $project->slug);
    }

    /**
     * The `global` object of the API (docs/global-projects.md, "API").
     *
     * @return array<string, mixed>|null
     */
    public function summary(Project $project, bool $withHealth = false): ?array
    {
        if (! $project->isGlobal()) {
            return null;
        }

        $rows = ProjectRegion::query()
            ->where('project_id', $project->getKey())
            ->with(['knativeRegion', 'servedVersion:id,number', 'targetVersion:id,number'])
            ->orderBy('position')
            ->get();

        if ($withHealth) {
            $this->checkHealth($project, $rows->all());
        }

        $regions = [];
        foreach ($rows as $row) {
            $region = $row->knativeRegion;
            $primary = (string) $row->knative_region_id === (string) $project->knative_region_id;
            $address = trim((string) $region?->external_ip);
            $regions[] = [
                'region_id' => (string) $row->knative_region_id,
                'region_name' => $region?->name,
                'role' => $primary ? 'primary' : 'secondary',
                'position' => $primary ? 0 : (int) $row->position,
                'status' => $row->status,
                'status_message' => $row->status_message,
                'version' => $row->servedVersion?->number,
                'target_version' => $row->targetVersion?->number,
                'healthy' => $row->healthy,
                'health_checked_at' => $row->health_checked_at?->toIso8601String(),
                'address' => $address !== '' ? $address : null,
                'in_dns' => $row->isAnswerable() && ! $project->isStopped() && $address !== '',
                // the triggers run here (the primary; docs/global-projects.md, "Triggers")
                'triggers_active' => (bool) $row->getAttribute('triggers_active'),
                // the project's regional data reaches this copy (null: no regional data)
                'data_status' => $row->getAttribute('data_status'),
                'data_message' => $row->getAttribute('data_message'),
                'unhealthy_since' => $row->unhealthy_since?->toIso8601String(),
                'fenced_at' => $row->fenced_at?->toIso8601String(),
            ];
        }
        usort($regions, fn (array $a, array $b) => [$a['role'] === 'primary' ? 0 : 1, $a['position']] <=> [$b['role'] === 'primary' ? 0 : 1, $b['position']]);

        return [
            'mode' => in_array($project->global_mode, Project::GLOBAL_MODES, true) ? $project->global_mode : 'ha',
            'failover' => $project->globalFailover(),
            'failover_after_seconds' => max(30, (int) config('global_dns.failover.after_seconds', 180)),
            'moving_to' => GlobalFailover::moving($project),
            'hostname' => $this->hostname($project),
            'primary_region_id' => (string) $project->knative_region_id,
            'regions' => $regions,
            'bindings' => $this->dataSummary($project),
            'warnings' => $this->warnings($project),
        ];
    }

    /**
     * The project's bindings across its regions (docs/global-projects.md, "Data"): how each is
     * reached — as it is (external), through its data link, or a replica cluster per region —
     * and every region's state.
     *
     * @return list<array<string, mixed>>
     */
    public function dataSummary(Project $project): array
    {
        if (! BindingEnv::tablesReady()) {
            return [];
        }

        $bindings = Binding::query()
            ->whereIn('id', BindingAttachment::query()->select('binding_id')->where('project_id', $project->getKey())->whereNull('environment_id'))
            ->with('region:id,name')
            ->orderBy('name')
            ->get();
        $replicas = RegionalData::tableReady()
            ? BindingReplica::query()->whereIn('binding_id', $bindings->pluck('id'))->with('knativeRegion:id,name')->get()->groupBy('binding_id')
            : collect();

        $out = [];
        foreach ($bindings as $binding) {
            /** @var Binding $binding */
            $rows = $replicas->get($binding->id, collect());
            $out[] = [
                'binding_id' => (string) $binding->id,
                'name' => $binding->name,
                'type' => $binding->type,
                'plan' => $binding->plan,
                'home_region_id' => $binding->knative_region_id !== null ? (string) $binding->knative_region_id : null,
                'home_region_name' => $binding->region?->name,
                // external: as it is; link: through the data link; replicas: a replica per region
                'access' => $binding->type === 'external' ? 'direct' : ($binding->isDedicated() ? 'replicas' : ($rows->isEmpty() ? 'direct' : 'link')),
                'regions' => $rows->map(fn (BindingReplica $r) => [
                    'region_id' => (string) $r->knative_region_id,
                    'region_name' => $r->knativeRegion?->name,
                    'kind' => $r->kind,
                    'status' => $r->status,
                    'status_message' => $r->status_message,
                    'lag_seconds' => $r->lag_seconds,
                    'checked_at' => $r->checked_at?->toIso8601String(),
                ])->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * What the project's owners should know about running it in several regions.
     *
     * @return list<string>
     */
    public function warnings(Project $project): array
    {
        $warnings = [];

        if (BindingEnv::tablesReady()) {
            $types = BindingAttachment::query()
                ->where('project_id', $project->getKey())
                ->whereNull('environment_id')
                ->with('binding:id,type')
                ->get()
                ->map(fn (BindingAttachment $a) => $a->binding->type ?? null)
                ->filter()
                ->unique();
            if ($types->contains('external')) {
                $warnings[] = __('api.global.warning_external_bindings');
            }
            if ($types->contains(fn ($t) => in_array($t, ['kv', 'object'], true))) {
                $warnings[] = __('api.global.warning_linked_bindings');
            }
            if ($types->contains('postgres')) {
                $warnings[] = __('api.global.warning_postgres');
            }
        }

        if (app(ProjectTriggers::class)->plan($project)->triggers->contains(fn ($t) => ! in_array($t->type, ['http', 'workflow'], true))) {
            $warnings[] = __('api.global.warning_triggers_primary');
        }

        try {
            if (PrivateDatabaseBinding::query()->where('project_id', $project->getKey())->exists()) {
                $warnings[] = __('api.global.warning_private_connections');
            }
        } catch (Throwable) {
            // a schema without private connections
        }

        if (! $this->zone->configured()) {
            $warnings[] = __('api.global.no_zone');
        }

        return $warnings;
    }

    /**
     * Ask every region's health route for the project the way PowerDNS does (GET
     * http://<region address>/global-health/<slug>, Host runr-health.<zone>) and store the result
     * on its row. Reused for HEALTH_CACHE_SECONDS.
     *
     * @param  list<ProjectRegion>  $rows
     */
    public function checkHealth(Project $project, array $rows): void
    {
        $zone = $this->zone->name();
        if ($zone === null) {
            return;
        }

        $key = "global_health:{$project->getKey()}";
        if (Cache::has($key)) {
            return;
        }
        Cache::put($key, true, self::HEALTH_CACHE_SECONDS);

        $host = (string) $this->zone->healthHost($zone);
        $servers = app(GlobalDnsServerService::class);
        $timeout = max(1, (int) config('global_dns.health.timeout', 2));

        foreach ($rows as $row) {
            $address = $servers->addressesOf($row->knativeRegion?->external_ip)[0] ?? null;
            if ($address === null || $row->status === ProjectRegion::STATUS_REMOVING) {
                continue;
            }

            $url = 'http://'.(str_contains($address, ':') ? "[{$address}]" : $address).'/global-health/'.$project->slug;
            try {
                $response = Http::timeout($timeout)->connectTimeout($timeout)->withHeaders(['Host' => $host])->get($url);
                $healthy = $response->status() === 200 && trim($response->body()) === 'ok';
            } catch (Throwable) {
                $healthy = false;
            }

            $row->forceFill(['healthy' => $healthy, 'health_checked_at' => now()])->save();
        }
    }
}
