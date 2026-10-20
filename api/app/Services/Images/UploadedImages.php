<?php

namespace App\Services\Images;

use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Runtime;
use App\Models\UploadedImage;
use App\Services\RegistryImageLifecycleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * The uploaded images of an owner (docs/images.md): lookup, resolution of the references other
 * features use (CaaS image, runtime version `upload:`, wasm-exec `wasm.upload`), what still uses
 * an image, and deletion — the record and, when nothing else points at its digest, the manifest
 * in the region registry (RegistryImageLifecycleService).
 */
class UploadedImages
{
    public function __construct(private RegistryImageLifecycleService $registry) {}

    /** The current owner's images (optionally of one region / kind). */
    public function visible(?string $regionId = null, ?string $kind = null): Builder
    {
        return UploadedImage::query()
            ->where('owner_key', ImageOwner::currentKey())
            ->when($regionId, fn ($q) => $q->where('knative_region_id', $regionId))
            ->when($kind, fn ($q) => $q->where('kind', $kind));
    }

    /**
     * The image a reference names for an owner in a region: an id (its repository:tag is looked up
     * in $regionId when the id belongs to another region), repository:tag or repository@digest.
     * An image that is not in the registry the region uses now (the region moved to another
     * cluster since the upload, UploadedImage::inRegistryOf()) is not uploaded there: null.
     */
    public function resolve(string $reference, string $ownerKey, string $regionId): ?UploadedImage
    {
        $image = $this->lookup($reference, $ownerKey, $regionId);

        return $image && ! $image->inRegistryOf() ? null : $image;
    }

    private function lookup(string $reference, string $ownerKey, string $regionId): ?UploadedImage
    {
        $ref = ImageName::reference($reference);
        if ($ref === null) {
            return null;
        }

        $query = UploadedImage::query()->where('owner_key', $ownerKey)->where('knative_region_id', $regionId);

        switch ($ref['type']) {
            case 'id':
                $image = UploadedImage::query()->where('owner_key', $ownerKey)->whereKey($ref['id'])->first();
                if (! $image || $image->knative_region_id === $regionId) {
                    return $image;
                }

                return $query->where('repository', $image->repository)->where('tag', $image->tag)->first();
            case 'digest':
                return $query->where('repository', $ref['repository'])->where('digest', $ref['digest'])->first();
            default:
                return $query->where('repository', $ref['repository'])->where('tag', $ref['tag'])->first();
        }
    }

    /**
     * Regions (of $regionIds) the reference is not uploaded to.
     *
     * @param  list<string>  $regionIds
     * @return list<string> region names
     */
    public function missingRegions(string $reference, string $ownerKey, array $regionIds): array
    {
        $missing = [];
        foreach ($regionIds as $id) {
            if (! $this->resolve($reference, $ownerKey, $id)) {
                $missing[] = KnativeRegion::find($id)?->name ?? $id;
            }
        }

        return $missing;
    }

    /**
     * The uploaded image an image reference of a CaaS project points at: a reference into the
     * uploads repositories of the region's registry (image null: no such upload, or one that is not
     * in that registry — the region moved to another cluster since). Null for any other image.
     * $registry: look into the uploads repositories of that registry address instead — e.g. the one
     * a region migration left (the image is then the record, wherever it is now).
     *
     * @return array{image: ?UploadedImage, owner: string}|null
     */
    public function forImageRef(?KnativeRegion $region, string $imageRef, ?string $registry = null): ?array
    {
        $current = $registry === null;
        $registry = rtrim((string) ($registry ?? $region?->registry_address), '/');
        $prefix = trim((string) config('images.repository_prefix', 'runr-uploads'), '/').'/';
        if ($registry === '' || ! str_starts_with($imageRef, "{$registry}/{$prefix}")) {
            return null;
        }

        $path = substr($imageRef, strlen("{$registry}/{$prefix}"));
        if (! preg_match('#^([a-z0-9-]+)/(.+?)(?:@(sha256:[a-f0-9]{64})|:([A-Za-z0-9_][A-Za-z0-9._-]{0,127}))$#', $path, $m)) {
            return ['image' => null, 'owner' => ''];
        }

        $query = UploadedImage::query()->where('knative_region_id', $region?->id)->where('owner_key', $m[1])->where('repository', $m[2]);
        $image = ! empty($m[3]) ? $query->where('digest', $m[3])->first() : $query->where('tag', $m[4] ?? '')->first();
        if ($current && $image && ! $image->inRegistryOf($region)) {
            $image = null;
        }

        return ['image' => $image, 'owner' => $m[1]];
    }

    /**
     * What a reference into the uploads repositories of the registry at $registry names: the
     * uploaded image's repository:tag, or the reference's own path without a record. Null for a
     * reference that is not into those repositories.
     */
    public function nameOfRef(?KnativeRegion $region, string $imageRef, string $registry): ?string
    {
        $found = $this->forImageRef($region, $imageRef, $registry);
        if ($found === null) {
            return null;
        }

        return $found['image']?->name()
            ?? substr($imageRef, strlen(rtrim($registry, '/').'/'.trim((string) config('images.repository_prefix', 'runr-uploads'), '/').'/'));
    }

    /**
     * Why a CaaS project in $region may not run $imageRef: a reference into the uploads
     * repositories of the region registry must name an uploaded image of the current owner in
     * that region (an organization never runs another one's uploads) that is in the region's
     * registry (not one the region left with a move to another cluster). Null when it may — also
     * for every image outside the uploads repositories.
     */
    public function caasImageProblem(?KnativeRegion $region, ?string $imageRef): ?string
    {
        if (! $region || ! is_string($imageRef) || $imageRef === '') {
            return null;
        }

        $found = $this->forImageRef($region, $imageRef);
        if ($found === null) {
            return null;
        }

        $own = $found['owner'] === strtolower(ImageOwner::currentKey());
        if ($found['image'] !== null && $own) {
            return null;
        }

        // The upload exists, but in the registry of a cluster the region left
        $record = $own ? $this->forImageRef($region, $imageRef, (string) $region->registry_address)['image'] ?? null : null;

        return $record !== null
            ? __('images.caas_not_in_registry', ['name' => $record->name(), 'region' => $region->name])
            : __('images.caas_not_found', ['image' => $imageRef]);
    }

    /**
     * What still uses an image: CaaS projects of the current context whose image is its digest or
     * tag reference, and runtimes of the owner whose versions name it (`upload:`).
     *
     * @return array{projects: list<string>, runtimes: list<string>}
     */
    public function usages(UploadedImage $image): array
    {
        $region = KnativeRegion::find($image->knative_region_id);
        $projects = [];
        try {
            $projects = ContainerConfig::query()
                ->whereIn('image', [$image->imageRef($region), $image->tagRef($region)])
                ->with('project:id,name')
                ->get()
                ->map(fn (ContainerConfig $c) => (string) ($c->project?->name ?? $c->project_id))
                ->unique()->values()->all();
        } catch (\Throwable $e) {
            Log::warning('Uploaded image usage check failed', ['image' => $image->id, 'error' => $e->getMessage()]);
        }

        $names = [$image->id, $image->name(), "{$image->repository}@{$image->digest}"];
        if ($image->tag === ImageName::DEFAULT_TAG) {
            $names[] = $image->repository;
        }

        $runtimes = Runtime::query()
            ->whereIn('source', [Runtime::SOURCE_PLATFORM, Runtime::SOURCE_CUSTOM])
            ->get()
            ->filter(fn (Runtime $r) => ImageOwner::keyFor($r->source === Runtime::SOURCE_CUSTOM ? $r->tenant_id : null) === $image->owner_key)
            ->filter(fn (Runtime $r) => collect($r->manifest['versions'] ?? [])->contains(fn ($v) => in_array($v['upload'] ?? null, $names, true)))
            ->map(fn (Runtime $r) => $r->id)
            ->values()->all();

        return ['projects' => $projects, 'runtimes' => $runtimes];
    }

    /**
     * Delete an image: the record, and the manifest in the region registry unless another record
     * points at the same digest. False when the registry could not confirm the deletion (the
     * record is gone either way; the manifest is orphaned and logged).
     */
    public function delete(UploadedImage $image): bool
    {
        $region = KnativeRegion::find($image->knative_region_id);
        $inRegistry = $image->inRegistryOf($region);
        $image->delete();

        // An image of a registry the region left (a move to another cluster) has nothing in the region's registry
        return ! $inRegistry || $this->removeManifest($region, $image->registry_repository, $image->digest);
    }

    /** Remove a manifest from the region registry when no uploaded image record still has it. */
    public function removeManifest(?KnativeRegion $region, string $repository, string $digest): bool
    {
        $shared = UploadedImage::query()
            ->where('knative_region_id', $region?->id)
            ->where('registry_repository', $repository)
            ->where('digest', $digest)
            ->exists();
        if ($shared || ! $region || $region->is_demo) {
            return true;
        }

        $ok = $this->registry->deleteManifest($region, $repository, $digest);
        if (! $ok) {
            Log::warning('Uploaded image manifest not removed from the registry', ['region' => $region->name, 'repository' => $repository, 'digest' => $digest]);
        }

        return $ok;
    }
}
