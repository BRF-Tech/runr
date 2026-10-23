<?php

namespace App\Services\OrgTransfer\Sections;

use App\Models\Binding;
use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingsCluster;
use App\Services\Bindings\DataServices\KvService;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\OrgTransfer\OrgTransferPaths;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Services\OrgTransfer\Region\BindingDumps;
use App\Services\OrgTransfer\Region\RegionTransfer;
use App\Services\OrgTransfer\Region\RegionTransferException;
use Illuminate\Support\Collection;

/**
 * bindings/data/<binding id>.<ext> + bindings/data/index.json: the data of the bindings the user
 * picked (docs/org-export.md#binding-data) — each optional, the size estimate (the binding's
 * measured usage) shown before the export starts. A dump is made in the binding's region by a
 * transfer pod (BindingDumps) and pulled through the Kubernetes API.
 *
 * Import (restore_data, minus skip_binding_data): once the new binding is provisioned, a transfer
 * pod in its region receives the dump and restores it. A binding that does not become ready in
 * time, or a restore that fails, is reported; applying the import again retries it.
 *
 * KV dumps are Redis DUMP payloads, which restore into a Redis of the same or a newer version
 * only: the dump records the server's version (redis_version) and the plan refuses a target region
 * whose KV Redis is older - or whose version it cannot read - naming both versions; a package
 * without a recorded version gets a warning. Converting a payload to an older encoding is not
 * possible (RESTORE refuses a newer RDB version), so the choice is upgrading the region's Redis or
 * leaving that binding's data out (skip_binding_data).
 */
class BindingDataSection implements TransferSection
{
    public const INDEX = 'bindings/data/index.json';

    /** Seconds the import waits for a new binding to be provisioned. */
    private const READY_TIMEOUT = 900;

    public function __construct(private RegionTransfer $transfer, private BindingDumps $dumps) {}

    public function key(): string
    {
        return 'binding_data';
    }

    /** The bindings an export's options pick for their data. */
    public static function selected(array $options): Collection
    {
        $choice = $options['binding_data'] ?? [];
        if ($choice !== 'all' && (! is_array($choice) || $choice === [])) {
            return collect();
        }

        return Binding::query()->with('region')
            ->whereIn('type', array_keys(BindingDumps::FORMATS))
            ->where('status', Binding::STATUS_READY)
            ->when(is_array($choice), fn ($q) => $q->whereIn('id', $choice))
            ->orderBy('name')
            ->get();
    }

    public function export(ExportContext $context): void
    {
        $bindings = self::selected($context->options);
        if ($bindings->isEmpty()) {
            return;
        }

        $index = [];
        foreach ($bindings->values() as $i => $binding) {
            $context->progress('binding_data', 45, 70, $i, $bindings->count(), __('org_transfer.progress.binding_data', ['name' => $binding->name]));
            if (! $this->dumps->supports($binding) || ! $binding->region?->kubeconfig || $binding->region->is_demo) {
                $context->skip('binding_data', $binding->name, __('org_transfer.export.data_unsupported'));

                continue;
            }

            $entry = PackageFormat::bindingDataFile((string) $binding->id, BindingDumps::extension($binding->type));
            $file = BindingDumps::fileName($binding->type);
            $handle = null;
            try {
                app(BindingsCluster::class)->ensureDataNamespace($binding->region);
                $handle = $this->transfer->startSender($binding->region, BindingsCluster::dataNamespace(), 'dump-'.$binding->resource_name, $this->dumps->dumpTool($binding));
                $status = $this->transfer->waitFor($handle, ['done']);
                $size = (int) (collect((array) ($status['files'] ?? []))->firstWhere('name', $file)['size'] ?? -1);
                if ($size < 0) {
                    throw new RegionTransferException(__('org_transfer.region.file_missing', ['name' => $file]));
                }
                $this->transfer->download($handle, $file, $size, $context->path($entry),
                    fn ($done, $total) => $context->progress('binding_data', 45, 70, $i + ($total > 0 ? $done / $total : 1), $bindings->count(), __('org_transfer.progress.binding_data', ['name' => $binding->name])));
                $dump = [
                    'binding_id' => (string) $binding->id,
                    'file' => $entry,
                    'format' => BindingDumps::format($binding->type),
                    'type' => $binding->type,
                    'bytes' => $size,
                ];
                if ($binding->type === 'kv') {
                    $version = (string) (((array) ($status['result'] ?? []))['redis_version'] ?? '');
                    $dump['redis_version'] = self::isVersion($version) ? $version : null;
                }
                $index[] = $dump;
                $context->count('binding_data');
            } catch (\Throwable $e) {
                @unlink($context->path($entry));
                $context->skip('binding_data', $binding->name, $e->getMessage());
            } finally {
                if ($handle) {
                    $this->transfer->stop($handle);
                }
            }
        }

        $context->putJson(self::INDEX, $index);
    }

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        $index = collect((array) $context->json(self::INDEX, []))->filter(fn ($d) => is_array($d))->keyBy(fn ($d) => (string) ($d['binding_id'] ?? ''));
        if ($index->isEmpty()) {
            return;
        }
        // BindingsSection shows the data per binding (data, data_bytes); this adds whether it is
        // restored and, for KV dumps, whether the target's Redis can take the payloads
        if (! $context->choices->restoreData) {
            $plan->generalIssue(ImportPlan::WARNING, 'data_not_restored', __('org_transfer.plan.data_not_restored'));
        }

        foreach ($plan->bindings as &$row) {
            $sourceId = (string) ($row['source_id'] ?? '');
            $dump = $index->get($sourceId);
            if ($dump === null) {
                continue;
            }
            $row['data_restore'] = $context->choices->restoresDataOf($sourceId);
            if (($dump['type'] ?? null) !== 'kv') {
                continue;
            }
            $row['data_redis_version'] = $dump['redis_version'] ?? null;
            if ($row['data_restore'] && ($row['status'] ?? 'create') !== 'skip' && ! $context->settled('binding_data', $sourceId)) {
                $this->checkKvVersion($plan, $row, $dump);
            }
        }
        unset($row);
    }

    /**
     * A KV dump restores into a Redis of the same or a newer version only: the target region's KV
     * Redis older than the dump's, or its version unknown, blocks; a dump without a recorded
     * version (an older package) is a warning.
     */
    private function checkKvVersion(ImportPlan $plan, array &$row, array $dump): void
    {
        $name = (string) ($row['target_name'] ?? $row['name'] ?? '');
        $source = $dump['redis_version'] ?? null;
        if (! is_string($source) || ! self::isVersion($source)) {
            $plan->issue($row, ImportPlan::WARNING, 'kv_version_unrecorded', __('org_transfer.plan.kv_version_unrecorded', ['binding' => $name]));

            return;
        }

        $region = ! empty($row['target_region_id']) ? KnativeRegion::query()->find($row['target_region_id']) : null;
        if ($region === null) {
            return; // region_unmapped blocks already
        }
        if (! BindingRegionService::forRegion($region, 'kv')?->isReady()) {
            return; // binding_service_missing blocks already
        }
        $target = $this->targetVersion($region, $source);
        $row['target_redis_version'] = $target;
        if ($target === null) {
            $plan->issue($row, ImportPlan::ERROR, 'kv_version_unknown', __('org_transfer.plan.kv_version_unknown', ['binding' => $name, 'version' => $source, 'region' => $region->name]));
        } elseif (version_compare($target, $source, '<')) {
            $plan->issue($row, ImportPlan::ERROR, 'kv_version_older', __('org_transfer.plan.kv_version_older', ['binding' => $name, 'version' => $source, 'region' => $region->name, 'target' => $target]));
        }
    }

    /**
     * The target region's KV Redis version; a recorded version older than the dump's is read again
     * (the region's Redis may have been upgraded since it was recorded).
     */
    private function targetVersion(KnativeRegion $region, string $source): ?string
    {
        $kv = app(KvService::class);
        $target = $kv->serverVersion($region);
        if ($target !== null && version_compare($target, $source, '<')) {
            $target = $kv->serverVersion($region, true);
        }

        return $target;
    }

    private static function isVersion(string $version): bool
    {
        return (bool) preg_match('/^\d+(\.\d+)*$/', $version);
    }

    public function import(ImportContext $context): void
    {
        $index = (array) $context->json(self::INDEX, []);
        if ($index === [] || ! $context->choices->restoreData) {
            return;
        }

        foreach ($index as $i => $dump) {
            $sourceId = (string) ($dump['binding_id'] ?? '');
            if ($sourceId === '' || $context->settled('binding_data', $sourceId)) {
                continue;
            }
            $bindingId = $context->mapped('binding', $sourceId);
            $binding = $bindingId ? Binding::query()->with('region')->find($bindingId) : null;
            $source = $context->file((string) ($dump['file'] ?? ''));
            if (! $binding || $source === null) {
                $context->record('binding_data', $sourceId, $binding->name ?? $sourceId, 'skipped', null, __('org_transfer.import.binding_missing'));

                continue;
            }
            if (($dump['type'] ?? null) !== $binding->type || ! $this->dumps->supports($binding)) {
                $context->record('binding_data', $sourceId, $binding->name, 'skipped', null, __('org_transfer.export.data_unsupported'));

                continue;
            }
            if (! $context->choices->restoresDataOf($sourceId)) {
                $context->record('binding_data', $sourceId, $binding->name, 'skipped', null, __('org_transfer.import.data_left_out'));

                continue;
            }

            $context->progress('binding_data', 70, 85, $i, count($index), __('org_transfer.progress.binding_restore', ['name' => $binding->name]));
            $handle = null;
            try {
                $binding = $this->waitReady($context, $binding);
                $dumpedWith = $dump['redis_version'] ?? null;
                if ($binding->type === 'kv' && is_string($dumpedWith) && self::isVersion($dumpedWith) && $binding->region !== null) {
                    // The plan checked it; the region's Redis may have changed since
                    $target = $this->targetVersion($binding->region, $dumpedWith);
                    if ($target === null || version_compare($target, $dumpedWith, '<')) {
                        throw new RegionTransferException($target === null
                            ? __('org_transfer.plan.kv_version_unknown', ['binding' => $binding->name, 'version' => $dumpedWith, 'region' => $binding->region->name])
                            : __('org_transfer.plan.kv_version_older', ['binding' => $binding->name, 'version' => $dumpedWith, 'region' => $binding->region->name, 'target' => $target]));
                    }
                }
                $file = BindingDumps::fileName($binding->type);
                $sha = (string) hash_file('sha256', $source);
                app(BindingsCluster::class)->ensureDataNamespace($binding->region);
                $handle = $this->transfer->startReceiver($binding->region, BindingsCluster::dataNamespace(), 'restore-'.$binding->resource_name,
                    $this->dumps->restoreTool($binding), [$file => ['size' => (int) filesize($source), 'sha256' => $sha]]);
                $this->transfer->waitFor($handle, ['receiving', 'ready', 'done'], null, 300);
                $this->transfer->upload($handle, $file, $source,
                    fn ($done, $total) => $context->progress('binding_data', 70, 85, $i + ($total > 0 ? $done / $total : 1), count($index), __('org_transfer.progress.binding_restore', ['name' => $binding->name])));
                $this->transfer->waitFor($handle, ['done']);
                $context->record('binding_data', $sourceId, $binding->name, 'created', (string) $binding->id, (string) ($dump['bytes'] ?? '').' bytes');
            } catch (\Throwable $e) {
                $context->record('binding_data', $sourceId, $binding->name, 'failed', null, $e->getMessage());
            } finally {
                if ($handle) {
                    $this->transfer->stop($handle);
                }
            }
        }

        OrgTransferPaths::remove($context->transfer, 'work');
    }

    /** The new binding once it is provisioned; it was created by this import moments ago. */
    private function waitReady(ImportContext $context, Binding $binding): Binding
    {
        $deadline = time() + self::READY_TIMEOUT;
        while ($binding->status !== Binding::STATUS_READY) {
            if ($binding->status === Binding::STATUS_ERROR) {
                throw new RegionTransferException(__('org_transfer.import.binding_not_ready', ['name' => $binding->name, 'status' => $binding->status_message ?? $binding->status]));
            }
            if (time() >= $deadline) {
                throw new RegionTransferException(__('org_transfer.import.binding_not_ready', ['name' => $binding->name, 'status' => $binding->status]));
            }
            $context->assertNotCancelled();
            $this->pause();
            $binding = $binding->fresh(['region']) ?? $binding;
        }

        return $binding;
    }

    protected function pause(): void
    {
        sleep(5);
    }
}
