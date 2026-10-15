<?php

namespace Tests\Unit\Images;

use App\Support\Images\RegistryMove;
use PHPUnit\Framework\TestCase;

/**
 * RunR's image namespace move (0.5.1): ghcr.io/runrsh → ghcr.io/brf-tech, by exact prefix only.
 */
class RegistryMoveTest extends TestCase
{
    public function test_a_reference_under_the_old_namespace_moves_with_its_name_and_tag(): void
    {
        $this->assertSame('ghcr.io/brf-tech/runr-wrapper:1.1.0', RegistryMove::ref('ghcr.io/runrsh/runr-wrapper:1.1.0'));
        $this->assertSame('ghcr.io/brf-tech/reqs/busybox:latest', RegistryMove::ref('ghcr.io/runrsh/reqs/busybox:latest'));
        $this->assertSame('ghcr.io/brf-tech/exec-python@sha256:abc', RegistryMove::ref('ghcr.io/runrsh/exec-python@sha256:abc'));
        $this->assertSame('ghcr.io/brf-tech', RegistryMove::ref('ghcr.io/runrsh'), 'the namespace itself (a registry prefix setting)');
    }

    public function test_other_registries_and_names_stay(): void
    {
        foreach ([
            'ghcr.io/brf-tech/runr-agent:latest',
            'docker.io/library/redis:7-alpine',
            'zot.runr-registry.svc:5000/ghcr.io/runrsh/exec-python:3.14', // the region registry's pull-through path
            'registry.example.com/runrsh/exec-python:3.14',                 // a mirror of your own
            'ghcr.io/runrsh-labs/tool:1',
            'ghcr.io/runrshx',
            '',
        ] as $ref) {
            $this->assertSame($ref, RegistryMove::ref($ref), $ref);
        }
        $this->assertNull(RegistryMove::ref(null));
    }

    public function test_references_inside_a_text_move_and_prefixed_ones_stay(): void
    {
        $dockerfile = "FROM ghcr.io/runrsh/reqs/golang:1.26-alpine AS build\nARG BASE=ghcr.io/runrsh/reqs/alpine:3.21\n"
            ."COPY --from=ghcr.io/runrsh/runr-wrapper:1.1.0 /runr-wrapper /w\nFROM zot:5000/ghcr.io/runrsh/exec-go:1.26\n";

        $this->assertSame(
            "FROM ghcr.io/brf-tech/reqs/golang:1.26-alpine AS build\nARG BASE=ghcr.io/brf-tech/reqs/alpine:3.21\n"
            ."COPY --from=ghcr.io/brf-tech/runr-wrapper:1.1.0 /runr-wrapper /w\nFROM zot:5000/ghcr.io/runrsh/exec-go:1.26\n",
            RegistryMove::text($dockerfile),
        );
        $this->assertSame("image: 'ghcr.io/brf-tech/exec-python:{version}'", RegistryMove::text("image: 'ghcr.io/runrsh/exec-python:{version}'"));
        $this->assertSame('spin spin-v2 x io image ghcr.io/brf-tech/containerd-shim-spin:v1', RegistryMove::text('spin spin-v2 x io image ghcr.io/runrsh/containerd-shim-spin:v1'));
        $this->assertSame('ghcr.io/runrsh-labs/x myghcr.io/runrsh/y', RegistryMove::text('ghcr.io/runrsh-labs/x myghcr.io/runrsh/y'));
        $this->assertTrue(RegistryMove::mentions('FROM ghcr.io/runrsh/x'));
        $this->assertFalse(RegistryMove::mentions('FROM ghcr.io/brf-tech/x'));
        $this->assertFalse(RegistryMove::mentions(null));
    }

    public function test_decoded_json_keeps_its_shape(): void
    {
        $value = json_decode('{"image":"ghcr.io/runrsh/hf-serve:1.0.0","env":{},"list":["ghcr.io/runrsh/reqs/curl:latest",1,true,null],"n":1.5}');

        $moved = RegistryMove::value($value);

        $this->assertSame('{"image":"ghcr.io/brf-tech/hf-serve:1.0.0","env":{},"list":["ghcr.io/brf-tech/reqs/curl:latest",1,true,null],"n":1.5}', json_encode($moved, JSON_UNESCAPED_SLASHES));
        $this->assertSame(['a' => ['ghcr.io/brf-tech/x:1']], RegistryMove::value(['a' => ['ghcr.io/runrsh/x:1']]));
    }

    public function test_legacy_maps_back_for_comparing_with_what_an_earlier_release_deployed(): void
    {
        $spec = ['initContainers' => [['name' => 'fixup', 'image' => 'ghcr.io/brf-tech/reqs/busybox:latest']], 'containers' => [['image' => 'quay.io/kata-containers/kata-deploy:3.28.0']]];

        $legacy = RegistryMove::legacy($spec);

        $this->assertSame('ghcr.io/runrsh/reqs/busybox:latest', $legacy['initContainers'][0]['image']);
        $this->assertSame('quay.io/kata-containers/kata-deploy:3.28.0', $legacy['containers'][0]['image']);
        $this->assertSame($spec, RegistryMove::value($legacy));
    }
}
