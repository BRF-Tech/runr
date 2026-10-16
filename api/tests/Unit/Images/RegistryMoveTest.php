<?php

namespace Tests\Unit\Images;

use App\Support\Images\RegistryMove;
use PHPUnit\Framework\TestCase;

/**
 * RunR's image namespace move: ghcr.io/runrsh (up to 0.5.0) and RunR's packages directly under
 * ghcr.io/brf-tech (0.5.1) → ghcr.io/brf-tech/runr, a leading "runr-" / "runr/" of the name
 * dropped; by exact prefix only, and never twice.
 */
class RegistryMoveTest extends TestCase
{
    public function test_a_reference_under_the_namespace_up_to_0_5_0_moves_with_its_name_and_tag(): void
    {
        $this->assertSame('ghcr.io/brf-tech/runr/wrapper:1.1.0', RegistryMove::ref('ghcr.io/runrsh/runr-wrapper:1.1.0'));
        $this->assertSame('ghcr.io/brf-tech/runr/reqs/busybox:latest', RegistryMove::ref('ghcr.io/runrsh/reqs/busybox:latest'));
        $this->assertSame('ghcr.io/brf-tech/runr/exec-python@sha256:abc', RegistryMove::ref('ghcr.io/runrsh/exec-python@sha256:abc'));
        $this->assertSame('ghcr.io/brf-tech/runr/api:0.5.0', RegistryMove::ref('ghcr.io/runrsh/runr/api:0.5.0'));
        $this->assertSame('ghcr.io/brf-tech/runr', RegistryMove::ref('ghcr.io/runrsh'), 'the namespace itself (a registry prefix setting)');
    }

    public function test_runrs_packages_of_0_5_1_move_and_the_organizations_other_packages_stay(): void
    {
        foreach ([
            'ghcr.io/brf-tech/runr-wrapper:1.1.0' => 'ghcr.io/brf-tech/runr/wrapper:1.1.0',
            'ghcr.io/brf-tech/runr-agent:latest' => 'ghcr.io/brf-tech/runr/agent:latest',
            'ghcr.io/brf-tech/runr-queue-consumer:latest' => 'ghcr.io/brf-tech/runr/queue-consumer:latest',
            'ghcr.io/brf-tech/runr-supply-chain:1.0.0' => 'ghcr.io/brf-tech/runr/supply-chain:1.0.0',
            'ghcr.io/brf-tech/reqs/traefik:v3.7.4' => 'ghcr.io/brf-tech/runr/reqs/traefik:v3.7.4',
            'ghcr.io/brf-tech/exec-wasm-rust:4.1' => 'ghcr.io/brf-tech/runr/exec-wasm-rust:4.1',
            'ghcr.io/brf-tech/hf-serve:1.1.0-cuda' => 'ghcr.io/brf-tech/runr/hf-serve:1.1.0-cuda',
            'ghcr.io/brf-tech/image-upload:1.0.0' => 'ghcr.io/brf-tech/runr/image-upload:1.0.0',
            'ghcr.io/brf-tech/containerd-shim-spin@sha256:abc' => 'ghcr.io/brf-tech/runr/containerd-shim-spin@sha256:abc',
            'ghcr.io/brf-tech/ai-gpu-caas:e2e' => 'ghcr.io/brf-tech/runr/ai-gpu-caas:e2e',
        ] as $from => $to) {
            $this->assertSame($to, RegistryMove::ref($from), $from);
        }
    }

    public function test_other_registries_names_and_moved_references_stay(): void
    {
        foreach ([
            'ghcr.io/brf-tech/runr/wrapper:1.1.0',                             // moved already
            'ghcr.io/brf-tech/runr/api:0.5.2',
            'ghcr.io/brf-tech/runr',
            'ghcr.io/brf-tech',                                                 // the organization
            'ghcr.io/brf-tech/other-app:1',                                     // another package of it
            'ghcr.io/brf-tech/hf-serve-tools:1',
            'docker.io/library/redis:7-alpine',
            'zot.runr-registry.svc:5000/ghcr.io/runrsh/exec-python:3.14',       // the region registry's pull-through path
            'zot.runr-registry.svc:5000/ghcr.io/brf-tech/exec-python:3.14',
            'registry.example.com/runrsh/exec-python:3.14',                     // a mirror of your own
            'ghcr.io/runrsh-labs/tool:1',
            'ghcr.io/runrshx',
            'ghcr.io/brf-techx/exec-python:3.14',
            '',
        ] as $ref) {
            $this->assertSame($ref, RegistryMove::ref($ref), $ref);
        }
        $this->assertNull(RegistryMove::ref(null));
    }

    public function test_references_inside_a_text_move_and_prefixed_ones_stay(): void
    {
        $dockerfile = "FROM ghcr.io/runrsh/reqs/golang:1.26-alpine AS build\nARG BASE=ghcr.io/brf-tech/reqs/alpine:3.21\n"
            ."COPY --from=ghcr.io/brf-tech/runr-wrapper:1.1.0 /runr-wrapper /w\nFROM zot:5000/ghcr.io/runrsh/exec-go:1.26\n"
            ."FROM ghcr.io/brf-tech/runr/reqs/alpine:3.21\n";

        $this->assertSame(
            "FROM ghcr.io/brf-tech/runr/reqs/golang:1.26-alpine AS build\nARG BASE=ghcr.io/brf-tech/runr/reqs/alpine:3.21\n"
            ."COPY --from=ghcr.io/brf-tech/runr/wrapper:1.1.0 /runr-wrapper /w\nFROM zot:5000/ghcr.io/runrsh/exec-go:1.26\n"
            ."FROM ghcr.io/brf-tech/runr/reqs/alpine:3.21\n",
            RegistryMove::text($dockerfile),
        );
        $this->assertSame("image: 'ghcr.io/brf-tech/runr/exec-python:{version}'", RegistryMove::text("image: 'ghcr.io/runrsh/exec-python:{version}'"));
        $this->assertSame("image: 'ghcr.io/brf-tech/runr/exec-{runtime}:{version}'", RegistryMove::text("image: 'ghcr.io/brf-tech/exec-{runtime}:{version}'"));
        $this->assertSame('spin spin-v2 x io image ghcr.io/brf-tech/runr/containerd-shim-spin:v1', RegistryMove::text('spin spin-v2 x io image ghcr.io/runrsh/containerd-shim-spin:v1'));
        $this->assertSame('ghcr.io/runrsh-labs/x myghcr.io/runrsh/y ghcr.io/brf-tech/tool:1', RegistryMove::text('ghcr.io/runrsh-labs/x myghcr.io/runrsh/y ghcr.io/brf-tech/tool:1'));
        $this->assertTrue(RegistryMove::mentions('FROM ghcr.io/runrsh/x'));
        $this->assertTrue(RegistryMove::mentions('FROM ghcr.io/brf-tech/exec-go:1.26'));
        $this->assertFalse(RegistryMove::mentions('FROM ghcr.io/brf-tech/runr/exec-go:1.26'));
        $this->assertFalse(RegistryMove::mentions('FROM ghcr.io/brf-tech/x'));
        $this->assertFalse(RegistryMove::mentions(null));
    }

    public function test_the_move_is_idempotent(): void
    {
        foreach (['ghcr.io/runrsh/runr-wrapper:1.1.0', 'ghcr.io/runrsh/runr/ui:0.5.0', 'ghcr.io/brf-tech/reqs/redis:7-alpine', 'ghcr.io/runrsh'] as $ref) {
            $moved = RegistryMove::ref($ref);
            $this->assertSame($moved, RegistryMove::ref($moved), $ref);
            $this->assertSame($moved, RegistryMove::text((string) $moved), $ref);
        }
    }

    public function test_decoded_json_keeps_its_shape(): void
    {
        $value = json_decode('{"image":"ghcr.io/runrsh/hf-serve:1.0.0","env":{},"list":["ghcr.io/brf-tech/reqs/curl:latest",1,true,null],"n":1.5}');

        $moved = RegistryMove::value($value);

        $this->assertSame('{"image":"ghcr.io/brf-tech/runr/hf-serve:1.0.0","env":{},"list":["ghcr.io/brf-tech/runr/reqs/curl:latest",1,true,null],"n":1.5}', json_encode($moved, JSON_UNESCAPED_SLASHES));
        $this->assertSame(['a' => ['ghcr.io/brf-tech/runr/x:1']], RegistryMove::value(['a' => ['ghcr.io/runrsh/x:1']]));
    }

    public function test_legacy_maps_back_for_comparing_with_what_an_earlier_release_deployed(): void
    {
        $spec = ['initContainers' => [['name' => 'fixup', 'image' => 'ghcr.io/brf-tech/runr/reqs/busybox:latest'], ['name' => 'wrapper', 'image' => 'ghcr.io/brf-tech/runr/wrapper:1.1.0']],
            'containers' => [['image' => 'quay.io/kata-containers/kata-deploy:3.28.0'], ['image' => 'ghcr.io/brf-tech/runr/api:0.5.2']]];

        $legacy = RegistryMove::legacy($spec);
        $this->assertSame('ghcr.io/runrsh/reqs/busybox:latest', $legacy['initContainers'][0]['image']);
        $this->assertSame('ghcr.io/runrsh/runr-wrapper:1.1.0', $legacy['initContainers'][1]['image']);
        $this->assertSame('quay.io/kata-containers/kata-deploy:3.28.0', $legacy['containers'][0]['image']);
        $this->assertSame('ghcr.io/runrsh/runr/api:0.5.2', $legacy['containers'][1]['image']);
        $this->assertSame($spec, RegistryMove::value($legacy));

        $flat = RegistryMove::legacy($spec, RegistryMove::FLAT);
        $this->assertSame('ghcr.io/brf-tech/reqs/busybox:latest', $flat['initContainers'][0]['image']);
        $this->assertSame('ghcr.io/brf-tech/runr-wrapper:1.1.0', $flat['initContainers'][1]['image']);
        $this->assertSame('ghcr.io/brf-tech/runr/api:0.5.2', $flat['containers'][1]['image'], '0.5.1 published the API under runr/ already');
        $this->assertSame($spec, RegistryMove::value($flat));
    }
}
