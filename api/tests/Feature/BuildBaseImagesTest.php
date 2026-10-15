<?php

namespace Tests\Feature;

use App\Services\Manifests\BuildManifests;
use App\Services\Manifests\ZotManifests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesBuildCluster;
use Tests\TestCase;

/**
 * Kaniko builds take their base images through the internal registry's ghcr.io pull-through
 * cache when the region's Zot has that upstream (downloaded from ghcr.io once per tag, not on
 * every build), keep today's references otherwise, and run a pinned Kaniko release.
 */
class BuildBaseImagesTest extends TestCase
{
    use FakesBuildCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBuildCluster();
        // A region installed or reconciled with this release
        $this->zotConfig = json_encode(ZotManifests::mirrorConfig());
    }

    public function test_builder_and_runtime_images_come_through_the_zot_cache(): void
    {
        $this->submit($this->buildVersion('python', null));
        $this->assertSame([self::ZOT.'/ghcr.io/runrsh/exec-python:3.14', self::ZOT.'/ghcr.io/runrsh/exec-python:3.14'], $this->fromLines());

        $this->submit($this->buildVersion('nodejs', '22.22'));
        $this->assertSame([self::ZOT.'/ghcr.io/runrsh/exec-nodejs:22.22', self::ZOT.'/ghcr.io/runrsh/exec-nodejs:22.22'], $this->fromLines());

        $this->submit($this->buildVersion('php', null));
        $this->assertSame([self::ZOT.'/ghcr.io/runrsh/reqs/composer:latest', self::ZOT.'/ghcr.io/runrsh/exec-php:8.4'], $this->fromLines());

        // Kaniko pulls them over plain HTTP from the in-cluster registry
        $args = $this->created('jobs')['spec']['template']['spec']['containers'][0]['args'];
        $this->assertContains('--insecure-pull', $args);
        $this->assertContains('--insecure-registry='.self::ZOT, $args);
    }

    public function test_go_build_takes_builder_and_runner_through_the_zot_cache(): void
    {
        $this->submit($this->buildVersion('go', null, 'internal', ['main.go' => 'package main']));

        $this->assertSame([self::ZOT.'/ghcr.io/runrsh/exec-go:1.26', self::ZOT.'/ghcr.io/runrsh/reqs/alpine:3.20'], $this->fromLines());
    }

    public function test_zot_without_the_ghcr_upstream_keeps_ghcr_references(): void
    {
        // Not reconciled yet: Docker Hub upstream only
        $old = ZotManifests::mirrorConfig();
        $old['extensions']['sync']['registries'] = [ZotManifests::syncRegistries(false)[1]];
        $this->zotConfig = json_encode($old);

        $this->submit($this->buildVersion('nodejs', null));
        $this->assertSame(['ghcr.io/runrsh/exec-nodejs:24.14', 'ghcr.io/runrsh/exec-nodejs:24.14'], $this->fromLines());

        $this->zotConfig = null;
        $this->submit($this->buildVersion('go', null, 'internal', ['main.go' => 'package main']));
        $this->assertSame(['ghcr.io/runrsh/exec-go:1.26', 'ghcr.io/runrsh/reqs/alpine:3.20'], $this->fromLines());
    }

    public function test_external_registry_keeps_ghcr_references_and_never_reads_zot(): void
    {
        $this->submit($this->buildVersion('python', null, 'external'));

        $this->assertSame(['ghcr.io/runrsh/exec-python:3.14', 'ghcr.io/runrsh/exec-python:3.14'], $this->fromLines());
        $this->assertFalse(collect($this->calls)->contains(fn ($c) => str_contains($c['path'], 'runr-zot-config')));
    }

    public function test_kaniko_image_is_a_pinned_release(): void
    {
        $this->assertMatchesRegularExpression('~^ghcr\.io/osscontainertools/kaniko:v\d+\.\d+\.\d+$~', BuildManifests::KANIKO_IMAGE);
        $this->assertContains(BuildManifests::KANIKO_IMAGE, config('knative.prepull_images'));

        $this->submit($this->buildVersion('python', null));
        $this->assertSame(BuildManifests::KANIKO_IMAGE, $this->created('jobs')['spec']['template']['spec']['containers'][0]['image']);

        $this->submit($this->buildVersion('go', null, 'internal', ['main.go' => 'package main']));
        $this->assertSame(BuildManifests::KANIKO_IMAGE, $this->created('jobs')['spec']['template']['spec']['containers'][0]['image']);
    }
}
