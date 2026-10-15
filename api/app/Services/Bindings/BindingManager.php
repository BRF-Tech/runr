<?php

namespace App\Services\Bindings;

use App\Jobs\Bindings\BindingOperationJob;
use App\Jobs\Bindings\SyncProjectBindingsJob;
use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Residency\RegionPolicy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lifecycle of bindings (docs/bindings.md "Lifecycle"):
 *
 *   create ─▶ provisioning ─▶ ready ⇄ rotating
 *                                │ delete (confirm)
 *                                ▼
 *                            deleting ──restore──▶ ready
 *                                │ purge_at reached
 *                                ▼
 *                             purging ─▶ (row gone; usage records stay)
 *
 * Request-side methods validate and queue; the BindingOperationJob bodies (provision, rotate,
 * suspend, resume, applySettings, purge) talk to the region. Every change of what a project sees
 * re-syncs its Secret / workloads and the bindings' NetworkPolicies (SyncProjectBindingsJob).
 */
class BindingManager
{
    public function __construct(
        private BindingDrivers $drivers,
        private BindingQuotas $quotas,
        private ProjectBindingSync $sync,
        private BindingNetworkPolicies $policies,
        private RegionPolicy $residency,
    ) {}

    // ── Requests ────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{name: string, type: string, plan?: string, knative_region_id?: ?string, settings?: array, values?: array}  $data
     */
    public function create(array $data, ?string $userId): Binding
    {
        $type = $data['type'];
        $plan = $type === 'postgres' ? ($data['plan'] ?? Binding::PLAN_SHARED) : ($type === 'external' ? 'external' : Binding::PLAN_SHARED);
        $settings = self::normalizeSettings($type, $plan, (array) ($data['settings'] ?? []));
        $region = null;

        if ($type !== 'external') {
            $region = app(KnativeRegion::class)->newQuery()->find($data['knative_region_id'] ?? null);
            if (! $region || ! $region->kubeconfig || $region->is_demo) {
                throw new BindingException(__('api.bindings.region_unavailable', ['region' => $data['knative_region_id'] ?? '?']));
            }
            // Data residency (docs/supply-chain.md): 422 + residency.denied audit outside the allowed regions
            $this->residency->authorize($region, RegionPolicy::BINDING_CREATE);
            if (! BindingRegionService::forRegion($region, $type)?->isReady()) {
                throw new BindingException(__('api.bindings.service_not_ready', ['service' => $type, 'region' => $region->name]));
            }
            if ($type === 'postgres' && ! empty($settings['backups']) && ! BindingRegionService::forRegion($region, 'object')?->isReady()) {
                throw new BindingException(__('api.bindings.backups_need_object_store'));
            }
        }

        if ($exceeded = $this->quotas->exceeded($type, $plan, $settings)) {
            throw new BindingException(__('api.bindings.quota_exceeded', $exceeded));
        }

        $id = strtolower((string) Str::ulid());
        $binding = new Binding([
            'name' => $data['name'],
            'resource_name' => Binding::resourceNameFor($data['name'], $id),
            'type' => $type,
            'plan' => $plan,
            'knative_region_id' => $region?->getKey(),
            'status' => Binding::STATUS_PROVISIONING,
            'settings' => $settings,
            'credentials' => $type === 'external' ? ['values' => array_map('strval', (array) ($data['values'] ?? []))] : null,
            'created_by' => $userId,
        ]);
        $binding->id = $id;
        $binding->save();

        BindingAudit::binding('binding.created', $binding, ['settings' => $settings]);
        BindingOperationJob::dispatch('provision', $binding->id);

        return $binding;
    }

    public function attach(Binding $binding, Project $project, string $envPrefix = ''): BindingAttachment
    {
        if (! $binding->isActive()) {
            throw new BindingException(__('api.bindings.not_active', ['name' => $binding->name]));
        }
        if ($binding->type !== 'external' && (string) $binding->knative_region_id !== (string) $project->knative_region_id) {
            throw new BindingException(__('api.bindings.region_mismatch', ['binding' => $binding->name, 'project' => $project->name]));
        }
        if (BindingAttachment::query()->where('binding_id', $binding->id)->where('project_id', $project->id)->exists()) {
            throw new BindingException(__('api.bindings.already_attached', ['name' => $binding->name]));
        }
        // The binding's data stays in its region: the tenant must (still) be allowed there
        if ($binding->knative_region_id) {
            $this->residency->authorize((string) $binding->knative_region_id, RegionPolicy::BINDING_CREATE, field: 'binding_id');
        }

        $this->assertNoEnvConflict($binding, $project, $envPrefix);

        $attachment = BindingAttachment::create([
            'binding_id' => $binding->id,
            'project_id' => $project->id,
            'env_prefix' => $envPrefix,
        ]);

        BindingAudit::binding('binding.attached', $binding, ['project_id' => $project->id, 'env_prefix' => $envPrefix]);
        SyncProjectBindingsJob::dispatch($project->id, [$binding->id]);

        return $attachment->setRelation('binding', $binding);
    }

    public function updateAttachment(BindingAttachment $attachment, string $envPrefix): BindingAttachment
    {
        $binding = $attachment->binding;
        $project = $attachment->project;

        $this->assertNoEnvConflict($binding, $project, $envPrefix, $attachment->id);

        $attachment->env_prefix = $envPrefix;
        $attachment->save();

        BindingAudit::binding('binding.attachment_updated', $binding, ['project_id' => $project->id, 'env_prefix' => $envPrefix]);
        SyncProjectBindingsJob::dispatch($project->id, [$binding->id]);

        return $attachment;
    }

    public function detach(BindingAttachment $attachment): void
    {
        $binding = $attachment->binding;
        $projectId = $attachment->project_id;
        $attachment->delete();

        if ($binding) {
            BindingAudit::binding('binding.detached', $binding, ['project_id' => $projectId]);
        }
        SyncProjectBindingsJob::dispatch($projectId, $binding ? [$binding->id] : []);
    }

    public function requestRotation(Binding $binding): void
    {
        if ($binding->status !== Binding::STATUS_READY) {
            throw new BindingException(__('api.bindings.not_ready', ['name' => $binding->name, 'status' => $binding->status]));
        }

        $binding->status = Binding::STATUS_ROTATING;
        $binding->save();

        BindingOperationJob::dispatch('rotate', $binding->id);
    }

    /**
     * New settings (limits) or — external — new values. Quota checked against the new size.
     */
    public function update(Binding $binding, array $settings, ?array $values = null): Binding
    {
        if (! $binding->isActive()) {
            throw new BindingException(__('api.bindings.not_active', ['name' => $binding->name]));
        }

        $old = (array) $binding->settings;
        $merged = self::normalizeSettings($binding->type, $binding->plan, array_merge($old, $settings));

        if ($binding->isDedicated() && (int) ($merged['storage_gb'] ?? 0) < (int) ($old['storage_gb'] ?? 0)) {
            throw new BindingException(__('api.bindings.storage_shrink'));
        }
        if ($exceeded = $this->quotas->exceeded($binding->type, $binding->plan, $merged, $binding->id)) {
            throw new BindingException(__('api.bindings.quota_exceeded', $exceeded));
        }

        $binding->settings = $merged;
        if ($binding->type === 'external' && $values !== null) {
            $binding->credentials = ['values' => array_map('strval', $values)];
        }
        $binding->save();

        BindingAudit::binding('binding.updated', $binding, ['settings' => $merged, 'values_changed' => $values !== null]);

        if ($binding->type === 'external') {
            $this->syncAttachedProjects($binding);
        } elseif ($merged != $old) {
            BindingOperationJob::dispatch('apply_settings', $binding->id, ['old' => $old]);
        }

        return $binding;
    }

    /**
     * Delete with retention: detached from every project at once, access revoked by the job,
     * data purged at purge_at unless restored.
     */
    public function scheduleDeletion(Binding $binding): Binding
    {
        if (! $binding->isActive()) {
            throw new BindingException(__('api.bindings.not_active', ['name' => $binding->name]));
        }

        $projectIds = $binding->attachments()->pluck('project_id')->all();
        $binding->attachments()->delete();

        $binding->status = Binding::STATUS_DELETING;
        $binding->delete_requested_at = now();
        $binding->purge_at = now()->addDays(max(0, (int) config('bindings.retention_days', 7)));
        $binding->save();

        BindingAudit::binding('binding.deletion_scheduled', $binding, ['purge_at' => $binding->purge_at->toIso8601String(), 'detached_projects' => $projectIds]);

        foreach ($projectIds as $projectId) {
            SyncProjectBindingsJob::dispatch($projectId, [$binding->id]);
        }
        BindingOperationJob::dispatch('suspend', $binding->id);

        return $binding;
    }

    public function restore(Binding $binding): Binding
    {
        if ($binding->status !== Binding::STATUS_DELETING) {
            throw new BindingException(__('api.bindings.not_deleting', ['name' => $binding->name]));
        }
        // Restoring re-creates access to the data in its region (data residency)
        if ($binding->knative_region_id) {
            $this->residency->authorize((string) $binding->knative_region_id, RegionPolicy::BINDING_CREATE);
        }

        $binding->status = Binding::STATUS_READY;
        $binding->delete_requested_at = null;
        $binding->purge_at = null;
        $binding->save();

        BindingAudit::binding('binding.restored', $binding);
        BindingOperationJob::dispatch('resume', $binding->id);

        return $binding;
    }

    /**
     * Credentials of a binding (GET /bindings/{id}/credentials, bindings.view_credentials).
     *
     * @return array{env: array<string, string>, credentials: array}
     */
    public function reveal(Binding $binding): array
    {
        $env = empty($binding->credentials) ? [] : $this->drivers->for($binding)->env($binding);

        BindingAudit::binding('binding.credentials_revealed', $binding);

        return ['env' => $env];
    }

    /** @return array{state: string, message: ?string, details: array} */
    public function health(Binding $binding): array
    {
        if (! in_array($binding->status, [Binding::STATUS_READY, Binding::STATUS_ROTATING], true)) {
            return ['state' => $binding->status, 'message' => $binding->status_message, 'details' => []];
        }

        try {
            return $this->drivers->for($binding)->health($binding);
        } catch (\Throwable $e) {
            return ['state' => 'error', 'message' => $e->getMessage(), 'details' => []];
        }
    }

    // ── Job bodies ──────────────────────────────────────────────────────────────────────

    public function provision(Binding $binding): void
    {
        try {
            $this->drivers->for($binding)->provision($binding);
            $binding->status = Binding::STATUS_READY;
            $binding->status_message = null;
            $binding->save();
        } catch (\Throwable $e) {
            $binding->status = Binding::STATUS_ERROR;
            $binding->status_message = mb_substr($e->getMessage(), 0, 2000);
            $binding->save();
            Log::warning('Bindings: provisioning failed', ['binding' => $binding->resource_name, 'error' => $e->getMessage()]);

            return;
        }

        $this->syncAttachedProjects($binding);
    }

    public function rotate(Binding $binding): void
    {
        try {
            $this->drivers->for($binding)->rotate($binding);
            $binding->rotated_at = now();
            $binding->status = Binding::STATUS_READY;
            $binding->status_message = null;
            $binding->save();
        } catch (\Throwable $e) {
            $binding->status = Binding::STATUS_READY;
            $binding->status_message = __('api.bindings.rotation_failed', ['error' => mb_substr($e->getMessage(), 0, 1000)]);
            $binding->save();
            Log::warning('Bindings: rotation failed', ['binding' => $binding->resource_name, 'error' => $e->getMessage()]);

            return;
        }

        BindingAudit::binding('binding.credentials_rotated', $binding);

        // Rolling restart of every attached workload onto the new credentials
        $this->syncAttachedProjects($binding);

        if (! empty($binding->previous_credentials)) {
            BindingOperationJob::dispatch('finish_rotation', $binding->id)
                ->delay(now()->addSeconds(max(0, (int) config('bindings.rotation_grace_seconds', 600))));
        }
    }

    public function finishRotation(Binding $binding): void
    {
        $this->drivers->for($binding)->finishRotation($binding);
        $binding->save();
    }

    public function suspend(Binding $binding): void
    {
        try {
            $this->drivers->for($binding)->suspend($binding);
        } catch (\Throwable $e) {
            $binding->status_message = mb_substr($e->getMessage(), 0, 2000);
            $binding->save();
            Log::warning('Bindings: suspend failed', ['binding' => $binding->resource_name, 'error' => $e->getMessage()]);
        }

        $this->policies->sync($binding);
    }

    public function resume(Binding $binding): void
    {
        try {
            $this->drivers->for($binding)->resume($binding);
        } catch (\Throwable $e) {
            $binding->status_message = mb_substr($e->getMessage(), 0, 2000);
            $binding->save();
            Log::warning('Bindings: resume failed', ['binding' => $binding->resource_name, 'error' => $e->getMessage()]);
        }
    }

    public function applySettings(Binding $binding, array $old): void
    {
        try {
            $this->drivers->for($binding)->applySettings($binding, $old);
            $binding->save();
        } catch (\Throwable $e) {
            $binding->status_message = mb_substr($e->getMessage(), 0, 2000);
            $binding->save();
            Log::warning('Bindings: settings update failed', ['binding' => $binding->resource_name, 'error' => $e->getMessage()]);
        }
    }

    /** Delete the data, the backend resources and the row (usage records stay). */
    public function purge(Binding $binding): bool
    {
        $binding->status = Binding::STATUS_PURGING;
        $binding->save();

        try {
            $this->drivers->for($binding)->purge($binding);
            $this->policies->remove($binding);
        } catch (\Throwable $e) {
            // Retried by the next purge run
            $binding->status = Binding::STATUS_DELETING;
            $binding->status_message = __('api.bindings.purge_failed', ['error' => mb_substr($e->getMessage(), 0, 1000)]);
            $binding->save();
            Log::warning('Bindings: purge failed', ['binding' => $binding->resource_name, 'error' => $e->getMessage()]);

            return false;
        }

        BindingAudit::system('binding.purged', $binding);
        $binding->attachments()->delete();
        $binding->delete();

        return true;
    }

    /** Bindings whose retention ended (current organization context). */
    public function purgeDue(): int
    {
        $purged = 0;
        $due = Binding::query()
            ->where('status', Binding::STATUS_DELETING)
            ->where('purge_at', '<=', now())
            ->with('region')
            ->get();

        foreach ($due as $binding) {
            $purged += $this->purge($binding) ? 1 : 0;
        }

        return $purged;
    }

    /**
     * The organization is being deleted: every binding goes now (no retention).
     *
     * @return list<string> failures
     */
    public function purgeAllNow(): array
    {
        if (! BindingEnv::tablesReady()) {
            return [];
        }

        $failures = [];
        foreach (Binding::query()->with('region')->get() as $binding) {
            $binding->attachments()->delete();
            if (! $this->purge($binding)) {
                $failures[] = "binding {$binding->name}: ".$binding->status_message;
            }
        }

        return $failures;
    }

    /**
     * A project is being deleted (ProjectCleanupService): its Secret goes, its bindings stay and
     * their NetworkPolicies lose the project.
     *
     * @return list<string> failures
     */
    public function cleanupProject(Project $project): array
    {
        if (! BindingEnv::tablesReady()) {
            return [];
        }

        $bindingIds = BindingAttachment::query()->where('project_id', $project->id)->pluck('binding_id')->all();
        if (! $bindingIds) {
            return [];
        }

        $failures = [];
        BindingAttachment::query()->where('project_id', $project->id)->delete();

        try {
            $this->sync->forget($project);
        } catch (\Throwable $e) {
            $failures[] = "{$project->slug}: bindings Secret: {$e->getMessage()}";
        }

        foreach (Binding::query()->whereIn('id', $bindingIds)->with('region')->get() as $binding) {
            try {
                $this->policies->sync($binding);
            } catch (\Throwable $e) {
                $failures[] = "{$project->slug}: binding {$binding->name} NetworkPolicy: {$e->getMessage()}";
            }
        }

        return $failures;
    }

    /** Job body: a project's Secret + workloads, and the NetworkPolicies of the given / attached bindings. */
    public function syncProject(Project $project, array $bindingIds = []): void
    {
        $this->sync->sync($project);

        $ids = array_unique(array_merge(
            $bindingIds,
            BindingAttachment::query()->where('project_id', $project->id)->pluck('binding_id')->all(),
        ));

        foreach (Binding::query()->whereIn('id', $ids)->with('region')->get() as $binding) {
            $this->policies->sync($binding);
        }
    }

    public function syncAttachedProjects(Binding $binding): void
    {
        foreach ($binding->attachments()->pluck('project_id') as $projectId) {
            SyncProjectBindingsJob::dispatch($projectId, [$binding->id]);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────

    /** Env names of $binding with $prefix must not collide with the project's other attachments. */
    private function assertNoEnvConflict(Binding $binding, Project $project, string $prefix, ?string $exceptAttachment = null): void
    {
        $names = array_map(fn ($k) => $prefix.$k, BindingEnv::keysFor($binding));

        $taken = [];
        $others = BindingAttachment::query()
            ->where('project_id', $project->id)
            ->when($exceptAttachment, fn ($q) => $q->whereKeyNot($exceptAttachment))
            ->with('binding')
            ->get();
        foreach ($others as $other) {
            if ($other->binding) {
                array_push($taken, ...$other->envNames());
            }
        }

        $conflict = array_values(array_intersect($names, $taken));
        if ($conflict) {
            throw new BindingException(__('api.bindings.env_conflict', ['names' => implode(', ', $conflict)]));
        }
    }

    /** Settings with defaults; only the keys of the type / plan. */
    public static function normalizeSettings(string $type, string $plan, array $settings): array
    {
        return match ($type) {
            'kv' => [
                'memory_mb' => (int) ($settings['memory_mb'] ?? config('bindings.kv.default_memory_mb')),
                'over_quota' => (bool) ($settings['over_quota'] ?? false),
            ],
            'object' => [
                'quota_gb' => (int) ($settings['quota_gb'] ?? config('bindings.object.default_quota_gb')),
            ],
            'postgres' => array_merge([
                'storage_gb' => (int) ($settings['storage_gb'] ?? ($plan === Binding::PLAN_DEDICATED
                    ? config('bindings.postgres.dedicated.default_storage_gb')
                    : config('bindings.postgres.default_storage_gb'))),
                'connection_limit' => (int) ($settings['connection_limit'] ?? config('bindings.postgres.default_connection_limit')),
                'statement_timeout' => (string) ($settings['statement_timeout'] ?? config('bindings.postgres.default_statement_timeout')),
                'over_quota' => (bool) ($settings['over_quota'] ?? false),
            ], $plan === Binding::PLAN_DEDICATED ? [
                'instances' => (int) ($settings['instances'] ?? config('bindings.postgres.dedicated.default_instances')),
                'backups' => (bool) ($settings['backups'] ?? false),
            ] : []),
            default => [],
        };
    }
}
