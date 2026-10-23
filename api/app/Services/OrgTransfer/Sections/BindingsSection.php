<?php

namespace App\Services\OrgTransfer\Sections;

use App\Models\Binding;
use App\Models\BindingRegionService;
use App\Services\Bindings\BindingManager;
use App\Services\Bindings\BindingQuotas;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Services\Residency\RegionPolicy;

/**
 * bindings/bindings.json: the organization's bindings (docs/bindings.md) — name, type, plan,
 * settings and region; never credentials (the import provisions new ones). The values of an
 * external binding are secrets: in secrets/secrets.enc when the export includes secrets. Their
 * data is BindingDataSection's.
 *
 * Import: BindingManager::create() in the mapped region (residency, data service ready, quotas)
 * — provisioning runs as for a binding created in the console; projects attach to it in the
 * projects section. A name taken in the organization needs a rename (binding_renames).
 */
class BindingsSection implements TransferSection
{
    public function __construct(private BindingManager $bindings, private BindingQuotas $quotas) {}

    public function key(): string
    {
        return 'bindings';
    }

    public function export(ExportContext $context): void
    {
        $rows = [];
        $bindings = Binding::query()
            ->whereNotIn('status', [Binding::STATUS_DELETING, Binding::STATUS_PURGING])
            ->with('region:id,name')
            ->orderBy('name')
            ->get();

        foreach ($bindings as $binding) {
            $rows[] = [
                'id' => (string) $binding->id,
                'name' => $binding->name,
                'resource_name' => $binding->resource_name,
                'type' => $binding->type,
                'plan' => $binding->plan,
                'knative_region_id' => $binding->knative_region_id,
                'region_name' => $binding->region?->name,
                'settings' => self::portableSettings((array) $binding->settings),
                'status' => $binding->status,
                'usage_bytes' => (int) $binding->usage_bytes,
            ];
            if ($binding->type === 'external') {
                $context->addSecret('bindings', (string) $binding->id, (array) $binding->credential('values', []));
            }
        }

        $context->putJson(PackageFormat::BINDINGS, $rows);
        $context->count('bindings', count($rows));
    }

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        $dataIndex = collect((array) $context->json(BindingDataSection::INDEX, []))->keyBy('binding_id');

        foreach ((array) $context->json(PackageFormat::BINDINGS, []) as $binding) {
            $sourceId = (string) ($binding['id'] ?? '');
            $name = $this->targetName($context, $binding);
            $region = $binding['type'] === 'external' ? null : $context->regionFor($binding['knative_region_id'] ?? null);
            $data = $dataIndex->get($sourceId);

            $row = [
                'source_id' => $sourceId,
                'name' => $binding['name'] ?? null,
                'type' => $binding['type'] ?? null,
                'plan' => $binding['plan'] ?? null,
                'target_name' => $name,
                'target_region_id' => $region?->id,
                'target_region' => $region?->name,
                'data' => $data !== null,
                'data_bytes' => $data['bytes'] ?? null,
                'status' => $context->mapped('binding', $sourceId) ? 'imported' : 'create',
                'issues' => [],
            ];

            if ($row['status'] === 'create') {
                $this->check($context, $plan, $row, $binding, $name, $region);
            }

            $plan->bindings[] = $row;
        }
    }

    public function import(ImportContext $context): void
    {
        $all = (array) $context->json(PackageFormat::BINDINGS, []);
        foreach ($all as $i => $binding) {
            $sourceId = (string) ($binding['id'] ?? '');
            if ($sourceId === '' || $context->settled('binding', $sourceId)) {
                continue;
            }
            $context->progress('bindings', 12, 20, $i, count($all), __('org_transfer.progress.binding', ['name' => $binding['name'] ?? $sourceId]));

            $name = $this->targetName($context, $binding);
            $region = $binding['type'] === 'external' ? null : $context->regionFor($binding['knative_region_id'] ?? null);
            if ($binding['type'] !== 'external' && $region === null) {
                $context->record('binding', $sourceId, $name, 'failed', null, __('org_transfer.plan.region_unmapped', ['region' => $binding['region_name'] ?? '?']));

                continue;
            }
            if (Binding::query()->where('name', $name)->exists()) {
                $context->record('binding', $sourceId, $name, 'failed', null, __('org_transfer.plan.binding_name_taken', ['name' => $name]));

                continue;
            }

            $values = null;
            if ($binding['type'] === 'external') {
                $values = $context->secret('bindings', $sourceId);
                if (! is_array($values)) {
                    $values = [];
                    $context->manual('secrets', __('org_transfer.manual.secrets'), [__('org_transfer.manual.binding_values', ['binding' => $name])]);
                }
            }

            try {
                $created = $this->bindings->create([
                    'name' => $name,
                    'type' => (string) $binding['type'],
                    'plan' => (string) ($binding['plan'] ?? Binding::PLAN_SHARED),
                    'knative_region_id' => $region?->id,
                    'settings' => (array) ($binding['settings'] ?? []),
                    'values' => $values,
                ], $context->userId());
                $context->record('binding', $sourceId, $name, 'created', (string) $created->id);
            } catch (\Throwable $e) {
                $context->record('binding', $sourceId, $name, 'failed', null, $e->getMessage());
            }
        }
    }

    /** The binding's name in the organization: the user's rename, else its own. */
    public function targetName(ImportContext $context, array $binding): string
    {
        $rename = $context->choices->bindingRenames[(string) ($binding['id'] ?? '')] ?? null;

        return $rename !== null && $rename !== '' ? $rename : (string) ($binding['name'] ?? '');
    }

    private function check(ImportContext $context, ImportPlan $plan, array &$row, array $binding, string $name, $region): void
    {
        $type = (string) ($binding['type'] ?? '');

        if (! preg_match(Binding::NAME_PATTERN, $name) || strlen($name) > 30) {
            $plan->issue($row, ImportPlan::ERROR, 'name_invalid', __('org_transfer.plan.binding_name_invalid', ['name' => $name]));
        } elseif (Binding::query()->where('name', $name)->exists()) {
            $row['collision'] = true;
            $row['suggested_name'] = $this->freeBindingName($name);
            $plan->issue($row, ImportPlan::ERROR, 'name_taken', __('org_transfer.plan.binding_name_taken', ['name' => $name]));
        }

        if ($type !== 'external') {
            if ($region === null) {
                $plan->issue($row, ImportPlan::ERROR, 'region_unmapped', __('org_transfer.plan.region_unmapped', ['region' => $binding['region_name'] ?? '?']));
            } else {
                if (! app(RegionPolicy::class)->allows($region, RegionPolicy::BINDING_CREATE)) {
                    $plan->issue($row, ImportPlan::ERROR, 'residency', __('org_transfer.plan.residency', ['region' => $region->name]));
                }
                if (! BindingRegionService::forRegion($region, $type)?->isReady()) {
                    $plan->issue($row, ImportPlan::ERROR, 'binding_service_missing', __('api.bindings.service_not_ready', ['service' => $type, 'region' => $region->name]));
                }
            }
        } elseif ($context->secretsState() !== 'unlocked') {
            $plan->issue($row, ImportPlan::MANUAL, 'secrets', __('org_transfer.manual.binding_values', ['binding' => $name]));
            $plan->manual('secrets', __('org_transfer.manual.secrets'), [__('org_transfer.manual.binding_values', ['binding' => $name])]);
        }

        $settings = BindingManager::normalizeSettings($type, (string) ($binding['plan'] ?? Binding::PLAN_SHARED), (array) ($binding['settings'] ?? []));
        if ($exceeded = $this->quotas->exceeded($type, (string) ($binding['plan'] ?? Binding::PLAN_SHARED), $settings)) {
            $plan->issue($row, ImportPlan::ERROR, 'binding_quota', __('api.bindings.quota_exceeded', $exceeded));
        }
    }

    private function freeBindingName(string $name): string
    {
        for ($i = 2; $i < 100; $i++) {
            $candidate = mb_substr($name, 0, 30 - strlen("-{$i}"))."-{$i}";
            if (! Binding::query()->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        return mb_substr($name, 0, 23).'-'.substr(bin2hex(random_bytes(3)), 0, 6);
    }

    /** Settings without what belongs to one installation (state RunR keeps, not choices). */
    private static function portableSettings(array $settings): array
    {
        unset($settings['over_quota'], $settings['db'], $settings['cluster']);

        return $settings;
    }
}
