<?php

namespace App\Services\OrgTransfer\Sections;

use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\Deployment;
use App\Models\ExecutorImage;
use App\Models\Git\GitRepoLink;
use App\Models\Group;
use App\Models\KnativeRegion;
use App\Models\Observability\ProjectObservabilitySetting;
use App\Models\Observability\UptimeCheck;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Models\Version;
use App\Rules\WithinResourceLimit;
use App\Services\Bindings\BindingManager;
use App\Services\GitService;
use App\Services\OrgTransfer\Contracts\ImportAdmission;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\OrgTransfer\Package\PackageException;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Services\OrgTransfer\Package\TarReader;
use App\Services\OrgTransfer\Secrets\SecretValues;
use App\Services\ProjectDomainService;
use App\Services\ProjectService;
use App\Services\Residency\RegionPolicy;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\Auth\AccountLookup;
use App\Support\Rbac\AccessManager;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use App\Workflows\Engine\WorkflowRegistry;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowApp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * projects/<id>/project.json and projects/<id>/code/v<N>.tar per project (docs/org-export.md):
 * the project, its service config (every column, so config added later travels too), all
 * versions with their code (the project's directory of the organization's git repository at
 * each version's commit) and config snapshot, a summary of the deployment history, custom
 * domains, the names of its secret variables (values in secrets/secrets.enc when included),
 * workflow definitions, binding attachments, observability settings, uptime checks and project
 * access.
 *
 * Import, per project: created through ProjectService (region capacity admission) in the
 * mapped region under its name or the user's rename; versions committed again with their own
 * numbers and snapshots — a version built for the source's registry keeps that image reference,
 * so its next deploy builds it again for the region's registry (VersionRebuilds) unless the
 * images section brought the image along; domains wait for a new DNS verification; secrets are
 * written when the passphrase opens them, else listed as a manual step.
 */
class ProjectsSection implements TransferSection
{
    /** Columns of a service config that are the row's, not the project's settings. */
    private const CONFIG_INTERNAL = ['id', 'project_id', 'created_at', 'updated_at'];

    /** Largest code of one version read into memory on import. */
    private const CODE_MAX_BYTES = 128 * 1024 * 1024;

    public function __construct(
        private GitService $git,
        private ProjectService $projects,
        private SecretValues $secrets,
        private RuntimeRegistry $runtimes,
        private ProjectDomainService $domains,
    ) {}

    public function key(): string
    {
        return 'projects';
    }

    // ── Export ───────────────────────────────────────────────────────────

    public function export(ExportContext $context): void
    {
        $projects = $context->projects();
        $versions = 0;

        foreach ($projects->values() as $i => $project) {
            $context->progress('projects', 10, 45, $i, $projects->count(), __('org_transfer.progress.project', ['name' => $project->name, 'n' => $i + 1, 'total' => $projects->count()]));
            $data = $this->exportProject($context, $project);
            $context->putJson(PackageFormat::projectFile($project->id), $data);
            $versions += count($data['versions']);
        }

        $context->count('projects', $projects->count());
        $context->count('versions', $versions);
    }

    /** @return array<string, mixed> */
    private function exportProject(ExportContext $context, Project $project): array
    {
        // the whole region: the secret values (and the environments' own) are read from its
        // cluster through this relation, a region of only id and name has no cluster
        $project->loadMissing('knativeRegion');
        $config = match ($project->service_type) {
            'caas' => $project->containerConfig,
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            default => null,
        };

        $versions = [];
        foreach (Version::query()->where('project_id', $project->id)->orderBy('number')->get() as $version) {
            $code = null;
            if ($version->commit_id) {
                $entry = PackageFormat::codeFile($project->id, (int) $version->number);
                if ($this->git->archiveVersion($project, (string) $version->commit_id, $context->path($entry))) {
                    $code = $entry;
                } else {
                    @unlink($context->path($entry));
                    $context->warn(__('org_transfer.export.code_missing', ['project' => $project->name, 'version' => $version->number]));
                }
            }
            $versions[] = [
                'number' => (int) $version->number,
                'commit_message' => $version->commit_message,
                'changed_files' => $version->changed_files,
                'config_snapshot' => $version->config_snapshot,
                'build_status' => $version->build_status,
                'build_error' => $version->build_error,
                'build_image' => $version->build_image,
                'created_at' => $version->created_at?->toIso8601String(),
                'code' => $code,
            ];
        }

        $secrets = $this->secrets->read($project);
        foreach ($secrets['unreadable'] as $name) {
            $context->warn(__('org_transfer.export.secret_unreadable', ['project' => $project->name, 'name' => $name]));
        }
        $context->addSecret('projects', $project->id, $secrets['values']);

        $active = Deployment::query()->where('project_id', $project->id)->where('status', 'active')->with('version:id,number')->latest()->first();
        $history = Deployment::query()->where('project_id', $project->id)->with('version:id,number')
            ->orderByDesc('created_at')->limit((int) config('org_transfer.deployment_history', 50))->get()
            ->map(fn (Deployment $d) => [
                'version' => $d->version->number ?? null,
                'status' => $d->status,
                'deployed_by' => $d->deployed_by,
                'created_at' => $d->created_at?->toIso8601String(),
            ])->all();

        $uptime = [];
        foreach (UptimeCheck::query()->where('project_id', $project->id)->orderBy('name')->get() as $check) {
            $headers = (array) ($check->headers ?? []);
            $context->addSecret('uptime_headers', (string) $check->id, $headers);
            $uptime[] = [
                'id' => (string) $check->id,
                'name' => $check->name,
                'url' => $check->url,
                'path' => $check->path,
                'method' => $check->method,
                'header_names' => array_map('strval', array_keys($headers)),
                'interval_seconds' => $check->interval_seconds,
                'timeout_seconds' => $check->timeout_seconds,
                'expected_status' => $check->expected_status,
                'body_regex' => $check->body_regex,
                'locations' => $check->getAttribute('locations'),
                'region_ids' => $check->region_ids,
                'failure_threshold' => $check->failure_threshold,
                'recovery_threshold' => $check->recovery_threshold,
                'is_enabled' => (bool) $check->is_enabled,
                'show_on_status_page' => (bool) $check->show_on_status_page,
                'status_page_name' => $check->status_page_name,
                'status_page_order' => $check->status_page_order,
            ];
        }

        $observability = ProjectObservabilitySetting::query()->find($project->id);
        // Production's workflow app (an environment's syncs again with its deploy)
        $workflowApp = WorkflowApp::findFor((string) $project->id, null);

        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'description' => $project->description,
            'service_type' => $project->service_type,
            'runtime' => $project->runtime,
            'runtime_version' => $project->runtime_version,
            'endpoint_type' => $project->endpoint_type,
            'knative_region_id' => $project->knative_region_id,
            'region_name' => $project->knativeRegion?->name,
            'stopped' => $project->isStopped(),
            'config' => $config ? self::configAttributes($config) : null,
            'versions' => $versions,
            'deployments' => [
                'active_version' => $active?->version?->number,
                'history' => $history,
            ],
            'domains' => ProjectDomain::query()->where('project_id', $project->id)->orderBy('domain')->get()
                ->map(fn (ProjectDomain $d) => ['domain' => $d->domain, 'tls_mode' => $d->tls_mode, 'status' => $d->status])->all(),
            'secrets' => array_values(array_unique(array_merge(array_keys($secrets['values']), $secrets['unreadable']))),
            'secret_references' => $secrets['references'],
            'workflows' => Workflow::query()->where('project_id', $project->id)->whereNull('archived_at')->orderBy('name')->get()
                ->map(fn (Workflow $w) => [
                    'definition' => ['name' => $w->name, 'description' => $w->description, 'triggers' => $w->triggers ?? []] + (array) ($w->config ?? []),
                    'is_active' => (bool) $w->is_active,
                ])->all(),
            'workflow_endpoint' => $workflowApp?->endpoint_url,
            'bindings' => BindingAttachment::query()->where('project_id', $project->id)->get()
                ->map(fn (BindingAttachment $a) => ['binding_id' => (string) $a->binding_id, 'env_prefix' => (string) $a->env_prefix])->all(),
            'observability' => $observability ? [
                'tracing_enabled' => (bool) $observability->tracing_enabled,
                'tracing_sample_ratio' => $observability->tracing_sample_ratio,
                'replay_enabled' => (bool) $observability->replay_enabled,
                'replay_max_body_bytes' => $observability->replay_max_body_bytes,
                'replay_retention_days' => $observability->replay_retention_days,
                'replay_redact_headers' => $observability->replay_redact_headers,
                'replay_redact_query' => $observability->replay_redact_query,
            ] : null,
            'uptime_checks' => $uptime,
            'access' => $this->exportAccess($project),
            'git_linked' => class_exists(GitRepoLink::class) && Schema::hasTable('git_repo_links')
                && GitRepoLink::query()->where('project_id', $project->id)->exists(),
        ];
    }

    /** Project access of users (by e-mail) and groups (by name). */
    private function exportAccess(Project $project): array
    {
        $assignments = RoleAssignment::query()->where('project_id', $project->id)->get();
        $users = User::query()->whereIn('id', $assignments->where('subject_type', RoleAssignment::SUBJECT_USER)->pluck('subject_id')->all())->pluck('email', 'id');
        $groups = Group::query()->whereIn('id', $assignments->where('subject_type', RoleAssignment::SUBJECT_GROUP)->pluck('subject_id')->all())->pluck('name', 'id');

        return $assignments->map(fn (RoleAssignment $a) => $a->subject_type === RoleAssignment::SUBJECT_GROUP
            ? ['group' => $groups[$a->subject_id] ?? null, 'role' => $a->role]
            : ['email' => $users[$a->subject_id] ?? null, 'role' => $a->role])
            ->filter(fn ($a) => ($a['group'] ?? $a['email'] ?? null) !== null)->values()->all();
    }

    // ── Plan ─────────────────────────────────────────────────────────────

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        $demands = [];

        foreach ($context->projects() as $project) {
            $sourceId = (string) $project['id'];
            $name = $context->projectName($project);
            $region = $context->regionFor($project['knative_region_id'] ?? null);
            $row = [
                'source_id' => $sourceId,
                'name' => $project['name'],
                'target_name' => $name,
                'slug' => Project::slugFor($name),
                'service_type' => $project['service_type'],
                'runtime' => $project['runtime'] ?? null,
                'runtime_version' => $project['runtime_version'] ?? null,
                'source_region' => $project['region_name'] ?? null,
                'target_region_id' => $region?->id,
                'target_region' => $region?->name,
                'versions' => count((array) ($project['versions'] ?? [])),
                'domains' => array_column((array) ($project['domains'] ?? []), 'domain'),
                'bindings' => $this->bindingNames($context, (array) ($project['bindings'] ?? [])),
                'collision' => false,
                'suggested_name' => null,
                'status' => 'create',
                'issues' => [],
            ];

            if ($context->skipsProject($sourceId)) {
                $row['status'] = 'skip';
            } elseif ($context->mapped('project', $sourceId)) {
                $row['status'] = 'imported';
            } elseif (! in_array($project['service_type'], Project::SERVICE_TYPES, true)) {
                $row['status'] = 'skip';
                $plan->issue($row, ImportPlan::WARNING, 'unsupported_type', __('org_transfer.plan.unsupported_type', ['type' => $project['service_type']]));
            } else {
                $this->check($context, $plan, $row, $project, $name, $region, $demands);
            }

            $plan->projects[] = $row;
        }
    }

    /** @param array<string, list<array{service_type: string, config: array}>> $demands */
    private function check(ImportContext $context, ImportPlan $plan, array &$row, array $project, string $name, ?KnativeRegion $region, array &$demands): void
    {
        $slug = Project::slugFor($name);
        if ($slug === '' || strlen($slug) > Project::MAX_SLUG_LENGTH || ! preg_match(Project::SLUG_PATTERN, $slug)) {
            $plan->issue($row, ImportPlan::ERROR, 'name_invalid', __('org_transfer.plan.name_invalid', ['name' => $name]));
        } elseif ($context->slugTaken($name) || $this->plannedSlug($plan, $slug)) {
            $row['collision'] = true;
            $row['suggested_name'] = $context->freeName($name);
            $plan->issue($row, ImportPlan::ERROR, 'slug_taken', __('org_transfer.plan.slug_taken', ['slug' => $slug, 'name' => $row['suggested_name']]));
        }

        $config = (array) ($project['config'] ?? []);
        $trigger = (string) ($config['trigger_type'] ?? 'http');

        if (! empty($project['knative_region_id'])) {
            if ($region === null) {
                $plan->issue($row, ImportPlan::ERROR, 'region_unmapped', __('org_transfer.plan.region_unmapped', ['region' => $project['region_name'] ?? '?']));
            } else {
                if (! app(RegionPolicy::class)->allows($region, RegionPolicy::PROJECT_CREATE)) {
                    $plan->issue($row, ImportPlan::ERROR, 'residency', __('org_transfer.plan.residency', ['region' => $region->name]));
                }
                if (! $region->is_active) {
                    $plan->issue($row, ImportPlan::WARNING, 'region_unavailable', __('org_transfer.plan.region_inactive', ['region' => $region->name]));
                }
                if ($error = $this->capacityError($project['service_type'], $config, $region)) {
                    $plan->issue($row, ImportPlan::ERROR, 'capacity', $error);
                }
                $demands[$region->id][] = ['service_type' => (string) $project['service_type'], 'config' => $config];
                if ($error = app(ImportAdmission::class)->check($region, $demands[$region->id])) {
                    $plan->issue($row, ImportPlan::ERROR, 'quota', $error);
                }
            }
        }

        if (in_array($project['service_type'], ['xaas', 'faas'], true)) {
            $runtime = (string) ($project['runtime'] ?? '');
            if ($reason = $this->runtimes->unavailableReason($runtime, $region?->id, $trigger)) {
                $plan->issue($row, ImportPlan::ERROR, $this->runtimes->manifest($runtime) ? 'runtime_unavailable' : 'runtime_missing', $reason);
            } elseif (! empty($project['runtime_version']) && ! $this->runtimeVersionActive($runtime, (string) $project['runtime_version'])) {
                $plan->issue($row, ImportPlan::ERROR, 'runtime_version_missing', __('org_transfer.plan.runtime_version_missing', ['runtime' => $runtime, 'version' => $project['runtime_version']]));
            }
        }

        $domains = array_column((array) ($project['domains'] ?? []), 'domain');
        if ($domains) {
            $plan->issue($row, ImportPlan::MANUAL, 'dns', __('org_transfer.plan.dns', ['domains' => implode(', ', $domains)]));
            $plan->manual('dns', __('org_transfer.manual.dns'), $domains);
            $taken = ProjectDomain::query()->whereIn('domain', $domains)->pluck('domain')->all();
            if ($taken) {
                $plan->issue($row, ImportPlan::WARNING, 'domain_taken', __('org_transfer.plan.domain_taken', ['domains' => implode(', ', $taken)]));
            }
        }

        $secretNames = (array) ($project['secrets'] ?? []);
        if ($secretNames && $context->secretsState() !== 'unlocked') {
            $plan->issue($row, ImportPlan::MANUAL, 'secrets', __('org_transfer.plan.secrets', ['names' => implode(', ', $secretNames)]));
            $plan->manual('secrets', __('org_transfer.manual.secrets'), array_map(fn ($n) => "{$row['target_name']}: {$n}", $secretNames));
        }
        if (! empty($project['secret_references'])) {
            $plan->issue($row, ImportPlan::MANUAL, 'secrets', __('org_transfer.plan.secret_references', ['names' => implode(', ', array_keys((array) $project['secret_references']))]));
        }
        if (! empty($project['git_linked'])) {
            $plan->manual('git', __('org_transfer.manual.git'), [$row['target_name']]);
        }
        foreach ((array) ($project['uptime_checks'] ?? []) as $check) {
            if (! empty($check['header_names']) && $context->secretsState() !== 'unlocked') {
                $plan->manual('secrets', __('org_transfer.manual.secrets'), [__('org_transfer.manual.uptime_headers', ['check' => $check['name'] ?? '?', 'project' => $row['target_name']])]);
            }
        }
        if (! empty($project['stopped'])) {
            $plan->issue($row, ImportPlan::WARNING, 'stopped', __('org_transfer.plan.stopped'));
        }
    }

    private function plannedSlug(ImportPlan $plan, string $slug): bool
    {
        foreach ($plan->projects as $other) {
            if (($other['status'] ?? null) === 'create' && ($other['slug'] ?? null) === $slug) {
                return true;
            }
        }

        return false;
    }

    /** Region capacity of the project's size, as ProjectService::create() admits it. */
    private function capacityError(string $type, array $config, KnativeRegion $region): ?string
    {
        try {
            $trigger = is_string($t = $config['trigger_type'] ?? null) && $t !== '' ? $t : 'http';
            $minScale = is_numeric($m = $config['min_scale'] ?? null) ? (int) $m : 0;
            $scaleToZero = array_key_exists('scale_to_zero', $config) && $config['scale_to_zero'] !== null ? $config['scale_to_zero'] : true;
            Validator::make(['config' => $config], [
                'config.cpu' => [new WithinResourceLimit('cpu', null, $region->id, $trigger, $minScale, $type, $scaleToZero)],
                'config.memory_mb' => [new WithinResourceLimit('memory', null, $region->id, $trigger, $minScale, $type, $scaleToZero)],
            ])->validate();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        return null;
    }

    /**
     * The runtime version exists here and is active — as creating the project checks it
     * (StoreProjectRequest): versions live in executor_images, a built-in runtime's manifest lists
     * none (executor:sync discovers them).
     */
    private function runtimeVersionActive(string $runtime, string $version): bool
    {
        return ExecutorImage::query()->where('runtime', $runtime)->where('version', $version)->where('is_active', true)->exists();
    }

    /** @return list<string> */
    private function bindingNames(ImportContext $context, array $attachments): array
    {
        $names = collect((array) $context->json(PackageFormat::BINDINGS, []))->pluck('name', 'id');

        return array_values(array_filter(array_map(fn ($a) => $names[(string) ($a['binding_id'] ?? '')] ?? null, $attachments)));
    }

    // ── Import ───────────────────────────────────────────────────────────

    public function import(ImportContext $context): void
    {
        $projects = $context->projects();
        foreach ($projects as $i => $project) {
            $sourceId = (string) $project['id'];
            if ($context->skipsProject($sourceId) || ! in_array($project['service_type'], Project::SERVICE_TYPES, true)) {
                if (! $context->settled('project', $sourceId)) {
                    $context->record('project', $sourceId, $project['name'] ?? $sourceId, 'skipped');
                }

                continue;
            }

            $context->progress('projects', 20, 70, $i, count($projects), __('org_transfer.progress.project', ['name' => $context->projectName($project), 'n' => $i + 1, 'total' => count($projects)]));

            $target = $this->importProject($context, $project);
            if ($target === null) {
                continue;
            }

            $this->importVersions($context, $project, $target);
            $this->importSecrets($context, $project, $target);
            $this->importDomains($context, $project, $target);
            $this->importWorkflows($context, $project, $target);
            $this->importBindings($context, $project, $target);
            $this->importObservability($context, $project, $target);
            $this->importAccess($context, $project, $target);
        }
    }

    private function importProject(ImportContext $context, array $project): ?Project
    {
        $sourceId = (string) $project['id'];
        if ($id = $context->mapped('project', $sourceId)) {
            return Project::query()->find($id);
        }
        if ($context->settled('project', $sourceId)) {
            return null;
        }

        $name = $context->projectName($project);
        if ($context->slugTaken($name)) {
            $context->record('project', $sourceId, $name, 'failed', null, __('org_transfer.plan.slug_taken', ['slug' => Project::slugFor($name), 'name' => $context->freeName($name)]));

            return null;
        }

        $region = $context->regionFor($project['knative_region_id'] ?? null);
        if (! empty($project['knative_region_id']) && $region === null) {
            $context->record('project', $sourceId, $name, 'failed', null, __('org_transfer.plan.region_unmapped', ['region' => $project['region_name'] ?? '?']));

            return null;
        }

        $config = (array) ($project['config'] ?? []);
        try {
            app(RegionPolicy::class)->authorize($region, RegionPolicy::PROJECT_CREATE);

            $created = $this->projects->create([
                'name' => $name,
                'service_type' => $project['service_type'],
                'runtime' => $project['runtime'] ?? null,
                'runtime_version' => $project['runtime_version'] ?? null,
                'description' => $project['description'] ?? null,
                'knative_region_id' => $region?->id,
                'endpoint_type' => $project['endpoint_type'] ?? 'subdomain',
                'created_by' => $context->userId(),
                'config' => $config + ($project['service_type'] === 'caas' ? ['image' => $config['image'] ?? 'nginx'] : []),
            ]);
        } catch (\Throwable $e) {
            $context->record('project', $sourceId, $name, 'failed', null, $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage());

            return null;
        }

        // ProjectService::create() starts a CaaS project with a first version: the package's
        // versions take its place, numbered as in the source
        Version::query()->where('project_id', $created->id)->delete();

        // Every config column the package has and this installation knows (also those added by
        // newer features, e.g. a deploy strategy), the plain env vars included
        $model = match ($created->service_type) {
            'caas' => $created->containerConfig,
            'xaas' => $created->xaasConfig,
            'faas' => $created->faasConfig,
            default => null,
        };
        if ($model !== null && $config !== []) {
            $columns = Schema::connection($model->getConnectionName())->getColumnListing($model->getTable());
            $model->forceFill(array_intersect_key($config, array_flip(array_diff($columns, self::CONFIG_INTERNAL))))->save();
        }

        app(AccessManager::class)->grantCreatorAccess($created);
        $context->record('project', $sourceId, $name, 'created', $created->id);

        return $created->fresh();
    }

    private function importVersions(ImportContext $context, array $project, Project $target): void
    {
        $existing = Version::query()->where('project_id', $target->id)->pluck('number')->map(fn ($n) => (int) $n)->all();

        foreach ((array) ($project['versions'] ?? []) as $version) {
            $number = (int) ($version['number'] ?? 0);
            $key = $project['id'].':v'.$number;
            if ($number < 1 || $context->settled('version', $key)) {
                continue;
            }
            if (in_array($number, $existing, true)) {
                $context->record('version', $key, "{$target->name} v{$number}", 'exists', (string) Version::query()->where('project_id', $target->id)->where('number', $number)->value('id'));

                continue;
            }

            try {
                $files = ! empty($version['code']) ? $this->readCode($context, (string) $version['code']) : [];
                $message = (string) ($version['commit_message'] ?? '');
                $commit = $this->git->commitSnapshot($target, $files, "v{$number}: ".($message !== '' ? $message : __('org_transfer.import.version_message')));

                [$status, $error] = self::buildState($version);
                $created = Version::query()->create([
                    'project_id' => $target->id,
                    'number' => $number,
                    'commit_id' => $commit,
                    'commit_message' => $version['commit_message'] ?? null,
                    'created_by' => $context->userId() ?? 'system',
                    'changed_files' => $version['changed_files'] ?? null,
                    'config_snapshot' => self::snapshotFor($version['config_snapshot'] ?? null, $target),
                    'build_status' => $status,
                    'build_error' => $error,
                    'build_image' => $status === 'ready' ? ($version['build_image'] ?? null) : null,
                ]);
                $context->record('version', $key, "{$target->name} v{$number}", 'created', $created->id);
            } catch (\Throwable $e) {
                $context->record('version', $key, "{$target->name} v{$number}", 'failed', null, $e->getMessage());
            }
        }
    }

    /**
     * Build state of an imported version: a ready build keeps its image reference (another
     * registry's — the first deploy builds it again for this region's, VersionRebuilds); a
     * build that was still running in the source is cancelled (saving or pushing builds anew).
     *
     * @return array{0: string, 1: ?string}
     */
    private static function buildState(array $version): array
    {
        $status = (string) ($version['build_status'] ?? 'ready');

        return match ($status) {
            'ready', 'failed', Version::STATUS_PRUNED, Version::STATUS_CANCELLED => [$status, $version['build_error'] ?? null],
            default => [Version::STATUS_CANCELLED, __('org_transfer.import.build_cancelled')],
        };
    }

    /** The snapshot with the project's meta of this organization (name, region). */
    private static function snapshotFor(mixed $snapshot, Project $target): ?array
    {
        if (! is_array($snapshot)) {
            return null;
        }

        return array_merge($snapshot, ['name' => $target->name, 'knative_region_id' => $target->knative_region_id]);
    }

    /** @return array<string, string> */
    private function readCode(ImportContext $context, string $entry): array
    {
        $file = $context->file($entry);
        if ($file === null) {
            throw new PackageException(__('org_transfer.package.missing_entries', ['names' => $entry]), 'package_invalid');
        }

        $in = fopen($file, 'rb');
        if ($in === false) {
            throw new PackageException(__('org_transfer.package.extract_failed'), 'package_extract_failed');
        }
        try {
            // git archive: a directory entry before its files
            $tar = new TarReader($in, skipDirectories: true);
            $files = [];
            $total = 0;
            while (($item = $tar->next()) !== null) {
                $name = ltrim(preg_replace('#^\./#', '', $item['name']) ?? '', '/');
                if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/')) {
                    continue;
                }
                $total += $item['size'];
                if ($total > self::CODE_MAX_BYTES) {
                    throw new PackageException(__('org_transfer.package.entry_too_large'), 'package_invalid');
                }
                $files[$name] = $tar->contents(self::CODE_MAX_BYTES);
            }

            return $files;
        } finally {
            fclose($in);
        }
    }

    private function importSecrets(ImportContext $context, array $project, Project $target): void
    {
        $key = (string) $project['id'];
        if ($context->settled('secret', $key)) {
            return;
        }

        $names = (array) ($project['secrets'] ?? []);
        $values = $context->secret('projects', $key);
        $missing = $names;
        try {
            if (is_array($values) && $values !== []) {
                $set = $this->secrets->write($target, array_map('strval', $values), $context->userId());
                $missing = array_values(array_diff($names, $set));
            }
            $unwritten = ! empty($project['secret_references'])
                ? $this->secrets->writeReferences($target, (array) $project['secret_references'], $context->userId())
                : [];
            $missing = array_values(array_unique(array_merge($missing, $unwritten)));
        } catch (\Throwable $e) {
            $context->record('secret', $key, $target->name, 'failed', null, $e->getMessage());

            return;
        }

        if ($missing) {
            $context->manual('secrets', __('org_transfer.manual.secrets'), array_map(fn ($n) => "{$target->name}: {$n}", $missing));
        }
        if ($names || ! empty($project['secret_references'])) {
            $context->record('secret', $key, $target->name, $missing ? 'manual' : 'created', $target->id, $missing ? implode(', ', $missing) : null);
        }
    }

    private function importDomains(ImportContext $context, array $project, Project $target): void
    {
        foreach ((array) ($project['domains'] ?? []) as $domain) {
            $name = strtolower((string) ($domain['domain'] ?? ''));
            if ($name === '' || $context->settled('domain', $name)) {
                continue;
            }
            if (ProjectDomain::query()->where('domain', $name)->exists()) {
                // Another project of this installation has it (a copy into the same organization):
                // applying again would not change that — the user moves it by hand if wanted
                $context->record('domain', $name, $name, 'manual', null, __('org_transfer.plan.domain_taken', ['domains' => $name]));
                $context->manual('domain_taken', __('org_transfer.manual.domain_taken'), ["{$target->name}: {$name}"]);

                continue;
            }
            try {
                $created = $this->domains->add($target, $name);
                $context->record('domain', $name, $name, 'manual', $created->id, __('org_transfer.manual.dns'));
                $context->manual('dns', __('org_transfer.manual.dns'), [$name]);
                if (($domain['tls_mode'] ?? null) === 'custom') {
                    $context->manual('tls', __('org_transfer.manual.tls'), [$name]);
                }
            } catch (\Throwable $e) {
                $context->record('domain', $name, $name, 'failed', null, $e->getMessage());
            }
        }
    }

    private function importWorkflows(ImportContext $context, array $project, Project $target): void
    {
        $workflows = (array) ($project['workflows'] ?? []);
        $key = (string) $project['id'];
        if (($workflows === [] && empty($project['workflow_endpoint'])) || $context->settled('workflow', $key)) {
            return;
        }

        try {
            if ($workflows !== []) {
                $created = app(WorkflowRegistry::class)->declare($target, array_map(fn ($w) => (array) ($w['definition'] ?? []), $workflows), 'import');
                $paused = collect($workflows)->filter(fn ($w) => empty($w['is_active']))->map(fn ($w) => $w['definition']['name'] ?? null)->filter()->all();
                foreach ($created as $workflow) {
                    if (in_array($workflow->name, $paused, true)) {
                        $workflow->update(['is_active' => false]);
                    }
                }
            }
            if (! empty($project['workflow_endpoint'])) {
                WorkflowApp::forProject($target)->update(['endpoint_url' => (string) $project['workflow_endpoint']]);
            }
            $context->record('workflow', $key, $target->name, 'created', $target->id, count($workflows).' workflow(s)');
        } catch (\Throwable $e) {
            $context->record('workflow', $key, $target->name, 'failed', null, $e->getMessage());
        }
    }

    private function importBindings(ImportContext $context, array $project, Project $target): void
    {
        foreach ((array) ($project['bindings'] ?? []) as $attachment) {
            $sourceBinding = (string) ($attachment['binding_id'] ?? '');
            $key = $project['id'].':'.$sourceBinding;
            if ($sourceBinding === '' || $context->settled('attachment', $key)) {
                continue;
            }
            $bindingId = $context->mapped('binding', $sourceBinding);
            $binding = $bindingId ? Binding::query()->find($bindingId) : null;
            if (! $binding) {
                $context->record('attachment', $key, $target->name, 'failed', null, __('org_transfer.import.binding_missing'));

                continue;
            }

            try {
                if (BindingAttachment::query()->where('binding_id', $binding->id)->where('project_id', $target->id)->exists()) {
                    $context->record('attachment', $key, "{$target->name} → {$binding->name}", 'exists');

                    continue;
                }
                // A binding the import just created is still provisioning: attaching it is allowed
                // (BindingManager), its env reaches the project once it is ready
                $created = app(BindingManager::class)->attach($binding, $target, (string) ($attachment['env_prefix'] ?? ''));
                $context->record('attachment', $key, "{$target->name} → {$binding->name}", 'created', (string) $created->id);
            } catch (\Throwable $e) {
                $context->record('attachment', $key, "{$target->name} → {$binding->name}", 'failed', null, $e->getMessage());
            }
        }
    }

    private function importObservability(ImportContext $context, array $project, Project $target): void
    {
        $settings = $project['observability'] ?? null;
        if (is_array($settings) && ! $context->settled('observability', (string) $project['id'])) {
            try {
                $row = ProjectObservabilitySetting::forProject($target);
                $row->fill(array_intersect_key($settings, array_flip([
                    'tracing_enabled', 'tracing_sample_ratio', 'replay_enabled', 'replay_max_body_bytes',
                    'replay_retention_days', 'replay_redact_headers', 'replay_redact_query',
                ])));
                if ($row->replay_salt === null) {
                    $row->rotateReplaySalt();
                }
                $row->save();
                $context->record('observability', (string) $project['id'], $target->name, 'created', $target->id);
            } catch (\Throwable $e) {
                $context->record('observability', (string) $project['id'], $target->name, 'failed', null, $e->getMessage());
            }
        }

        foreach ((array) ($project['uptime_checks'] ?? []) as $check) {
            $key = (string) ($check['id'] ?? '');
            if ($key === '' || $context->settled('uptime_check', $key)) {
                continue;
            }
            $headers = $context->secret('uptime_headers', $key);
            $needsHeaders = ! empty($check['header_names']) && ! is_array($headers);
            try {
                $created = UptimeCheck::query()->create(array_merge(
                    array_intersect_key($check, array_flip([
                        'name', 'url', 'path', 'method', 'interval_seconds', 'timeout_seconds', 'expected_status', 'body_regex',
                        'locations', 'failure_threshold', 'recovery_threshold', 'show_on_status_page', 'status_page_name', 'status_page_order',
                    ])),
                    [
                        'project_id' => $target->id,
                        'headers' => is_array($headers) ? $headers : null,
                        'region_ids' => $target->knative_region_id ? [$target->knative_region_id] : null,
                        // Without its headers (credentials) a check would only fail: off until they are set
                        'is_enabled' => (bool) ($check['is_enabled'] ?? true) && ! $needsHeaders,
                        'status' => UptimeCheck::STATUS_UNKNOWN,
                    ],
                ));
                $context->record('uptime_check', $key, "{$target->name}: ".($check['name'] ?? $key), $needsHeaders ? 'manual' : 'created', (string) $created->id);
                if ($needsHeaders) {
                    $context->manual('secrets', __('org_transfer.manual.secrets'), [__('org_transfer.manual.uptime_headers', ['check' => $check['name'] ?? '?', 'project' => $target->name])]);
                }
            } catch (\Throwable $e) {
                $context->record('uptime_check', $key, "{$target->name}: ".($check['name'] ?? $key), 'failed', null, $e->getMessage());
            }
        }
    }

    private function importAccess(ImportContext $context, array $project, Project $target): void
    {
        $access = (array) ($project['access'] ?? []);
        $key = (string) $project['id'];
        if ($access === [] || $context->settled('access', $key) || $context->user === null) {
            return;
        }

        $manager = app(AccessManager::class);
        $missing = [];
        foreach ($access as $grant) {
            $role = (string) ($grant['role'] ?? '');
            if ($role === '' || $role === PermissionCatalog::OWNER) {
                continue;
            }
            try {
                if (isset($grant['group'])) {
                    $group = Group::query()->where('name', (string) $grant['group'])->first();
                    if ($group) {
                        $manager->grantProjectAccess($context->user, $target, RoleAssignment::SUBJECT_GROUP, (string) $group->id, $role);
                    } else {
                        $missing[] = "group {$grant['group']}";
                    }
                } elseif (isset($grant['email'])) {
                    // the member in any letter case, as the sign-in names an account
                    $user = AccountLookup::byEmail((string) $grant['email'], (new User)->getConnection()->getName());
                    if ($user && app(Rbac::class)->organization()->isMember($user->id)) {
                        $manager->grantProjectAccess($context->user, $target, RoleAssignment::SUBJECT_USER, (string) $user->id, $role);
                    } else {
                        $missing[] = (string) $grant['email'];
                    }
                }
            } catch (\Throwable $e) {
                $missing[] = ($grant['group'] ?? $grant['email'] ?? '?').': '.$e->getMessage();
            }
        }

        if ($missing) {
            $context->manual('access', __('org_transfer.manual.access'), array_map(fn ($m) => "{$target->name}: {$m}", $missing));
        }
        $context->record('access', $key, $target->name, $missing ? 'manual' : 'created', $target->id);
    }

    /** Every column of a service config with its (cast) value — encrypted env vars decrypted. */
    private static function configAttributes(Model $config): array
    {
        $out = [];
        foreach (array_keys($config->getAttributes()) as $column) {
            if (in_array($column, self::CONFIG_INTERNAL, true)) {
                continue;
            }
            $value = $config->getAttribute($column);
            $out[$column] = $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
        }

        return $out;
    }
}
