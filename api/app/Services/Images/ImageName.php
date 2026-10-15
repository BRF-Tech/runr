<?php

namespace App\Services\Images;

/**
 * Names of uploaded images (docs/images.md): `repository[:tag]` as users write them — repository
 * in the OCI distribution grammar (lowercase path components, up to three), tag up to 128
 * characters, `latest` when omitted. A reference to an uploaded image is one of
 *
 *   repository:tag            the image of that name (in the region asked for)
 *   repository@sha256:<hex>   the image with that digest
 *   <ULID>                    the uploaded image's id
 */
final class ImageName
{
    public const REPOSITORY = '/^[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*(?:\/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*){0,2}$/';

    public const TAG = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,127}$/';

    public const DIGEST = '/^sha256:[a-f0-9]{64}$/';

    public const ULID = '/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/';

    public const DEFAULT_TAG = 'latest';

    public static function validRepository(string $repository): bool
    {
        return strlen($repository) <= 128 && preg_match(self::REPOSITORY, $repository) === 1;
    }

    public static function validTag(string $tag): bool
    {
        return preg_match(self::TAG, $tag) === 1;
    }

    /**
     * "repository[:tag]" → [repository, tag]; null when it is not a valid name.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parse(string $name): ?array
    {
        $name = trim($name);
        $slash = strrpos($name, '/');
        $colon = strrpos($name, ':');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            [$repository, $tag] = [substr($name, 0, $colon), substr($name, $colon + 1)];
        } else {
            [$repository, $tag] = [$name, self::DEFAULT_TAG];
        }

        return self::validRepository($repository) && self::validTag($tag) ? [$repository, $tag] : null;
    }

    /**
     * A reference to an uploaded image.
     *
     * @return array{type: 'id', id: string}|array{type: 'digest', repository: string, digest: string}|array{type: 'tag', repository: string, tag: string}|null
     */
    public static function reference(string $reference): ?array
    {
        $reference = trim($reference);
        if (preg_match(self::ULID, $reference)) {
            return ['type' => 'id', 'id' => strtoupper($reference)];
        }
        if (str_contains($reference, '@')) {
            [$repository, $digest] = explode('@', $reference, 2);

            return self::validRepository($repository) && preg_match(self::DIGEST, $digest)
                ? ['type' => 'digest', 'repository' => $repository, 'digest' => $digest]
                : null;
        }
        $parsed = self::parse($reference);

        return $parsed ? ['type' => 'tag', 'repository' => $parsed[0], 'tag' => $parsed[1]] : null;
    }

    /**
     * The name an upload gets when none is given: the file name without its archive extensions
     * (myapp.tar.gz → myapp:latest), lowercased, other characters replaced by '-'.
     *
     * @return array{0: string, 1: string}
     */
    public static function fromFilename(string $filename): array
    {
        $base = strtolower(basename(str_replace('\\', '/', $filename)));
        $base = preg_replace('/(\.tar\.gz|\.tgz|\.tar|\.oci|\.wasm|\.gz)+$/', '', $base) ?? $base;
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', $base) ?? '', '-');

        return [$base !== '' ? substr($base, 0, 128) : 'image', self::DEFAULT_TAG];
    }

    /** Repository path in the region registry: <prefix>/<owner>/<repository>. */
    public static function registryRepository(string $ownerKey, string $repository): string
    {
        return trim((string) config('images.repository_prefix', 'runr-uploads'), '/').'/'.strtolower($ownerKey).'/'.$repository;
    }
}
