<?php

namespace App\Services\GlobalProjects;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Models\UploadedImage;
use App\Services\Images\ImageName;
use App\Services\Images\ImageUploadService;
use App\Services\Images\UploadedImages;
use App\Services\OrgTransfer\Region\RegionTransfer;
use App\Services\OrgTransfer\Region\RegionTransferException;
use App\Services\OrgTransfer\Region\TransferTool;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * An uploaded CaaS image of a global project in every region (docs/global-projects.md, "Uploaded
 * images"): an upload lives in one region's internal registry, which no other region pulls from.
 * Before a region runs the project, the image is copied there — pulled from the primary's
 * registry as an OCI layout by a transfer pod, handed through the control plane, pushed into the
 * region's registry under the same repository and tag by another (the region transfer of org
 * export / import) — and recorded as the organization's uploaded image in that region, pinned by
 * the same digest. A region that already holds that digest of the repository copies nothing.
 */
class GlobalImageCopy
{
    public function __construct(
        private readonly UploadedImages $images,
        private readonly RegionTransfer $transfer,
    ) {}

    /**
     * The upload a CaaS project runs from its primary's registry, null for any other image.
     */
    public function sourceOf(Project $project): ?UploadedImage
    {
        if ($project->service_type !== 'caas') {
            return null;
        }
        $image = (string) ($project->containerConfig->image ?? '');
        if ($image === '' || ! $project->knativeRegion) {
            return null;
        }

        return $this->images->forImageRef($project->knativeRegion, $image)['image'] ?? null;
    }

    /** Whether the region needs a copy of the project's uploaded image before it runs it. */
    public function needed(Project $project, KnativeRegion $region): bool
    {
        return (string) $region->getKey() !== (string) $project->knative_region_id && $this->sourceOf($project) !== null;
    }

    /**
     * The image in $region (copied when it is not there yet). Returns the reference the region's
     * copy runs: the region registry's repository pinned by digest.
     *
     * @throws RuntimeException
     */
    public function copy(Project $project, KnativeRegion $region): string
    {
        $source = $this->sourceOf($project) ?? throw new RuntimeException(__('api.global.image_not_uploaded'));
        $primary = $project->knativeRegion;

        if ($region->registry_mode !== 'internal' || ! $region->registry_address) {
            throw new RuntimeException(__('api.global.region_no_registry', ['region' => $region->name]));
        }

        $existing = UploadedImage::query()
            ->where('owner_key', $source->owner_key)
            ->where('knative_region_id', $region->getKey())
            ->where('repository', $source->repository)
            ->where('digest', $source->digest)
            ->first();
        if ($existing && $existing->inRegistryOf($region)) {
            return $existing->imageRef($region);
        }

        $target = rtrim((string) $region->registry_address, '/').'/'.ImageName::registryRepository($source->owner_key, $source->repository).':'.$source->tag;
        $file = tempnam(sys_get_temp_dir(), 'runr-global-image-');
        $sender = null;
        $receiver = null;

        try {
            $sender = $this->transfer->startSender($primary, ImageUploadService::namespace($primary), 'gimg-'.$project->slug, null, $source->imageRef($primary));
            $status = $this->transfer->waitFor($sender, ['done']);
            $size = (int) (collect((array) ($status['files'] ?? []))->firstWhere('name', 'image.tar')['size'] ?? -1);
            if ($size < 0) {
                throw new RegionTransferException(__('org_transfer.region.file_missing', ['name' => 'image.tar']));
            }
            $this->transfer->download($sender, 'image.tar', $size, $file);
            $this->transfer->stop($sender);
            $sender = null;

            $tool = new TransferTool((string) config('org_transfer.transfer_image'), '', [], [
                ['name' => 'RUNR_UPLOAD_TARGET', 'value' => $target],
                ['name' => 'RUNR_REGISTRY_PLAIN_HTTP', 'value' => 'true'],
            ], ['/runr-image-upload', 'push-when-ready', 'image.tar']);
            $receiver = $this->transfer->startReceiver($region, ImageUploadService::namespace($region), 'gpush-'.$project->slug, $tool,
                ['image.tar' => ['size' => (int) filesize($file), 'sha256' => (string) hash_file('sha256', $file)]]);
            $this->transfer->waitFor($receiver, ['receiving', 'ready', 'done'], null, 300);
            $this->transfer->upload($receiver, 'image.tar', $file);
            $result = $this->transfer->waitFor($receiver, ['done']);
            $digest = (string) ($result['result']['digest'] ?? $source->digest);
        } finally {
            foreach ([$sender, $receiver] as $handle) {
                if ($handle) {
                    $this->transfer->stop($handle);
                }
            }
            @unlink($file);
        }

        $copy = UploadedImage::query()->firstOrNew([
            'owner_key' => $source->owner_key,
            'knative_region_id' => $region->getKey(),
            'repository' => $source->repository,
            'tag' => $source->tag,
        ]);
        $copy->fill([
            'tenant_id' => $source->tenant_id,
            'registry_repository' => $source->registry_repository,
            'digest' => preg_match(ImageName::DIGEST, $digest) ? $digest : $source->digest,
            'media_type' => $source->media_type,
            'config_media_type' => $source->config_media_type,
            'kind' => $source->kind,
            'source_format' => $source->source_format,
            'size_bytes' => $source->size_bytes,
            'archive_bytes' => $source->archive_bytes,
            'archive_sha256' => $source->archive_sha256,
            'platforms' => $source->platforms,
            'layers' => $source->layers,
            'source_filename' => $source->source_filename,
            'uploaded_by' => $source->uploaded_by,
            'note' => mb_substr(__('api.global.image_copy_note', ['project' => $project->name, 'region' => $primary?->name]), 0, 1000),
            'registry_address' => $region->registry_address,
        ])->save();

        Log::info('Uploaded image copied for a global project', ['project' => $project->slug, 'region' => $region->name, 'image' => $copy->name()]);

        return $copy->imageRef($region);
    }

    /**
     * A deploy's manifests with the region's copy in place of the primary's image (every
     * container that runs it).
     *
     * @param  list<array>  $manifests
     * @return list<array>
     */
    public static function withImage(array $manifests, Project $project, string $regionRef): array
    {
        $original = (string) ($project->containerConfig->image ?? '');
        if ($original === '' || $regionRef === '' || $original === $regionRef) {
            return $manifests;
        }

        $swap = function (array $containers) use ($original, $regionRef): array {
            foreach ($containers as $i => $container) {
                if (($container['image'] ?? null) === $original) {
                    $containers[$i]['image'] = $regionRef;
                }
            }

            return $containers;
        };

        foreach ($manifests as $i => $manifest) {
            if (isset($manifest['spec']['template']['spec']['containers'])) {
                $manifests[$i]['spec']['template']['spec']['containers'] = $swap($manifest['spec']['template']['spec']['containers']);
            }
            if (isset($manifest['spec']['jobTemplate']['spec']['template']['spec']['containers'])) {
                $manifests[$i]['spec']['jobTemplate']['spec']['template']['spec']['containers'] = $swap($manifest['spec']['jobTemplate']['spec']['template']['spec']['containers']);
            }
        }

        return $manifests;
    }

    /** The region's copy recorded on its row (the step of GlobalReplicaDeployer that waits for it). */
    public function copyInto(Project $project, ProjectRegion $row): void
    {
        $region = $row->knativeRegion ?? throw new RuntimeException(__('api.global.region_unavailable'));
        $row->update(['build_image' => $this->copy($project, $region)]);
    }
}
