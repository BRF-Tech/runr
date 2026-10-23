<?php

namespace App\Workflows\Engine;

use App\Models\Project;
use App\Models\Version;
use App\Services\Environments\EnvironmentScope;
use App\Services\GitService;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Models\WorkflowTrigger;
use App\Workflows\Support\JsonValue;
use App\Workflows\Support\WorkflowEnvironments;
use App\Workflows\Transport\TransportException;
use App\Workflows\Transport\WorkflowTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Workflow definitions of a project in one environment (the Project passed in: the project itself
 * for production, an EnvironmentProject view for another environment; docs/workflows.md#environments).
 *
 *  - sync():    asks the deployed function of the environment (signed `introspect` request with
 *               the environment's key) which workflows it serves; when the function cannot
 *               answer, a `runr.workflows.json` manifest is used instead: production's from the
 *               project's latest code version, an environment's from the version it runs.
 *  - declare(): the set of definitions sent through the API (CLI, IaC, UI).
 *  - declareOne() / archive(): one definition through the API; the others stay as they are.
 *
 * Definitions are upserted by name within the environment; workflows no longer declared are
 * archived (their runs stay, they start no new runs). Pausing (is_active) is kept across syncs.
 * A declaration names where a definition comes from (source: sdk, manifest, api, import): one the
 * API declares again unchanged keeps its source — re-declaring the set does not turn what the
 * function's SDK reported into an API definition. A sync always takes the code's source.
 */
class WorkflowRegistry
{
    public const MANIFEST_FILE = 'runr.workflows.json';

    public function __construct(
        private readonly WorkflowTransport $transport,
        private readonly EndpointResolver $endpoints,
    ) {}

    /**
     * @return array{source: string, workflows: list<Workflow>, sdk: array|null}
     *
     * @throws WorkflowException
     */
    public function sync(Project $project): array
    {
        $app = WorkflowApp::forProject($project);

        try {
            [$defs, $sdk] = $this->introspect($project, $app);
            $source = 'sdk';
        } catch (WorkflowException|TransportException|ProtocolException|DefinitionException $e) {
            $manifest = $this->manifestFromCode($project);
            if ($manifest === null) {
                $app->forceFill(['sync_status' => 'error', 'sync_error' => mb_substr($e->getMessage(), 0, 2000)])->save();

                throw $e instanceof WorkflowException ? $e : new WorkflowException(__('workflows.sync_failed', ['error' => $e->getMessage()]), 422, 'sync_failed');
            }

            try {
                $defs = Definition::listFromArray($manifest);
            } catch (DefinitionException $de) {
                $app->forceFill(['sync_status' => 'error', 'sync_error' => mb_substr($de->getMessage(), 0, 2000)])->save();

                throw new WorkflowException(__('workflows.manifest_invalid', ['error' => $de->getMessage()]), 422, 'manifest_invalid');
            }
            $sdk = null;
            $source = 'manifest';
        }

        $workflows = $this->apply($project, $defs, $source);

        $app->forceFill([
            'sync_status' => 'ok',
            'sync_error' => null,
            'sdk' => $sdk,
            'last_synced_at' => now(),
        ])->save();

        return ['source' => $source, 'workflows' => $workflows, 'sdk' => $sdk];
    }

    /**
     * @return list<Workflow>
     *
     * @throws WorkflowException
     */
    public function declare(Project $project, mixed $workflows, string $source = 'api'): array
    {
        try {
            $defs = Definition::listFromArray($workflows);
        } catch (DefinitionException $e) {
            throw WorkflowException::invalid($e->getMessage(), 'definition_invalid');
        }

        WorkflowApp::forProject($project);

        return $this->apply($project, $defs, $source, keepUnchangedSource: true);
    }

    /**
     * One definition declared through the API (PUT /projects/{project}/workflows/{workflow}): added
     * or replaced; the environment's other definitions stay as they are (no archiving, sources
     * kept). Unchanged, it keeps its source too.
     *
     * @param  array<string, mixed>  $definition  the definition without its name, or with the same name
     *
     * @throws WorkflowException
     */
    public function declareOne(Project $project, string $name, array $definition, string $source = 'api'): Workflow
    {
        $given = $definition['name'] ?? $definition['id'] ?? null;
        if ($given !== null && $given !== $name) {
            throw WorkflowException::invalid(__('workflows.name_mismatch', ['name' => is_string($given) ? $given : '?', 'workflow' => $name]), 'definition_invalid');
        }
        unset($definition['id']);

        try {
            $def = Definition::fromArray(['name' => $name] + $definition);
        } catch (DefinitionException $e) {
            throw WorkflowException::invalid($e->getMessage(), 'definition_invalid');
        }

        $environmentId = WorkflowEnvironments::idOf($project);
        $max = (int) config('workflows.limits.max_workflows_per_project', 200);
        $live = Workflow::where('project_id', $project->id)->inEnvironment($environmentId)->whereNull('archived_at');
        if (! (clone $live)->where('name', $name)->exists() && $live->count() >= $max) {
            throw WorkflowException::invalid("At most {$max} workflows per project", 'definition_invalid');
        }

        WorkflowApp::forProject($project);

        return $this->apply($project, [$def], $source, keepUnchangedSource: true, archiveOthers: false)[0];
    }

    /**
     * Archive one definition (DELETE /projects/{project}/workflows/{workflow}): its runs stay, it
     * starts no new runs; a later declaration or sync that names it brings it back. The others
     * stay as they are.
     *
     * @throws WorkflowException
     */
    public function archive(Project $project, string $name): Workflow
    {
        $workflow = Workflow::where('project_id', $project->id)
            ->inEnvironment(WorkflowEnvironments::idOf($project))
            ->where('name', $name)
            ->first();
        if (! $workflow) {
            throw new WorkflowException(__('workflows.not_found'), 404, 'workflow_not_found');
        }

        if ($workflow->archived_at === null) {
            DB::transaction(function () use ($workflow) {
                $workflow->forceFill(['archived_at' => now()])->save();
                WorkflowTrigger::where('workflow_id', $workflow->id)->delete();
            });
        }

        return $workflow->fresh();
    }

    /**
     * Upsert the definitions in the project's environment. $keepUnchangedSource: a live
     * definition whose content is the same keeps its source and sync time (an API declaration
     * of what the SDK reported); a sync passes false — the code names the source. $archiveOthers:
     * the definitions not in $defs are archived (a whole set).
     *
     * @param  list<Definition>  $defs
     * @return list<Workflow>
     */
    public function apply(Project $project, array $defs, string $source, bool $keepUnchangedSource = false, bool $archiveOthers = true): array
    {
        $environmentId = WorkflowEnvironments::idOf($project);
        $environment = WorkflowEnvironments::nameOf($project);

        return DB::transaction(function () use ($project, $defs, $source, $environmentId, $environment, $keepUnchangedSource, $archiveOthers) {
            $existing = Workflow::where('project_id', $project->id)->inEnvironment($environmentId)->get()->keyBy('name');
            $result = [];

            foreach ($defs as $def) {
                /** @var Workflow $workflow */
                $workflow = $existing->get($def->name) ?? new Workflow(['project_id' => $project->id, 'name' => $def->name, 'is_active' => true]
                    + ($environmentId !== null ? ['environment_id' => $environmentId] : []));
                $unchanged = $keepUnchangedSource && $workflow->exists && $workflow->archived_at === null
                    && $workflow->definition_hash === $def->hash();
                $workflow->fill([
                    'description' => $def->description,
                    'triggers' => $def->triggers,
                    'config' => $def->config,
                    'source' => $unchanged ? $workflow->source : $source,
                    'definition_hash' => $def->hash(),
                    'synced_at' => $unchanged && $workflow->synced_at !== null ? $workflow->synced_at : now(),
                    'archived_at' => null,
                ])->save();

                $this->rebuildTriggers($workflow, $def, $environment);
                $result[] = $workflow->fresh();
            }

            if ($archiveOthers) {
                $declared = array_map(fn (Definition $d) => $d->name, $defs);
                foreach ($existing as $name => $workflow) {
                    if (! in_array($name, $declared, true) && $workflow->archived_at === null) {
                        $workflow->forceFill(['archived_at' => now()])->save();
                        WorkflowTrigger::where('workflow_id', $workflow->id)->delete();
                    }
                }
            }

            return $result;
        });
    }

    /** @param  string|null  $environment  the workflow's environment name (null: production) */
    private function rebuildTriggers(Workflow $workflow, Definition $def, ?string $environment = null): void
    {
        $scope = WorkflowEnvironments::ready() ? ['environment' => $environment] : [];

        // A cron trigger that did not change keeps its last tick (no second run in the same minute)
        $lastFired = WorkflowTrigger::where('workflow_id', $workflow->id)
            ->where('type', WorkflowTrigger::TYPE_CRON)
            ->get()
            ->mapWithKeys(fn ($t) => [$t->cron.'|'.$t->timezone => $t->last_fired_at]);

        WorkflowTrigger::where('workflow_id', $workflow->id)->delete();

        foreach ($def->triggers as $t) {
            if (isset($t['cron'])) {
                WorkflowTrigger::create($scope + [
                    'workflow_id' => $workflow->id,
                    'type' => WorkflowTrigger::TYPE_CRON,
                    'cron' => $t['cron'],
                    'timezone' => $t['timezone'] ?? null,
                    'last_fired_at' => $lastFired->get($t['cron'].'|'.($t['timezone'] ?? '')),
                ]);
            } elseif (isset($t['event'])) {
                WorkflowTrigger::create($scope + [
                    'workflow_id' => $workflow->id,
                    'type' => WorkflowTrigger::TYPE_EVENT,
                    'event' => $t['event'],
                    'expression' => $t['if'] ?? null,
                ]);
            }
        }

        foreach ($def->config['cancel_on'] ?? [] as $c) {
            WorkflowTrigger::create($scope + [
                'workflow_id' => $workflow->id,
                'type' => WorkflowTrigger::TYPE_CANCEL,
                'event' => $c['event'],
                'expression' => $c['expression'] ?? null,
            ]);
        }
    }

    /**
     * @return array{0: list<Definition>, 1: array|null}
     *
     * @throws WorkflowException|TransportException|ProtocolException|DefinitionException
     */
    private function introspect(Project $project, WorkflowApp $app): array
    {
        $url = $this->endpoints->resolve($project, $app);
        if ($url === null) {
            throw new WorkflowException(__('workflows.endpoint_unknown'), 422, 'endpoint_unknown');
        }

        $envelope = Protocol::envelope(['type' => Protocol::TYPE_INTROSPECT, 'protocol' => Protocol::VERSION, 'ts' => time()], $app->signingKeys());
        $response = $this->transport->send($url, $envelope['body'], $envelope['headers']);
        $decoded = Protocol::decodeResponse($response->body);

        $defs = Definition::listFromArray(JsonValue::toArray($decoded->workflows ?? null));
        $sdk = isset($decoded->sdk) && is_object($decoded->sdk) ? JsonValue::toArray($decoded->sdk) : null;

        return [$defs, $sdk];
    }

    /**
     * `runr.workflows.json` of the code: a list, or {"workflows": [...]} — production's latest code
     * version, the version an environment runs.
     */
    private function manifestFromCode(Project $project): mixed
    {
        try {
            if (WorkflowEnvironments::idOf($project) !== null) {
                $versionId = WorkflowEnvironments::liveVersionId($project);
                $version = $versionId !== null ? Version::query()->find($versionId) : null;
            } else {
                $version = $project->latestVersion;
            }
            if (! $version || ! $version->commit_id) {
                return null;
            }

            // The project's repository (an environment's view names its workload, not the repository)
            $content = app(GitService::class)->getFileAtVersion(EnvironmentScope::base($project), $version->commit_id, self::MANIFEST_FILE);
            if ($content === null || trim($content) === '') {
                return null;
            }

            $decoded = json_decode($content, true);
            if (is_array($decoded) && isset($decoded['workflows'])) {
                return $decoded['workflows'];
            }

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable $e) {
            Log::warning('Workflow manifest could not be read', ['project' => $project->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
