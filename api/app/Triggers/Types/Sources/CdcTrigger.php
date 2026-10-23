<?php

namespace App\Triggers\Types\Sources;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Triggers\Cleanup\CdcSlotCleanup;
use App\Triggers\GatewayContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Postgres change data capture (runtime/queue-consumer/sources/cdc): logical replication with
 * pgoutput from a publication, one event per inserted / updated / deleted row
 * (dev.runr.cdc.<op>), strictly in commit order; the replication slot's position advances only
 * once every change of a transaction is delivered or dead-lettered. The DSN (a role with
 * REPLICATION) is the trigger's secret `dsn`. The slot defaults to runr_<project>_<trigger> (an
 * environment's gateway: runr_<workload>_<trigger>). When the trigger goes — removed, renamed, its
 * slot or database changed, its project or environment deleted — RunR drops the slots it read and
 * the publication it created, once no event gateway reads them any more (CdcSlotCleanup).
 */
class CdcTrigger extends BrokerTriggerType
{
    public const OPERATIONS = ['insert', 'update', 'delete'];

    public function key(): string
    {
        return 'cdc';
    }

    protected function configRules(): array
    {
        return [
            'publication' => 'required|string|max:63|regex:/^[a-z_][a-z0-9_]*$/',
            'create_publication' => 'nullable|boolean',
            'tables' => 'nullable|array|max:100',
            'tables.*' => 'string|max:127|regex:/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/',
            'slot' => 'nullable|string|max:63|regex:/^[a-z0-9_]+$/',
            'create_slot' => 'nullable|boolean',
            'operations' => 'nullable|array|min:1',
            'operations.*' => ['string', Rule::in(self::OPERATIONS)],
            'status_interval' => ['nullable', 'string', KafkaTrigger::DURATION],
        ];
    }

    public function secretRules(): array
    {
        return ['dsn' => ['nullable', 'string', 'max:2000', 'regex:#^postgres(ql)?://#']];
    }

    public function normalize(array $config): array
    {
        $normalized = self::only($config, ['publication', 'tables' => 'list', 'slot', 'status_interval']);
        $normalized['create_publication'] = (bool) ($config['create_publication'] ?? false);
        $normalized['create_slot'] = (bool) ($config['create_slot'] ?? true);
        $operations = array_values(array_intersect(self::OPERATIONS, (array) ($config['operations'] ?? self::OPERATIONS)));
        $normalized['operations'] = $operations ?: self::OPERATIONS;

        // Postgres concurrency would break the commit order: one delivery at a time
        $config['delivery'] = ['concurrency' => 1] + (array) ($config['delivery'] ?? []);
        $config['delivery']['concurrency'] = 1;

        return self::keepDelivery($config, $normalized);
    }

    public function validate(Validator $validator, Project $project, array $config, ?ProjectTrigger $existing): void
    {
        self::requireSecret($validator, $existing, 'dsn');
        if (! empty($config['create_publication']) && empty($config['tables'])) {
            $validator->errors()->add('config.tables', __('triggers.errors.cdc_tables_required'));
        }
    }

    protected function sourceConfig(ProjectTrigger $trigger, GatewayContext $context): array
    {
        $config = $trigger->config ?? [];

        return array_filter([
            'publication' => (string) ($config['publication'] ?? ''),
            'create_publication' => (bool) ($config['create_publication'] ?? false),
            'tables' => $config['tables'] ?? null,
            'slot' => ($config['slot'] ?? null) ?: self::slotName($context, $trigger),
            'create_slot' => (bool) ($config['create_slot'] ?? true),
            'operations' => $config['operations'] ?? self::OPERATIONS,
            'status_interval' => $config['status_interval'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** runr_<project>_<trigger> in Postgres' slot alphabet, at most 63 characters. */
    public static function slotName(GatewayContext $context, ProjectTrigger $trigger): string
    {
        return self::defaultSlot((string) $context->project->slug, (string) $trigger->name);
    }

    /** The default slot of a trigger in a workload (the project's slug, or an environment's workload name). */
    public static function defaultSlot(string $workload, string $trigger): string
    {
        $name = 'runr_'.preg_replace('/[^a-z0-9_]/', '_', strtolower($workload.'_'.$trigger));
        if (strlen($name) <= 63) {
            return $name;
        }

        return substr($name, 0, 54).'_'.substr(hash('sha256', $name), 0, 8);
    }

    /**
     * The trigger is gone: RunR drops its replication slots (and the publication it created) once
     * no event gateway reads them any more (CdcSlotCleanup) — Postgres keeps WAL for a slot nobody
     * reads.
     */
    public function deleted(ProjectTrigger $trigger, Project $project): void
    {
        app(CdcSlotCleanup::class)->removed($project, $trigger);
    }

    /** A rename, another slot or another database leaves the old slots behind: they are dropped too. */
    public function changed(ProjectTrigger $before, ProjectTrigger $after, Project $project): void
    {
        app(CdcSlotCleanup::class)->changed($project, $before, $after);
    }

    protected function sourceSecrets(ProjectTrigger $trigger): array
    {
        return ['dsn' => $trigger->secret('dsn')];
    }

    public function fields(): array
    {
        return [
            $this->field('dsn', required: true, secret: true),
            $this->field('publication', required: true),
            $this->field('create_publication', 'boolean', default: false),
            $this->field('tables', 'list'),
            $this->field('slot'),
            $this->field('create_slot', 'boolean', default: true),
            $this->field('operations', 'list', default: self::OPERATIONS),
        ];
    }
}
