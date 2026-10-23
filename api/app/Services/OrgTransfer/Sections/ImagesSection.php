<?php

namespace App\Services\OrgTransfer\Sections;

use App\Models\Project;
use App\Models\Version;
use App\Services\FunctionBuildService;
use App\Services\Images\ImageUploadService;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Services\OrgTransfer\Region\RegionTransfer;
use App\Services\OrgTransfer\Region\RegionTransferException;
use App\Services\OrgTransfer\Region\TransferTool;
use Illuminate\Support\Collection;

/**
 * projects/<id>/images/v<N>.oci.tar + projects/images.json: the prebuilt images of the exported
 * projects (optional, include_images — they can be large): the version the project runs and its
 * latest ready build, pulled from the region's registry as OCI layout tars (every platform) by a
 * transfer pod.
 *
 * Import (images): pushed into the target region's registry under the name its builds use
 * (runr-builds/<slug>:v<N>) by a transfer pod, the version then points at it — deployable
 * without a build. Without an image a version is built again on its first deploy.
 */
class ImagesSection implements TransferSection
{
    public const INDEX = 'projects/images.json';

    public function __construct(private RegionTransfer $transfer) {}

    public function key(): string
    {
        return 'images';
    }

    /** The versions whose images an export takes: the one running and the latest ready build. */
    public static function versionsFor(Project $project): Collection
    {
        $running = $project->activeDeployment()->value('version_id');
        $latest = Version::query()->where('project_id', $project->id)->where('build_status', 'ready')
            ->whereNotNull('build_image')->orderByDesc('number')->first();

        return Version::query()->where('project_id', $project->id)->where('build_status', 'ready')->whereNotNull('build_image')
            ->whereIn('id', array_filter([$running, $latest?->id]))->orderBy('number')->get();
    }

    public function export(ExportContext $context): void
    {
        if (empty($context->options['include_images'])) {
            return;
        }

        $work = [];
        foreach ($context->projects() as $project) {
            // the whole region: an earlier section may have loaded only its id and name onto the project
            $region = $project->knativeRegion()->first();
            if ($project->service_type === 'caas' || ! $region?->kubeconfig || $region->is_demo || $region->registry_mode !== 'internal') {
                continue;
            }
            foreach (self::versionsFor($project) as $version) {
                $work[] = [$project, $version, $region];
            }
        }

        $index = [];
        foreach ($work as $i => [$project, $version, $region]) {
            $context->progress('images', 70, 85, $i, count($work), __('org_transfer.progress.image', ['name' => $project->name, 'version' => $version->number]));
            $entry = PackageFormat::imageFile($project->id, (int) $version->number);
            $handle = null;
            try {
                $handle = $this->transfer->startSender($region, ImageUploadService::namespace($region), 'image-'.$project->slug, null, (string) $version->build_image);
                $status = $this->transfer->waitFor($handle, ['done']);
                $size = (int) (collect((array) ($status['files'] ?? []))->firstWhere('name', 'image.tar')['size'] ?? -1);
                if ($size < 0) {
                    throw new RegionTransferException(__('org_transfer.region.file_missing', ['name' => 'image.tar']));
                }
                $this->transfer->download($handle, 'image.tar', $size, $context->path($entry));
                $index[] = [
                    'project_id' => $project->id,
                    'version' => (int) $version->number,
                    'file' => $entry,
                    'reference' => $version->build_image,
                    'digest' => $status['result']['digest'] ?? null,
                    'platforms' => $status['result']['platforms'] ?? null,
                    'bytes' => $size,
                ];
                $context->count('images');
            } catch (\Throwable $e) {
                @unlink($context->path($entry));
                $context->skip('images', "{$project->name} v{$version->number}", $e->getMessage());
            } finally {
                if ($handle) {
                    $this->transfer->stop($handle);
                }
            }
        }

        if ($index !== []) {
            $context->putJson(self::INDEX, $index);
        }
    }

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        $images = (array) $context->json(self::INDEX, []);
        if ($images === [] || ! $context->choices->images) {
            return;
        }

        foreach ($plan->projects as &$row) {
            $count = count(array_filter($images, fn ($img) => ($img['project_id'] ?? null) === $row['source_id']));
            if ($count === 0 || ($row['status'] ?? null) !== 'create') {
                continue;
            }
            $region = $row['target_region_id'] ? $context->targetRegions()->firstWhere('id', $row['target_region_id']) : null;
            if (! $region || $region->is_demo || $region->registry_mode !== 'internal' || ! $region->registry_address) {
                $plan->issue($row, ImportPlan::WARNING, 'images', __('org_transfer.plan.images_rebuilt', ['count' => $count]));
            }
        }
        unset($row);
    }

    public function import(ImportContext $context): void
    {
        $images = (array) $context->json(self::INDEX, []);
        if ($images === [] || ! $context->choices->images) {
            return;
        }

        foreach ($images as $i => $image) {
            $key = ($image['project_id'] ?? '').':v'.($image['version'] ?? '');
            if ($context->settled('image', $key)) {
                continue;
            }
            $projectId = $context->mapped('project', (string) ($image['project_id'] ?? ''));
            $project = $projectId ? Project::query()->with('knativeRegion')->find($projectId) : null;
            $version = $project ? Version::query()->where('project_id', $project->id)->where('number', (int) ($image['version'] ?? 0))->first() : null;
            $source = $context->file((string) ($image['file'] ?? ''));
            $region = $project?->knativeRegion;
            if (! $project || ! $version || $source === null) {
                $context->record('image', $key, $key, 'skipped', null, __('org_transfer.import.project_not_imported'));

                continue;
            }
            if (! $region?->kubeconfig || $region->is_demo || $region->registry_mode !== 'internal' || ! $region->registry_address) {
                $context->record('image', $key, "{$project->name} v{$version->number}", 'skipped', null, __('org_transfer.plan.images_rebuilt', ['count' => 1]));

                continue;
            }

            $context->progress('images', 85, 92, $i, count($images), __('org_transfer.progress.image', ['name' => $project->name, 'version' => $version->number]));
            $target = app(FunctionBuildService::class)->buildImageRef($region, $project, $version);
            $handle = null;
            try {
                $tool = new TransferTool((string) config('org_transfer.transfer_image'), '', [], [
                    ['name' => 'RUNR_UPLOAD_TARGET', 'value' => $target],
                    ['name' => 'RUNR_REGISTRY_PLAIN_HTTP', 'value' => 'true'],
                ], ['/runr-image-upload', 'push-when-ready', 'image.tar']);
                $handle = $this->transfer->startReceiver($region, ImageUploadService::namespace($region), 'push-'.$project->slug, $tool,
                    ['image.tar' => ['size' => (int) filesize($source), 'sha256' => (string) hash_file('sha256', $source)]]);
                $this->transfer->waitFor($handle, ['receiving', 'ready', 'done'], null, 300);
                $this->transfer->upload($handle, 'image.tar', $source);
                $this->transfer->waitFor($handle, ['done']);
                $version->update(['build_image' => $target, 'build_status' => 'ready', 'build_error' => null]);
                $context->record('image', $key, "{$project->name} v{$version->number}", 'created', $version->id, $target);
            } catch (\Throwable $e) {
                $context->record('image', $key, "{$project->name} v{$version->number}", 'failed', null, $e->getMessage());
            } finally {
                if ($handle) {
                    $this->transfer->stop($handle);
                }
            }
        }
    }
}
