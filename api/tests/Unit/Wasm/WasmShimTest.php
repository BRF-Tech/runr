<?php

namespace Tests\Unit\Wasm;

use App\Services\Wasm\WasmShim;
use PHPUnit\Framework\TestCase;

/**
 * The shims RunR installs: RunR's Spin shim image, pinned release artifacts, handlers,
 * RuntimeClasses, node labels and the script lines the node DaemonSets read.
 */
class WasmShimTest extends TestCase
{
    public function test_the_catalog(): void
    {
        $this->assertSame(['spin', 'wasmtime'], WasmShim::IDS);
        $this->assertSame(['wasmtime-spin-v2', 'wasmtime-v1'], WasmShim::runtimeClassNames());

        $spin = WasmShim::get('spin');
        $this->assertSame(['containerd-shim-spin-v2', 'io.containerd.spin.v2', 'spin-runr', 'wasmtime-spin-v2'],
            [$spin->binary, $spin->runtimeType, $spin->handler, $spin->runtimeClass]);
        $this->assertSame('v0.26.0-runr.1', $spin->version);
        $this->assertSame('runr.dev/wasm-spin', $spin->nodeLabel());

        $wasmtime = WasmShim::get('wasmtime');
        $this->assertSame(['containerd-shim-wasmtime-v1', 'io.containerd.wasmtime.v1', 'wasmtime-runr', 'wasmtime-v1'],
            [$wasmtime->binary, $wasmtime->runtimeType, $wasmtime->handler, $wasmtime->runtimeClass]);
    }

    public function test_release_artifacts_are_pinned_by_version_and_sha256(): void
    {
        $wasmtime = WasmShim::get('wasmtime');
        $this->assertNull($wasmtime->image);
        foreach (['x86_64', 'aarch64'] as $arch) {
            $artifact = $wasmtime->artifacts[$arch];
            $this->assertMatchesRegularExpression('#^https://github\.com/[^ ]+'.preg_quote($wasmtime->version, '#').'/[^ ]+'.$arch.'[^ ]*\.tar\.gz$#', $artifact['url'], $arch);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $artifact['sha256'], $arch);
        }
        // the digest runwasi publishes with the release
        $this->assertSame('a9b1215ee670f11414c8fd8e970b52679085929c8ec73a0fe6ed9cf215cf9ba1', $wasmtime->artifacts['x86_64']['sha256']);
    }

    public function test_the_spin_shim_is_runrs_build_from_its_image(): void
    {
        $spin = WasmShim::get('spin');

        // runtime/wasm-shim-spin: containerd-shim-spin v0.26.0 with the env of Linux containers fixed
        $this->assertSame('ghcr.io/runrsh/containerd-shim-spin:v0.26.0-runr.1', $spin->image);
        $this->assertStringEndsWith(':'.$spin->version, $spin->image, 'the image tag is the shim version');
        $this->assertSame([], $spin->artifacts);
        $this->assertSame(['x86_64', 'aarch64'], $spin->arches);

        // the build file carries the same version and the upstream tag it is built from
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 4).'/runtime/wasm-shim-spin/Dockerfile');
        $this->assertStringContainsString('ARG VERSION='.$spin->version, $dockerfile);
        $this->assertStringContainsString('ghcr.io/runrsh/containerd-shim-spin:'.$spin->version, $dockerfile);
        $this->assertStringContainsString('ARG SHIM_SPIN_COMMIT=922760061d91bacd455c5824b8143dec2a372515', $dockerfile);
    }

    public function test_runtime_class_lookup(): void
    {
        $this->assertSame('spin', WasmShim::forRuntimeClass('wasmtime-spin-v2')?->id);
        $this->assertSame('wasmtime', WasmShim::forRuntimeClass('wasmtime-v1')?->id);
        $this->assertNull(WasmShim::forRuntimeClass('wasmtime-spin'));
        $this->assertNull(WasmShim::forRuntimeClass('gvisor'));
        $this->assertNull(WasmShim::forRuntimeClass(null));
        $this->assertTrue(WasmShim::isSupported('spin'));
        $this->assertFalse(WasmShim::isSupported('wasmedge'));

        $this->expectException(\InvalidArgumentException::class);
        WasmShim::get('wasmedge');
    }

    public function test_architectures(): void
    {
        foreach (WasmShim::all() as $shim) {
            $this->assertTrue($shim->supportsArch('amd64'), $shim->id);
            $this->assertTrue($shim->supportsArch('arm64'), $shim->id);
            $this->assertFalse($shim->supportsArch('s390x'), $shim->id);
        }
        $this->assertSame('x86_64', WasmShim::unameArch('amd64'));
        $this->assertSame('aarch64', WasmShim::unameArch('arm64'));
    }

    public function test_the_script_line_has_eight_fields_in_order(): void
    {
        $wasmtime = WasmShim::get('wasmtime');
        $this->assertSame([
            'wasmtime', 'containerd-shim-wasmtime-v1', 'wasmtime-runr', 'io.containerd.wasmtime.v1',
            $wasmtime->artifacts['x86_64']['url'], $wasmtime->artifacts['x86_64']['sha256'],
            $wasmtime->artifacts['aarch64']['url'], $wasmtime->artifacts['aarch64']['sha256'],
        ], explode(' ', $wasmtime->scriptLine()));

        // an image shim: "image <image>" for each architecture (one multi-arch image)
        $spin = WasmShim::get('spin');
        $this->assertSame([
            'spin', 'containerd-shim-spin-v2', 'spin-runr', 'io.containerd.spin.v2',
            'image', 'ghcr.io/runrsh/containerd-shim-spin:v0.26.0-runr.1', 'image', 'ghcr.io/runrsh/containerd-shim-spin:v0.26.0-runr.1',
        ], explode(' ', $spin->scriptLine()));
        $this->assertSame(['id', 'name', 'version', 'runtime_class', 'handler', 'description'], array_keys($spin->toArray()));
    }
}
