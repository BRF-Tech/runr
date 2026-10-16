<?php

namespace Tests\Unit\SupplyChain;

use App\Services\SupplyChain\ImageRef;
use PHPUnit\Framework\TestCase;

class ImageRefTest extends TestCase
{
    public function test_docker_hub_short_names(): void
    {
        $this->assertSame('docker.io/library/nginx:1.27-alpine', ImageRef::parse('nginx:1.27-alpine')->canonical());
        $this->assertSame('docker.io/library/nginx:latest', ImageRef::parse('nginx')->canonical());
        $this->assertSame('docker.io/acme/api:2', ImageRef::parse('acme/api:2')->canonical());
        $this->assertSame('docker.io/library/nginx:1', ImageRef::parse('index.docker.io/library/nginx:1')->canonical());
    }

    public function test_registry_with_port_and_digest(): void
    {
        $digest = 'sha256:'.str_repeat('0f', 32);
        $ref = ImageRef::parse("10.43.0.5:5000/runr-builds/orders-api:v3@{$digest}");

        $this->assertSame('10.43.0.5:5000', $ref->registry);
        $this->assertSame('runr-builds/orders-api', $ref->repository);
        $this->assertSame('v3', $ref->tag);
        $this->assertSame($digest, $ref->digest);
        $this->assertSame("10.43.0.5:5000/runr-builds/orders-api@{$digest}", $ref->canonical());

        $this->assertSame("10.43.0.5:5000/runr-builds/orders-api@{$digest}", ImageRef::parse('10.43.0.5:5000/runr-builds/orders-api:v3')->withDigest($digest));
        $this->assertSame('localhost:5000/x:latest', ImageRef::parse('localhost:5000/x')->canonical());
    }

    public function test_policy_globs_match_tag_and_digest_of_one_repository(): void
    {
        $this->assertSame(
            ['index.docker.io/library/rust:*', 'index.docker.io/library/rust@*'],
            ImageRef::parse('rust:1.82-alpine')->policyGlobs(),
        );
        $this->assertSame(
            ['ghcr.io/brf-tech/runr/wrapper:*', 'ghcr.io/brf-tech/runr/wrapper@*'],
            ImageRef::parse('ghcr.io/brf-tech/runr/wrapper:1.0.0')->policyGlobs(),
        );
    }
}
