<?php

namespace Tests\Unit;

use App\Services\Manifests\RegistryImageManifests;
use PHPUnit\Framework\TestCase;

/**
 * Pure image-reference parsing and version selection for the internal registry's
 * build image retention — no HTTP, mirrors ZotManifestsTest.
 */
class RegistryImageManifestsTest extends TestCase
{
    public function test_repository_name_is_prefixed_with_runr_builds(): void
    {
        $this->assertSame('runr-builds/my-project', RegistryImageManifests::repositoryName('my-project'));
    }

    public function test_parse_image_ref_takes_repository_and_tag_from_the_stored_reference(): void
    {
        $this->assertSame(
            ['repo' => 'runr-builds/old-name', 'tag' => 'v12'],
            RegistryImageManifests::parseImageRef('10.43.0.5:5000/runr-builds/old-name:v12'),
        );
        $this->assertSame(
            ['repo' => 'runr-builds/api-acme', 'tag' => 'v3'],
            RegistryImageManifests::parseImageRef('registry.example.com/runr-builds/api-acme:v3'),
        );
    }

    public function test_parse_image_ref_ignores_anything_that_is_not_a_build_image(): void
    {
        $this->assertNull(RegistryImageManifests::parseImageRef(null));
        $this->assertNull(RegistryImageManifests::parseImageRef('nginx:1.27-alpine'));
        $this->assertNull(RegistryImageManifests::parseImageRef('10.43.0.5:5000/runr-builds/app:latest'));
        $this->assertNull(RegistryImageManifests::parseImageRef('10.43.0.5:5000/other/app:v1'));
        $this->assertNull(RegistryImageManifests::parseImageRef('10.43.0.5:5000/runr-builds/app@sha256:abc'));
    }

    public function test_retention_is_clamped_to_at_least_one(): void
    {
        $this->assertSame(1, RegistryImageManifests::clampRetain(0));
        $this->assertSame(1, RegistryImageManifests::clampRetain(-5));
        $this->assertSame(3, RegistryImageManifests::clampRetain('3'));
        $this->assertSame(RegistryImageManifests::DEFAULT_RETAIN, RegistryImageManifests::clampRetain(null));
    }

    private function v(string $id, int $number, string $status = 'ready', ?string $image = 'default'): array
    {
        return [
            'id' => $id,
            'number' => $number,
            'build_status' => $status,
            'build_image' => $image === 'default' ? "10.43.0.5:5000/runr-builds/app:v{$number}" : $image,
        ];
    }

    public function test_versions_to_prune_keeps_the_newest_ready_builds_numerically(): void
    {
        $versions = [$this->v('a', 2), $this->v('b', 10), $this->v('c', 1), $this->v('d', 9)];

        // v10 and v9 stay (numeric, not lexical order)
        $this->assertEqualsCanonicalizing(['a', 'c'], RegistryImageManifests::versionsToPrune($versions, 2, []));
    }

    public function test_versions_to_prune_never_touches_protected_or_in_progress_versions(): void
    {
        $versions = [
            $this->v('v5', 5),
            $this->v('v4', 4, 'building'),
            $this->v('v3', 3),
            $this->v('v2', 2),
            $this->v('v1', 1, 'failed'),
        ];

        // keep 1 (v5); v3 is protected (active deployment / live workload); v4 still building
        $this->assertEqualsCanonicalizing(['v2', 'v1'], RegistryImageManifests::versionsToPrune($versions, 1, ['v3']));
    }

    public function test_versions_to_prune_counts_only_ready_builds_toward_the_retention(): void
    {
        $versions = [$this->v('v3', 3, 'failed'), $this->v('v2', 2), $this->v('v1', 1)];

        // A failed newest build does not use up the one kept slot
        $this->assertEqualsCanonicalizing(['v3', 'v1'], RegistryImageManifests::versionsToPrune($versions, 1, []));
    }

    public function test_versions_to_prune_skips_pruned_and_non_build_images_and_clamps_keep(): void
    {
        $versions = [
            $this->v('v3', 3),
            $this->v('v2', 2, 'pruned', null),
            $this->v('v1', 1, 'ready', 'nginx:1.27-alpine'),
        ];

        $this->assertSame([], RegistryImageManifests::versionsToPrune($versions, 0, []));
    }
}
