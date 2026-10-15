<?php

namespace Tests\Unit\Sandboxes;

use App\Models\KnativeRegion;
use App\Models\SandboxRegionSetting;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Sandboxes\SandboxClassResolver;
use App\Services\Sandboxes\SandboxException;
use Tests\TestCase;

/**
 * Sandbox class choice: installed classes strongest first (Kata fc > clh > dragonball > qemu >
 * gVisor), runc only on the region's opt-in, region and organization allow lists, the runtime
 * manifest's sandbox list, the region default and explicit requests.
 */
class SandboxClassResolverTest extends TestCase
{
    private SandboxClassResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SandboxClassResolver;
    }

    private function region(array $hypervisors = ['qemu', 'clh', 'fc'], bool $gvisor = true, bool $kata = true): KnativeRegion
    {
        return new KnativeRegion([
            'name' => 'eu-1',
            'sandbox_kata_status' => $kata ? 'ready' : 'not_installed',
            'sandbox_kata_hypervisor' => $hypervisors,
            'sandbox_gvisor_status' => $gvisor ? 'ready' : 'not_installed',
        ]);
    }

    private function settings(array $attributes = []): SandboxRegionSetting
    {
        return new SandboxRegionSetting($attributes + ['knative_region_id' => 'r1']);
    }

    private function manifest(array $sandbox): RuntimeManifest
    {
        return new RuntimeManifest(['id' => 'x', 'sandbox' => $sandbox]);
    }

    public function test_installed_classes_strongest_first(): void
    {
        $this->assertSame(['kata-fc', 'kata-clh', 'kata-qemu', 'gvisor'], $this->resolver->installed($this->region()));
        $this->assertSame(['gvisor'], $this->resolver->installed($this->region([], true, false)));
        $this->assertSame([], $this->resolver->installed($this->region([], false, false)));
    }

    public function test_default_is_the_strongest_allowed_class(): void
    {
        $this->assertSame('kata-fc', $this->resolver->resolve(null, $this->region(), $this->settings()));
        $this->assertSame('kata-fc', $this->resolver->resolve('auto', $this->region(), $this->settings()));
        $this->assertSame('gvisor', $this->resolver->resolve(null, $this->region([], true, false), $this->settings()));
    }

    public function test_runc_only_when_the_region_allows_it(): void
    {
        $bare = $this->region([], false, false);

        try {
            $this->resolver->resolve(null, $bare, $this->settings());
            $this->fail('no class must be refused');
        } catch (SandboxException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('class_unavailable', $e->reason);
        }

        $this->assertSame('runc', $this->resolver->resolve(null, $bare, $this->settings(['allow_runc' => true])));
        // runc is the weakest: never the default while a sandbox is installed
        $this->assertSame('kata-fc', $this->resolver->resolve(null, $this->region(), $this->settings(['allow_runc' => true])));
        $this->assertSame('runc', $this->resolver->resolve('runc', $this->region(), $this->settings(['allow_runc' => true])));
    }

    public function test_requesting_runc_without_opt_in_is_refused(): void
    {
        $this->expectException(SandboxException::class);
        $this->resolver->resolve('runc', $this->region(), $this->settings());
    }

    public function test_region_allow_list_and_default(): void
    {
        $settings = $this->settings(['allowed_classes' => ['kata-clh', 'gvisor'], 'default_class' => 'gvisor']);

        $this->assertSame(['kata-clh', 'gvisor'], $this->resolver->allowed($this->region(), $settings));
        $this->assertSame('gvisor', $this->resolver->resolve(null, $this->region(), $settings));
        $this->assertSame('kata-clh', $this->resolver->resolve('kata', $this->region(), $settings));

        $this->expectException(SandboxException::class);
        $this->resolver->resolve('kata-fc', $this->region(), $settings);
    }

    public function test_kata_in_an_allow_list_means_every_hypervisor(): void
    {
        $settings = $this->settings(['allowed_classes' => ['kata']]);

        $this->assertSame(['kata-fc', 'kata-clh', 'kata-qemu'], $this->resolver->allowed($this->region(), $settings));
    }

    public function test_default_class_that_is_not_allowed_falls_back_to_the_strongest(): void
    {
        $settings = $this->settings(['default_class' => 'kata-dragonball']);

        $this->assertSame('kata-fc', $this->resolver->resolve(null, $this->region(), $settings));
    }

    public function test_organization_allow_list_narrows_the_region(): void
    {
        $this->assertSame(['gvisor'], $this->resolver->allowed($this->region(), $this->settings(), ['gvisor']));
        $this->assertSame('gvisor', $this->resolver->resolve(null, $this->region(), $this->settings(), ['gvisor', 'runc']));
        // runc for the organization still needs the region's opt-in
        $this->assertSame([], $this->resolver->allowed($this->region([], false, false), $this->settings(), ['runc']));
    }

    public function test_runtime_manifest_sandbox_list_is_respected(): void
    {
        $gvisorOnly = $this->manifest(['runc', 'gvisor']);

        $this->assertSame('gvisor', $this->resolver->resolve(null, $this->region(), $this->settings(), null, $gvisorOnly));

        $this->expectException(SandboxException::class);
        $this->resolver->resolve('kata', $this->region(), $this->settings(), null, $gvisorOnly);
    }

    public function test_effective_default_is_null_without_classes(): void
    {
        $this->assertNull($this->resolver->effectiveDefault($this->region([], false, false), $this->settings()));
        $this->assertSame('kata-fc', $this->resolver->effectiveDefault($this->region(), $this->settings()));
    }

    public function test_rank(): void
    {
        $classes = ['runc', 'gvisor', 'kata-qemu', 'kata-fc', 'kata-clh'];
        usort($classes, fn ($a, $b) => SandboxClassResolver::rank($a) <=> SandboxClassResolver::rank($b));

        $this->assertSame(['kata-fc', 'kata-clh', 'kata-qemu', 'gvisor', 'runc'], $classes);
    }
}
