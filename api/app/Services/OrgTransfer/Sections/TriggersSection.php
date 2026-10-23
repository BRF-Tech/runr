<?php

namespace App\Services\OrgTransfer\Sections;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\Topic;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Triggers\TriggerService;

/**
 * extensions/triggers/triggers.json (docs/triggers.md, docs/org-export.md#sections): the trigger
 * list of every exported project — name, type, config, enabled, position — and the organization's
 * topics. A trigger's secrets (a webhook's signing secret, a broker's password) travel in
 * secrets/secrets.enc like secret variables.
 *
 * Import: the organization's topics in the mapped regions (one of the same name is kept), then each
 * imported project's list replaces the one its config gave it (TriggerService::replace, every
 * check of the API). A webhook gets a new inbox URL; secrets the package does not open are a manual
 * step.
 */
class TriggersSection implements TransferSection
{
    public const FILE = 'triggers.json';

    public function __construct(private TriggerService $triggers) {}

    public function key(): string
    {
        return 'triggers';
    }

    private static function entry(): string
    {
        return PackageFormat::extensionDir('triggers').'/'.self::FILE;
    }

    public function export(ExportContext $context): void
    {
        $projects = [];
        $count = 0;
        foreach ($context->projects() as $project) {
            $rows = ProjectTrigger::query()->where('project_id', $project->id)->orderBy('position')->get();
            if ($rows->isEmpty()) {
                continue;
            }
            $projects[(string) $project->id] = $rows->map(function (ProjectTrigger $t) use ($context) {
                $secrets = (array) ($t->secrets ?? []);
                if ($secrets !== []) {
                    $context->addSecret('triggers', (string) $t->id, $secrets);
                }

                return [
                    'id' => (string) $t->id,
                    'name' => $t->name,
                    'type' => $t->type,
                    'config' => $t->config,
                    'enabled' => (bool) $t->enabled,
                    'position' => (int) $t->position,
                    'secret_names' => array_map('strval', array_keys($secrets)),
                ];
            })->values()->all();
            $count += $rows->count();
        }

        $topics = Topic::query()->orderBy('name')->get()->map(fn (Topic $t) => [
            'id' => (string) $t->id,
            'name' => $t->name,
            'knative_region_id' => $t->knative_region_id,
            'description' => $t->description,
        ])->values()->all();

        $context->putJson(self::entry(), ['projects' => $projects, 'topics' => $topics]);
        $context->count('triggers', $count);
        $context->count('topics', count($topics));
    }

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        $data = (array) $context->json(self::entry(), []);
        $webhooks = [];
        foreach ((array) ($data['projects'] ?? []) as $triggers) {
            foreach ((array) $triggers as $trigger) {
                if (($trigger['type'] ?? null) === 'webhook') {
                    $webhooks[] = (string) ($trigger['name'] ?? 'webhook');
                }
            }
        }
        if ($webhooks !== []) {
            $plan->manual('webhook_urls', __('org_transfer.manual.webhook_urls'), $webhooks);
        }
    }

    public function import(ImportContext $context): void
    {
        $data = (array) $context->json(self::entry(), []);

        foreach ((array) ($data['topics'] ?? []) as $topic) {
            $key = (string) ($topic['id'] ?? '');
            if ($key === '' || $context->settled('topic', $key)) {
                continue;
            }
            $region = $context->regionFor($topic['knative_region_id'] ?? null);
            if ($region === null) {
                $context->record('topic', $key, (string) ($topic['name'] ?? $key), 'failed', null, __('org_transfer.plan.region_unmapped', ['region' => (string) ($topic['knative_region_id'] ?? '?')]));

                continue;
            }
            $existing = Topic::query()->where('name', (string) $topic['name'])->where('knative_region_id', $region->id)->first();
            $created = $existing ?? Topic::query()->create([
                'name' => (string) $topic['name'],
                'knative_region_id' => $region->id,
                'description' => $topic['description'] ?? null,
                'created_by' => $context->userId(),
            ]);
            $context->record('topic', $key, $created->name, $existing ? 'skipped' : 'created', (string) $created->id);
        }

        foreach ((array) ($data['projects'] ?? []) as $sourceId => $triggers) {
            $key = (string) $sourceId;
            if ($context->settled('triggers', $key)) {
                continue;
            }
            $targetId = $context->mapped('project', $key);
            $project = $targetId ? Project::query()->find($targetId) : null;
            if (! $project) {
                continue;
            }

            $missing = [];
            $list = array_map(function (array $trigger) use ($context, &$missing, $project) {
                $secrets = $context->secret('triggers', (string) ($trigger['id'] ?? ''));
                $names = (array) ($trigger['secret_names'] ?? []);
                if ($names !== [] && ! is_array($secrets)) {
                    foreach ($names as $name) {
                        $missing[] = "{$project->name}: {$trigger['name']} ({$name})";
                    }
                }

                return array_filter([
                    'name' => $trigger['name'] ?? null,
                    'type' => $trigger['type'] ?? null,
                    'config' => $trigger['config'] ?? [],
                    'enabled' => (bool) ($trigger['enabled'] ?? true),
                    'secrets' => is_array($secrets) && $secrets !== [] ? $secrets : null,
                ], fn ($v) => $v !== null);
            }, array_values((array) $triggers));

            try {
                $this->triggers->replace($project, $list, $context->userId());
                $context->record('triggers', $key, $project->name, $missing ? 'manual' : 'created', (string) $project->id, $missing ? implode(', ', $missing) : null);
                if ($missing) {
                    $context->manual('secrets', __('org_transfer.manual.secrets'), $missing);
                }
            } catch (\Throwable $e) {
                $context->record('triggers', $key, $project->name, 'failed', null, $e->getMessage());
            }
        }
    }
}
