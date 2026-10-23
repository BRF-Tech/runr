<?php

namespace App\Services\OrgTransfer\Package;

use Illuminate\Support\Str;

/**
 * The organization package (docs/org-export.md#package-format): a tar compressed with zstd
 * whose first entry is manifest.json — format, schema version, the RunR version that wrote it,
 * the source organization, the sections it holds and, for every other entry, its size and
 * SHA-256. The importer reads packages of its own schema version and older ones and refuses newer
 * ones.
 *
 * Schema history:
 *  1  RunR 0.5.11 — organization (settings, roles, groups, members), bindings (+ data), projects
 *     (configs, versions with code, secrets, domains, workflows, observability), gateways, alert
 *     channels / rules, status page, images
 */
final class PackageFormat
{
    public const FORMAT = 'runr-org-export';

    public const SCHEMA_VERSION = 1;

    /** Oldest schema version this importer still reads. */
    public const MIN_SCHEMA_VERSION = 1;

    public const MANIFEST = 'manifest.json';

    public const EXTENSION = '.tar.zst';

    // Package layout (paths inside the tar)
    public const ORGANIZATION = 'organization/organization.json';

    public const OBSERVABILITY = 'organization/observability.json';

    public const GATEWAYS = 'organization/gateways.json';

    public const BINDINGS = 'bindings/bindings.json';

    public const SECRETS = 'secrets/secrets.enc';

    public static function projectFile(string $projectId): string
    {
        return 'projects/'.self::segment($projectId).'/project.json';
    }

    public static function codeFile(string $projectId, int $version): string
    {
        return 'projects/'.self::segment($projectId).'/code/v'.$version.'.tar';
    }

    public static function imageFile(string $projectId, int $version): string
    {
        return 'projects/'.self::segment($projectId).'/images/v'.$version.'.oci.tar';
    }

    public static function bindingDataFile(string $bindingId, string $extension): string
    {
        return 'bindings/data/'.self::segment($bindingId).'.'.$extension;
    }

    /** Directory of a section other features add (SectionRegistry::extend()). */
    public static function extensionDir(string $section): string
    {
        return 'extensions/'.self::segment($section);
    }

    /** The oldest schema version this importer reads (raised when support for one ends). */
    public static function oldestReadable(): int
    {
        return self::MIN_SCHEMA_VERSION;
    }

    /** runr-org-<slug>-<YYYYmmdd-HHMMSS>.tar.zst */
    public static function filename(?string $slug, \DateTimeInterface $at, bool $compressed = true): string
    {
        $slug = Str::slug((string) $slug) ?: 'organization';

        return 'runr-org-'.Str::limit($slug, 40, '').'-'.$at->format('Ymd-His').($compressed ? self::EXTENSION : '.tar');
    }

    /**
     * Refuses a manifest this importer cannot read: another format, a newer schema version, one
     * older than the oldest supported.
     *
     * @throws PackageException
     */
    public static function assertReadable(mixed $manifest): void
    {
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            throw new PackageException(__('org_transfer.package.not_a_package'), 'not_a_package');
        }

        $version = $manifest['schema_version'] ?? null;
        if (! is_int($version) || $version < 1) {
            throw new PackageException(__('org_transfer.package.no_schema_version'), 'not_a_package');
        }
        if ($version > self::SCHEMA_VERSION) {
            throw new PackageException(__('org_transfer.package.schema_too_new', [
                'version' => $version,
                'supported' => self::SCHEMA_VERSION,
                'runr' => (string) ($manifest['runr_version'] ?? '?'),
            ]), 'schema_too_new');
        }
        if ($version < self::oldestReadable()) {
            throw new PackageException(__('org_transfer.package.schema_too_old', ['version' => $version, 'min' => self::oldestReadable()]), 'schema_too_old');
        }
        if (! is_array($manifest['entries'] ?? null)) {
            throw new PackageException(__('org_transfer.package.not_a_package'), 'not_a_package');
        }
    }

    /**
     * A relative path inside the package: no absolute paths, no "..", no empty or hidden parts,
     * a conservative character set — the importer extracts only such paths.
     */
    public static function isSafePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 255 || str_starts_with($path, '/') || str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.') || ! preg_match('/^[A-Za-z0-9._-]+$/', $part)) {
                return false;
            }
        }

        return true;
    }

    /** A path segment from an id: what ULIDs and slugs consist of. */
    public static function segment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9_-]/', '-', $value) ?? '';

        return $segment !== '' ? $segment : 'x';
    }
}
