<?php

namespace App\Services;

use App\Jobs\BuildVersionJob;
use App\Models\Project;
use App\Models\Version;
use App\Rules\WithinResourceLimit;
use App\Services\Concerns\ProjectConfigTrait;
use App\Services\Concerns\RuntimeConfigTrait;
use App\Services\GlobalProjects\GlobalProjectService;
use App\Services\Runtimes\ProjectEntrypoint;
use App\Support\Secrets\SecretRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Version snapshots: the config_snapshot column (encrypted) keeps env var values; the copy committed
 * to the project's git repository — also the code ConfigMap mounted into the workload — keeps
 * their names only (SecretRedactor::envNamesOnly, docs/security.md).
 */
class ProjectService
{
    use ProjectConfigTrait, RuntimeConfigTrait;

    public function __construct(private GitService $git) {}

    /**
     * @throws ValidationException the project does not fit the region capacity (admitRegionCapacity())
     */
    public function create(array $data): Project
    {
        $data = $this->withRuntimeResourceDefaults($data);
        $this->admitRegionCapacity($data);

        $attributes = [
            'name' => $data['name'],
            'service_type' => $data['service_type'],
            'runtime' => $data['runtime'] ?? null,
            'runtime_version' => $data['runtime_version'] ?? null,
            'description' => $data['description'] ?? null,
            'knative_region_id' => $data['knative_region_id'] ?? null,
            'endpoint_type' => $data['endpoint_type'] ?? 'subdomain',
            'is_region_demo' => $data['is_region_demo'] ?? false,
            'created_by' => $data['created_by'] ?? null,
        ];
        // The creator owns (pays for) the project until the ownership is transferred (docs/billing.md)
        $owner = $data['owner_id'] ?? $data['created_by'] ?? null;
        if ($owner !== null && $owner !== '' && $owner !== 'system') {
            $attributes['owner_id'] = (string) $owner;
        }

        $project = Project::create($attributes);

        // Global project (docs/global-projects.md): its regions, the primary first
        if (($data['placement'] ?? Project::PLACEMENT_REGIONAL) === Project::PLACEMENT_GLOBAL) {
            app(GlobalProjectService::class)->attach($project, array_map('strval', (array) ($data['region_ids'] ?? [])), (string) ($data['global_mode'] ?? 'ha'));
        }

        $this->git->initRepo();
        $project->update(['git_path' => $this->git->projectDir($project)]);

        match ($project->service_type) {
            'caas' => $this->createCaaSConfig($project, $data['config']),
            'xaas' => $this->createXaaSConfig($project, $data['config']),
            'faas' => $this->createFaaSConfig($project, $data['config']),
        };

        // First version: create a config snapshot
        if ($project->service_type === 'caas') {
            $this->createCaaSVersion($project, 'Initial config', null);
        }

        return $project->load(['containerConfig', 'xaasConfig', 'faasConfig', 'latestVersion']);
    }

    /**
     * A code project that does not ask for a size gets its runtime's resource defaults
     * (manifest resources), else the service type's preset (ProjectConfigTrait).
     */
    private function withRuntimeResourceDefaults(array $data): array
    {
        if (! in_array($data['service_type'] ?? null, ['xaas', 'faas'], true)) {
            return $data;
        }

        $resources = $this->runtimeManifest($data['runtime'] ?? 'python')?->resources() ?? [];
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];

        if (isset($resources['cpu']) && empty($config['cpu'])) {
            $config['cpu'] = $resources['cpu'];
        }
        if (isset($resources['memory_mb']) && empty($config['memory_mb'])) {
            $config['memory_mb'] = $resources['memory_mb'];
        }
        $data['config'] = $config;

        return $data;
    }

    /**
     * Region capacity admission of a new project, whoever creates it: the API (StoreProjectRequest
     * checks the same before), the region demo (RegionDemoService checks all four up front), the
     * tenant / install demo seeding. WithinResourceLimit with the effective size — the requested
     * one, else the service type's default.
     *
     * @throws ValidationException
     */
    private function admitRegionCapacity(array $data): void
    {
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $trigger = is_string($t = $config['trigger_type'] ?? null) && $t !== '' ? $t : 'http';
        $minScale = is_numeric($m = $config['min_scale'] ?? null) ? (int) $m : 0;
        $type = $data['service_type'] ?? null;
        $scaleToZero = array_key_exists('scale_to_zero', $config) && $config['scale_to_zero'] !== null ? $config['scale_to_zero'] : true;
        $schedule = $config['scale_schedule'] ?? null;

        // a global project runs a copy in each of its regions: each has to fit
        $regionIds = [$data['knative_region_id'] ?? null];
        if (($data['placement'] ?? Project::PLACEMENT_REGIONAL) === Project::PLACEMENT_GLOBAL) {
            $regionIds = array_values(array_unique(array_merge($regionIds, array_map('strval', (array) ($data['region_ids'] ?? [])))));
        }

        foreach ($regionIds as $regionId) {
            Validator::make(['config' => $config], [
                'config.cpu' => [new WithinResourceLimit('cpu', null, $regionId, $trigger, $minScale, $type, $scaleToZero, $schedule)],
                'config.memory_mb' => [new WithinResourceLimit('memory', null, $regionId, $trigger, $minScale, $type, $scaleToZero, $schedule)],
            ])->validate();
        }
    }

    /** XaaS: commit the code to git and create a version record */
    public function saveXaaSCode(Project $project, string $code, string $userId, ?string $message = null): Version
    {
        $config = $project->xaasConfig;
        $filename = $config->filename ?? $this->entrypoint($project)->filename;
        $buildStatus = $this->isCompiledRuntime($project->runtime) ? 'pending' : 'ready';

        $version = $this->git->commitFiles(
            $project,
            [$filename => $code],
            $message ?? "update: {$filename}",
            $userId,
            $buildStatus
        );

        if ($buildStatus === 'pending' && $version->build_status === 'pending') {
            BuildVersionJob::dispatch($version, auth()->id());
        }

        return $version;
    }

    /** FaaS: commit the files to git and create a version record */
    public function saveFaaSFiles(Project $project, array $files, string $userId, ?string $message = null): Version
    {
        $buildStatus = $this->needsBuild($project->runtime, $files) ? 'pending' : 'ready';

        $version = $this->git->commitFiles(
            $project,
            $files,
            $message ?? 'update: '.implode(', ', array_keys($files)),
            $userId,
            $buildStatus
        );

        if ($buildStatus === 'pending' && $version->build_status === 'pending') {
            BuildVersionJob::dispatch($version, auth()->id());
        }

        return $version;
    }

    /**
     * CaaS: a version that runs $image with the project's config otherwise as it is — the project's
     * own config (and so its workload) keeps its image. An environment branch's push
     * (docs/environments.md#git-branches): the image is the version's code.
     */
    public function createCaaSImageVersion(Project $project, string $image, string $userId, ?string $message = null): Version
    {
        $config = $project->containerConfig ?? throw $project->notFound();
        $copy = (clone $config)->forceFill(['image' => $image]);
        $project->setRelation('containerConfig', $copy);

        try {
            return $this->createCaaSVersion($project, $message ?? 'Image '.$image, $userId);
        } finally {
            $project->setRelation('containerConfig', $config);
        }
    }

    /** CaaS: update the config and create a new version snapshot */
    public function updateCaaSConfig(Project $project, array $config, string $userId, ?string $message = null): Version
    {
        $this->updateConfigRow($project, 'containerConfig', $config);

        return $this->createCaaSVersion($project, $message ?? 'Config updated', $userId);
    }

    /** XaaS: update the config and create a new version snapshot */
    public function updateXaaSConfig(Project $project, array $config, string $userId, ?string $message = null): Version
    {
        $stored = $this->updateConfigRow($project, 'xaasConfig', $config);

        return $this->createCodeConfigVersion($project, $stored->toArray(), $message ?? 'Config updated', $userId);
    }

    /** FaaS: update the config and create a new version snapshot */
    public function updateFaaSConfig(Project $project, array $config, string $userId, ?string $message = null): Version
    {
        $stored = $this->updateConfigRow($project, 'faasConfig', $config);

        return $this->createCodeConfigVersion($project, $stored->toArray(), $message ?? 'Config updated', $userId);
    }

    /**
     * Writes a config change to the project's config row ($relation) and returns the row as
     * stored. A project deleted while the request ran takes its config row with it — before the
     * row is loaded or between its load and the write (Eloquent's update does not tell) — and
     * answers 404 (Project::notFound()), not a server error.
     *
     * @throws ModelNotFoundException
     */
    private function updateConfigRow(Project $project, string $relation, array $config): Model
    {
        $row = $project->getRelationValue($relation);
        if (! $row instanceof Model) {
            throw $project->notFound();
        }
        $row->update(self::withNormalizedGpu($config));

        return $row->fresh() ?? throw $project->notFound();
    }

    // ── Public helpers ────────────────────────────────────────────────────────

    /** Starter code of an XaaS project without versions: the runtime's template */
    public function getDefaultCode(Project $project): string
    {
        $entry = $this->entrypoint($project);

        return $this->starterFiles($project, $entry, false)[$entry->filename] ?? '';
    }

    /** Starter files of a FaaS project without versions: template + dependency file */
    public function getDefaultFiles(Project $project): array
    {
        return $this->starterFiles($project, $this->entrypoint($project), true);
    }

    /**
     * The runtime manifest's starter template for the project's handler file and function; the
     * main file always exists ("{file}" names it), the dependency file only for FaaS.
     *
     * @return array<string, string>
     */
    public function starterFiles(Project $project, ProjectEntrypoint $entry, bool $withDependencies): array
    {
        $manifest = $this->runtimeManifest($project->runtime ?? 'python');
        if (! $manifest) {
            return [$entry->filename => '# Hello, World!'.PHP_EOL];
        }

        $files = $manifest->templateFiles($entry->filename, $entry->handler, $withDependencies);

        return [$entry->filename => $files[$entry->filename] ?? ''] + $files;
    }

    /** Handler file + function of a code project (ProjectEntrypoint). */
    private function entrypoint(Project $project): ProjectEntrypoint
    {
        $manifest = $this->runtimeManifest($project->runtime ?? 'python');
        if (! $manifest) {
            return new ProjectEntrypoint('main.txt', 'handler');
        }

        return ProjectEntrypoint::for($project, $manifest);
    }

    /** Returns the project meta fields — shared by all snapshot types */
    private function projectMeta(Project $project): array
    {
        return [
            'name' => $project->name,
            'description' => $project->description,
            'runtime' => $project->runtime,
            'runtime_version' => $project->runtime_version,
            'knative_region_id' => $project->knative_region_id,
        ];
    }

    /** XaaS/FaaS: write the config change to git as .config.json and create a version record */
    private function createCodeConfigVersion(Project $project, array $config, string $message, ?string $userId): Version
    {
        $snapshot = array_merge($this->projectMeta($project), $config);
        $json = json_encode(SecretRedactor::envNamesOnly($snapshot), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $nextNumber = ($project->versions()->max('number') ?? 0) + 1;
        $label = 'v'.$nextNumber;
        $commitMsg = $message ? "{$label}: {$message}" : "version: {$label}";

        $commitId = $this->git->writeFiles($project, ['.config.json' => $json], $commitMsg);

        return Version::create([
            'project_id' => $project->id,
            'number' => $nextNumber,
            'commit_id' => $commitId,
            'commit_message' => $message,
            'created_by' => $userId ?? 'system',
            'changed_files' => ['.config.json'],
            'config_snapshot' => $snapshot,
        ]);
    }

    private function createCaaSVersion(Project $project, string $message, ?string $userId): Version
    {
        $cc = $project->containerConfig;
        $config = array_merge($this->projectMeta($project), [
            'image' => $cc->image,
            'command' => $cc->command,
            'args' => $cc->args,
            'env_vars' => $cc->env_vars,
            'ports' => $cc->ports,
            'memory_mb' => $cc->memory_mb,
            'cpu' => $cc->cpu,
            'timeout_seconds' => $cc->timeout_seconds,
            'image_pull_policy' => $cc->image_pull_policy,
            'image_pull_secret' => $cc->image_pull_secret,
            'trigger_type' => $cc->trigger_type,
            'schedule' => $cc->schedule,
            'queue_stream' => $cc->queue_stream,
            'scale_to_zero' => $cc->scale_to_zero,
            'min_scale' => $cc->min_scale,
            'max_scale' => $cc->max_scale,
            'gpu' => $cc->gpu,
            'scale_schedule' => $cc->scale_schedule,
        ]);
        $json = json_encode(SecretRedactor::envNamesOnly($config), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $nextNumber = ($project->versions()->max('number') ?? 0) + 1;
        $label = 'v'.$nextNumber;
        $commitMsg = $message ? "{$label}: {$message}" : "version: {$label}";

        $commitId = $this->git->writeFiles($project, ['config.json' => $json], $commitMsg);

        return Version::create([
            'project_id' => $project->id,
            'number' => $nextNumber,
            'commit_id' => $commitId,
            'commit_message' => $message,
            'created_by' => $userId ?? 'system',
            'changed_files' => ['config.json'],
            'config_snapshot' => $config,
        ]);
    }
}
