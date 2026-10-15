<?php

namespace App\Services\Images;

use App\Models\ImageUpload;
use App\Models\UploadedImage;

/**
 * Storage limit of uploaded images per owner (an organization, or the install when single-tenant),
 * counted over every region (docs/images.md "Quota"): the size of every uploaded image plus the
 * declared size of uploads still running. config images.quota_gb, 0 = unlimited.
 *
 * Registry space comes back when images are deleted — RegistryImageLifecycleService removes their
 * manifests from the region registry, as the build retention does for build images.
 */
class ImageQuota
{
    public const GIB = 1024 ** 3;

    public function limitBytes(): int
    {
        return (int) config('images.quota_gb', 50) * self::GIB;
    }

    /** Bytes the owner's images and open uploads claim; $exceptUpload: the upload being checked. */
    public function usedBytes(string $ownerKey, ?string $exceptUpload = null): int
    {
        $stored = (int) UploadedImage::query()->where('owner_key', $ownerKey)->sum('size_bytes');
        $open = (int) ImageUpload::query()
            ->where('owner_key', $ownerKey)
            ->whereIn('status', ImageUpload::OPEN)
            ->when($exceptUpload, fn ($q) => $q->whereKeyNot($exceptUpload))
            ->sum('size_bytes');

        return $stored + $open;
    }

    /**
     * Does an upload of $bytes fit? $replacing: the image of the same name and region the upload
     * would replace (its size comes back).
     *
     * @return array{limit: int, used: int, requested: int}|null the exceeded limit, null when it fits
     */
    public function exceeded(string $ownerKey, int $bytes, ?UploadedImage $replacing = null): ?array
    {
        $limit = $this->limitBytes();
        if ($limit <= 0) {
            return null;
        }

        $used = $this->usedBytes($ownerKey) - (int) ($replacing?->size_bytes ?? 0);

        return $used + $bytes > $limit ? ['limit' => $limit, 'used' => max(0, $used), 'requested' => $bytes] : null;
    }

    /** @return array{limit_bytes: int, used_bytes: int, images: int} */
    public function summary(string $ownerKey): array
    {
        return [
            'limit_bytes' => $this->limitBytes(),
            'used_bytes' => $this->usedBytes($ownerKey),
            'images' => UploadedImage::query()->where('owner_key', $ownerKey)->count(),
        ];
    }
}
