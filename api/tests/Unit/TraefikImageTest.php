<?php

namespace Tests\Unit;

use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\TraefikImage;
use PHPUnit\Framework\TestCase;

/**
 * The region Traefik runs the image of its pinned chart (the chart's appVersion), from the reqs
 * mirror of docker.io/traefik. The nodes pre-pull that image and the Kind e2e loads it into its
 * nodes — both must name what the chart runs, or the pre-pull fetches an image nothing uses and
 * every Traefik replica pulls its own; and the mirror list must copy it.
 */
class TraefikImageTest extends TestCase
{
    public function test_the_image_belongs_to_the_pinned_chart(): void
    {
        // A chart bump changes the image: update TRAEFIK_IMAGE with it (appVersion of the chart)
        // and its REQS_IMAGES entry (runtime/lib/mirror.sh)
        $this->assertSame(['40.3.0', 'ghcr.io/brf-tech/runr/reqs/traefik:v3.7.4'], [KnativeConstants::TRAEFIK_CHART_VERSION, KnativeConstants::TRAEFIK_IMAGE]);
    }

    public function test_the_mirror_copies_it_from_docker_hub(): void
    {
        $mirror = dirname(__DIR__, 3).'/runtime/lib/mirror.sh';
        if (! is_file($mirror)) {
            $this->markTestSkipped('runtime/lib/mirror.sh is not part of this checkout');
        }

        // target|source: ghcr.io/brf-tech/runr/reqs/traefik:<appVersion> from docker.io/traefik:<appVersion>
        $tag = TraefikImage::version(KnativeConstants::TRAEFIK_IMAGE);
        $this->assertStringContainsString("\"traefik:{$tag}|traefik:{$tag}\"", (string) file_get_contents($mirror));
    }

    public function test_the_nodes_pre_pull_it(): void
    {
        $config = require dirname(__DIR__, 2).'/config/knative.php';

        $this->assertSame(KnativeConstants::TRAEFIK_IMAGE, $config['traefik_image']);
        $this->assertContains(KnativeConstants::TRAEFIK_IMAGE, $config['prepull_images']);
        $this->assertEmpty(preg_grep('#/traefik:#', array_diff($config['prepull_images'], [KnativeConstants::TRAEFIK_IMAGE])), 'another Traefik image is pre-pulled');
    }

    public function test_knative_traefik_image_overrides_it_everywhere(): void
    {
        $image = 'registry.example.com/mirror/traefik:v3.7.4';
        putenv("KNATIVE_TRAEFIK_IMAGE={$image}");
        $_ENV['KNATIVE_TRAEFIK_IMAGE'] = $_SERVER['KNATIVE_TRAEFIK_IMAGE'] = $image;

        try {
            $config = require dirname(__DIR__, 2).'/config/knative.php';
        } finally {
            putenv('KNATIVE_TRAEFIK_IMAGE');
            unset($_ENV['KNATIVE_TRAEFIK_IMAGE'], $_SERVER['KNATIVE_TRAEFIK_IMAGE']);
        }

        $this->assertSame($image, $config['traefik_image']);
        $this->assertContains($image, $config['prepull_images']);
        $this->assertNotContains(KnativeConstants::TRAEFIK_IMAGE, $config['prepull_images']);
    }

    public function test_the_kind_e2e_loads_it(): void
    {
        $harness = dirname(__DIR__, 3).'/runtime/lib/test.sh';
        if (! is_file($harness)) {
            $this->markTestSkipped('runtime/lib/test.sh is not part of this checkout');
        }

        $this->assertStringContainsString('"'.KnativeConstants::TRAEFIK_IMAGE.'"', (string) file_get_contents($harness));
        // test-helm-upgrade loads it and expects region:reconcile to move the region Traefik to it
        $this->assertStringContainsString('"'.KnativeConstants::TRAEFIK_IMAGE.'"', (string) file_get_contents(dirname($harness).'/test-helm-upgrade.sh'));
    }

    public function test_chart_image_values(): void
    {
        $this->assertSame(['registry' => 'ghcr.io', 'repository' => 'brf-tech/runr/reqs/traefik', 'tag' => 'v3.7.4'], TraefikImage::helmValues('ghcr.io/brf-tech/runr/reqs/traefik:v3.7.4'));
        // the chart's default: Docker Hub
        $this->assertSame(['registry' => 'docker.io', 'repository' => 'traefik', 'tag' => 'v3.7.4'], TraefikImage::helmValues('traefik:v3.7.4'));
        $this->assertSame(['registry' => 'docker.io', 'repository' => 'traefik', 'tag' => 'v3.7.4'], TraefikImage::helmValues('docker.io/traefik:v3.7.4'));
        // a registry with a port, a version pinned by digest, a digest alone, no tag
        $this->assertSame(['registry' => 'registry.local:5000', 'repository' => 'mirror/traefik', 'tag' => 'v3.7.4@sha256:abc'], TraefikImage::helmValues('registry.local:5000/mirror/traefik:v3.7.4@sha256:abc'));
        $this->assertSame(['registry' => 'localhost:5000', 'repository' => 'traefik', 'digest' => 'sha256:abc'], TraefikImage::helmValues('localhost:5000/traefik@sha256:abc'));
        $this->assertSame(['registry' => 'ghcr.io', 'repository' => 'brf-tech/runr/reqs/traefik'], TraefikImage::helmValues('ghcr.io/brf-tech/runr/reqs/traefik'));
    }

    public function test_set_json_and_version(): void
    {
        $this->assertSame([
            'image.registry' => '"ghcr.io"',
            'image.repository' => '"brf-tech/runr/reqs/traefik"',
            'image.tag' => '"v3.7.4"',
        ], TraefikImage::helmSetJson('ghcr.io/brf-tech/runr/reqs/traefik:v3.7.4'));

        $this->assertSame('v3.7.4', TraefikImage::version('docker.io/traefik:v3.7.4'));
        $this->assertSame('v3.7.4', TraefikImage::version('registry.local:5000/traefik:v3.7.4@sha256:abc'));
        $this->assertSame('', TraefikImage::version('traefik@sha256:abc'));
    }
}
