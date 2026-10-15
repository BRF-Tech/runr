<?php

namespace App\Workflows\Engine;

use App\Models\Project;
use App\Services\GitService;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Models\WorkflowTrigger;
use App\Workflows\Support\JsonValue;
use App\Workflows\Transport\TransportException;
use App\Workflows\Transport\WorkflowTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Workflow definitions of a project.
 *
 *  - sync():    asks the deployed function (signed `introspect` request) which workflows it
 *               serves; when the function cannot answer, a `runr.workflows.json` manifest in
 *               the project's latest code version is used instead.
 *  - declare(): definitions sent through the API (CLI, IaC, UI).
 *
 * Definitions are upserted by name; workflows no longer declared are archived (their runs stay,
 * they start no new runs). Pausing (is_active) is kept across syncs.
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

        return $this->apply($project, $defs, $source);
    }

    /**
     * @param  list<Definition>  $defs
     * @return list<Workflow>
     */
    public function apply(Project $project, array $defs, string $source): array
    {
        return DB::transaction(function () use ($project, $defs, $source) {
            $existing = Workflow::where('project_id', $project->id)->get()->keyBy('name');
            $result = [];

            foreach ($defs as $def) {
                /** @var Workflow $workflow */
                $workflow = $existing->get($def->name) ?? new Workflow(['project_id' => $project->id, 'name' => $def->name, 'is_active' => true]);
                $workflow->fill([
                    'description' => $def->description,
                    'triggers' => $def->triggers,
                    'config' => $def->config,
                    'source' => $source,
                    'definition_hash' => $def->hash(),
                    'synced_at' => now(),
                    'archived_at' => null,
                ])->save();

                $this->rebuildTriggers($workflow, $def);
                $result[] = $workflow->fresh();
            }

            $declared = array_map(fn (Definition $d) => $d->name, $defs);
            foreach ($existing as $name => $workflow) {
                if (! in_array($name, $declared, true) && $workflow->archived_at === null) {
                    $workflow->forceFill(['archived_at' => now()])->save();
                    WorkflowTrigger::where('workflow_id', $workflow->id)->delete();
                }
            }

            return $result;
        });
    }

    private function rebuildTriggers(Workflow $workflow, Definition $def): void
    {
        // A cron trigger that did not change keeps its last tick (no second run in the same minute)
        $lastFired = WorkflowTrigger::where('workflow_id', $workflow->id)
            ->where('type', WorkflowTrigger::TYPE_CRON)
            ->get()
            ->mapWithKeys(fn ($t) => [$t->cron.'|'.$t->timezone => $t->last_fired_at]);

        WorkflowTrigger::where('workflow_id', $workflow->id)->delete();

        foreach ($def->triggers as $t) {
            if (isset($t['cron'])) {
                WorkflowTrigger::create([
                    'workflow_id' => $workflow->id,
                    'type' => WorkflowTrigger::TYPE_CRON,
                    'cron' => $t['cron'],
                    'timezone' => $t['timezone'] ?? null,
                    'last_fired_at' => $lastFired->get($t['cron'].'|'.($t['timezone'] ?? '')),
                ]);
            } elseif (isset($t['event'])) {
                WorkflowTrigger::create([
                    'workflow_id' => $workflow->id,
                    'type' => WorkflowTrigger::TYPE_EVENT,
                    'event' => $t['event'],
                    'expression' => $t['if'] ?? null,
                ]);
            }
        }

        foreach ($def->config['cancel_on'] ?? [] as $c) {
            WorkflowTrigger::create([
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

    /** `runr.workflows.json` of the latest code version: a list, or {"workflows": [...]}. */
    private function manifestFromCode(Project $project): mixed
    {
        try {
            $version = $project->latestVersion;
            if (! $version || ! $version->commit_id) {
                return null;
            }

            $content = app(GitService::class)->getFileAtVersion($project, $version->commit_id, self::MANIFEST_FILE);
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
