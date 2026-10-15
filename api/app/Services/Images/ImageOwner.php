<?php

namespace App\Services\Images;

use App\Models\UploadedImage;
use App\Support\TenantAccess;

/**
 * Who owns an uploaded image: the organization of the current tenant context (multi-tenant), or
 * "platform" outside one — single-tenant installs, and platform administrators in the central
 * console, whose uploads are what platform runtimes may reference. Users see and use the uploads
 * of their own owner only.
 */
final class ImageOwner
{
    public static function currentTenantId(): ?string
    {
        return TenantAccess::currentId();
    }

    public static function currentKey(): string
    {
        return self::keyFor(self::currentTenantId());
    }

    public static function keyFor(?string $tenantId): string
    {
        return $tenantId !== null && $tenantId !== '' ? (string) $tenantId : UploadedImage::PLATFORM_OWNER;
    }
}
