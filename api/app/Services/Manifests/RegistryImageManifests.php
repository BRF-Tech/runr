<?php

namespace App\Services\Manifests;

/**
 * Pure logic for the internal (Zot) registry's per-project build image retention.
 * Kept free of HTTP/K8s calls so it can be unit-tested directly — mirrors ZotManifests.
 */
class RegistryImageManifests
{
    private const REPO_PREFIX = 'runr-builds';

    public const DEFAULT_RETAIN = 10;

    /**
     * Manifest media types accepted when resolving a tag's digest (single-arch image and
     * multi-arch index, OCI and Docker schema 2). Zot answers with the type the tag was
     * pushed as and only deletes by that digest.
     */
    public const MANIFEST_ACCEPT = 'application/vnd.oci.image.manifest.v1+json, '
        .'application/vnd.docker.distribution.manifest.v2+json, '
        .'application/vnd.oci.image.index.v1+json, '
        .'application/vnd.docker.distribution.manifest.list.v2+json';

    /**
     * The Zot repository a project's builds are pushed under: runr-builds/{slug}.
     */
    public static function repositoryName(string $slug): string
    {
        return self::REPO_PREFIX.'/'.$slug;
    }

    /**
     * Split a build image reference ({registry}/runr-builds/{slug}:v{n}, as stored in
     * versions.build_image) into its repository and tag. The repository comes from the stored
     * reference, not from the project's current slug: a rename changes the slug, but images
     * already pushed stay under the old repository. Anything else (another repository, a
     * non-build tag, a digest reference) is not a RunR build image → null.
     *
     * @return array{repo: string, tag: string}|null
     */
    public static function parseImageRef(?string $imageRef): ?array
    {
        if (! $imageRef) {
            return null;
        }

        if (preg_match('#(?:^|/)('.self::REPO_PREFIX.'/[A-Za-z0-9._-]+):(v\d+)$#', $imageRef, $m) !== 1) {
            return null;
        }

        return ['repo' => $m[1], 'tag' => $m[2]];
    }

    /**
     * registry.retain_versions, never below 1: the newest ready build must always survive.
     */
    public static function clampRetain(mixed $keep): int
    {
        if ($keep === null || $keep === '') {
            return self::DEFAULT_RETAIN;
        }

        return max(1, (int) $keep);
    }

    /**
     * Versions whose build image may be deleted.
     *
     * Kept: the newest $keep ready builds, every version in $protectedIds (referenced by a
     * deployment that is not finished, or by the project's live Knative Service / CronJob /
     * queue consumer) and builds still in progress. Candidates: older ready builds and failed
     * builds that left an image reference behind.
     *
     * @param  iterable<array{id: string, number: int|string, build_status: ?string, build_image: ?string}>  $versions
     * @param  list<string>  $protectedIds
     * @return list<string> version ids
     */
    public static function versionsToPrune(iterable $versions, int $keep, array $protectedIds): array
    {
        $keep = self::clampRetain($keep);

        $list = [];
        foreach ($versions as $v) {
            if (self::parseImageRef($v['build_image'] ?? null) !== null) {
                $list[] = $v;
            }
        }

        usort($list, fn (array $a, array $b) => (int) $b['number'] <=> (int) $a['number']);

        $kept = 0;
        $prune = [];

        foreach ($list as $v) {
            $status = $v['build_status'] ?? null;

            if ($status === 'ready' && $kept < $keep) {
                $kept++;

                continue;
            }

            if (in_array($v['id'], $protectedIds, true)) {
                continue;
            }

            if (in_array($status, ['ready', 'failed'], true)) {
                $prune[] = $v['id'];
            }
        }

        return $prune;
    }
}
