<?php

namespace App\Services\OrgTransfer\Sections;

use App\Models\EnvironmentSecret;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\Environments\EnvironmentManager;
use App\Services\Environments\EnvironmentSecrets;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Services\Secrets\SecretBackendException;
use Illuminate\Support\Facades\Log;

/**
 * extensions/environments/environments.json (docs/environments.md): the environments of every
 * exported project besides its own workload — name, region, position, approval, git branch, auto
 * deploy, secrets inheritance and config overrides. Their own secret variables travel in
 * secrets/secrets.enc, read from the environment's region (their values live there only).
 *
 * Import: each environment is created again through EnvironmentManager (names, region capacity and
 * quota, data residency) in the mapped region; nothing is deployed — a promotion or a deploy puts a
 * version there. Secret values the package does not open are a manual step.
 */
class EnvironmentsSection implements TransferSection
{
    public const FILE = 'environments.json';

    public function __construct(
        private EnvironmentManager $environments,
        private EnvironmentSecrets $secrets,
    ) {}

    public function key(): string
    {
        return 'environments';
    }

    private static function entry(): string
    {
        return PackageFormat::extensionDir('environments').'/'.self::FILE;
    }

    public function export(ExportContext $context): void
    {
        if (! ProjectEnvironment::tablesReady()) {
            $context->putJson(self::entry(), ['projects' => []]);

            return;
        }

        $projects = [];
        $count = 0;
        foreach ($context->projects() as $project) {
            $rows = ProjectEnvironment::query()->where('project_id', $project->id)->where('is_primary', false)->orderBy('position')->get();
            if ($rows->isEmpty()) {
                continue;
            }
            $projects[(string) $project->id] = $rows->map(function (ProjectEnvironment $e) use ($context, $project) {
                $secrets = EnvironmentSecret::query()->where('environment_id', $e->id)->orderBy('name')->get();
                // The values live in the environment's region (pass-through): read from there; a
                // region that does not answer leaves them out (a manual step of the import)
                $values = [];
                if ($secrets->isNotEmpty()) {
                    try {
                        $values = $this->secrets->readValues($project, $e->setRelation('project', $project));
                    } catch (SecretBackendException $ex) {
                        Log::warning('Org export: environment secret values not read from the region', ['environment' => $e->id, 'error' => $ex->getMessage()]);
                    }
                }
                if ($values !== []) {
                    $context->addSecret('environments', (string) $e->id, $values);
                }

                return [
                    'id' => (string) $e->id,
                    'name' => $e->name,
                    'knative_region_id' => $e->knative_region_id,
                    'position' => (int) $e->position,
                    'requires_approval' => (bool) $e->requires_approval,
                    'git_branch' => $e->git_branch,
                    'auto_deploy' => (bool) $e->auto_deploy,
                    'inherit_secrets' => (bool) $e->inherit_secrets,
                    'overrides' => $e->overrides,
                    'secrets' => $secrets->pluck('name')->map(fn ($n) => (string) $n)->all(),
                ];
            })->values()->all();
            $count += $rows->count();
        }

        $context->putJson(self::entry(), ['projects' => $projects]);
        $context->count('environments', $count);
    }

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        // nothing blocks: an environment that does not fit the target is reported per entry
    }

    public function import(ImportContext $context): void
    {
        $projects = (array) (((array) $context->json(self::entry(), []))['projects'] ?? []);

        foreach ($projects as $sourceId => $environments) {
            $targetId = $context->mapped('project', (string) $sourceId);
            $project = $targetId ? Project::query()->find($targetId) : null;
            if (! $project) {
                continue;
            }

            foreach ((array) $environments as $env) {
                $key = (string) ($env['id'] ?? '');
                if ($key === '' || $context->settled('environment', $key)) {
                    continue;
                }
                $label = "{$project->name}/".($env['name'] ?? '?');
                $regionId = null;
                if (! empty($env['knative_region_id'])) {
                    $region = $context->regionFor((string) $env['knative_region_id']);
                    if ($region === null) {
                        $context->record('environment', $key, $label, 'failed', null, __('org_transfer.plan.region_unmapped', ['region' => (string) $env['knative_region_id']]));

                        continue;
                    }
                    $regionId = (string) $region->id;
                }

                try {
                    $created = $this->environments->create($project, [
                        'name' => (string) $env['name'],
                        'knative_region_id' => $regionId,
                        'position' => isset($env['position']) ? (int) $env['position'] : null,
                        'requires_approval' => (bool) ($env['requires_approval'] ?? false),
                        'git_branch' => $env['git_branch'] ?? null,
                        'auto_deploy' => (bool) ($env['auto_deploy'] ?? true),
                        'inherit_secrets' => (bool) ($env['inherit_secrets'] ?? true),
                        'overrides' => is_array($env['overrides'] ?? null) ? $env['overrides'] : null,
                    ], $context->userId());
                } catch (\Throwable $e) {
                    $message = method_exists($e, 'getResponse') ? (string) ($e->getResponse()->getData(true)['message'] ?? $e->getMessage()) : $e->getMessage();
                    $context->record('environment', $key, $label, 'failed', null, $message);

                    continue;
                }

                $names = array_map('strval', (array) ($env['secrets'] ?? []));
                $values = $context->secret('environments', $key);
                $missing = $names;
                if (is_array($values)) {
                    $set = [];
                    foreach ($values as $name => $value) {
                        try {
                            // into the environment's region (pass-through); one it does not take is a manual step
                            $this->environments->setSecret($created->setRelation('project', $project), (string) $name, (string) $value, null);
                            $set[] = (string) $name;
                        } catch (SecretBackendException $e) {
                            Log::warning('Org import: environment secret value not written to the region', ['environment' => $created->id, 'error' => $e->getMessage()]);
                        }
                    }
                    $missing = array_values(array_diff($names, $set));
                }
                if ($missing !== []) {
                    $context->manual('secrets', __('org_transfer.manual.secrets'), array_map(fn ($n) => "{$label}: {$n}", $missing));
                }
                $context->record('environment', $key, $label, $missing ? 'manual' : 'created', (string) $created->id);
            }
        }
    }
}
