<?php

namespace Tests\Unit\Images;

use App\Support\Images\ReleaseImages;
use Tests\TestCase;

/**
 * RunR's region images at the API's own version (ReleaseImages): the region agent, the internal
 * network's authz and the event gateway run at the release's tag, as the API and UI images follow
 * the chart's appVersion; region:reconcile moves the ones that run another tag.
 */
class ReleaseImagesTest extends TestCase
{
    public function test_the_tag_is_the_release_version_of_app_version(): void
    {
        $this->assertSame('0.6.0', ReleaseImages::tag('v0.6.0'), 'the chart and the image set v<version>');
        $this->assertSame('0.6.0', ReleaseImages::tag('0.6.0'));
        $this->assertSame('0.6.1-rc.1', ReleaseImages::tag('v0.6.1-rc.1'));
        foreach (['dev', 'main-1a2b3c4', '', 'latest', 'v0.6'] as $noRelease) {
            $this->assertSame('latest', ReleaseImages::tag($noRelease), "'{$noRelease}' is no release");
        }
        config(['app.version' => 'v0.7.2']);
        $this->assertSame('0.7.2', ReleaseImages::tag(), 'without an argument: config app.version');
        $this->assertSame('ghcr.io/brf-tech/runr/agent:0.6.0', ReleaseImages::ref('agent', 'v0.6.0'));
        $this->assertSame('ghcr.io/brf-tech/runr/queue-consumer:latest', ReleaseImages::ref('queue-consumer', 'dev'));
    }

    public function test_the_config_defaults_follow_app_version(): void
    {
        // config/knative.php reads APP_VERSION when RUNR_AGENT_IMAGE etc. are not set
        $config = function (string $version): array {
            $before = getenv('APP_VERSION');
            putenv("APP_VERSION={$version}");
            $_ENV['APP_VERSION'] = $_SERVER['APP_VERSION'] = $version;
            try {
                return require base_path('config/knative.php');
            } finally {
                putenv($before === false ? 'APP_VERSION' : "APP_VERSION={$before}");
                unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
            }
        };

        $knative = $config('v0.6.0');
        $this->assertSame('ghcr.io/brf-tech/runr/agent:0.6.0', $knative['agent_image']);
        $this->assertSame('ghcr.io/brf-tech/runr/agent:0.6.0', $knative['internal_network']['authz_image']);
        $this->assertSame('ghcr.io/brf-tech/runr/queue-consumer:0.6.0', $knative['queue_consumer_image']);
        $this->assertContains('ghcr.io/brf-tech/runr/queue-consumer:0.6.0', $knative['prepull_images']);
        $this->assertSame('ghcr.io/brf-tech/runr/agent:latest', $config('dev')['agent_image']);
    }

    public function test_another_tag_of_an_image_moves_to_the_configured_one(): void
    {
        config([
            'knative.agent_image' => 'ghcr.io/brf-tech/runr/agent:0.6.0',
            'knative.internal_network.authz_image' => 'ghcr.io/brf-tech/runr/agent:e2e-runr',
            'knative.queue_consumer_image' => 'ghcr.io/brf-tech/runr/queue-consumer:0.6.0',
        ]);
        $digest = '@sha256:'.str_repeat('b', 64);

        $this->assertSame('ghcr.io/brf-tech/runr/agent:0.6.0', ReleaseImages::moved('ghcr.io/brf-tech/runr/agent:latest'));
        $this->assertSame('ghcr.io/brf-tech/runr/agent:0.6.0', ReleaseImages::moved('ghcr.io/brf-tech/runr/agent:0.5.10'));
        $this->assertSame('ghcr.io/brf-tech/runr/agent:0.6.0', ReleaseImages::moved("ghcr.io/brf-tech/runr/agent{$digest}"));
        $this->assertSame('ghcr.io/brf-tech/runr/queue-consumer:0.6.0', ReleaseImages::moved('ghcr.io/brf-tech/runr/queue-consumer'));
        $this->assertSame('zot.runr-registry.svc:5000/ghcr.io/brf-tech/runr/queue-consumer:0.6.0',
            ReleaseImages::moved('zot.runr-registry.svc:5000/ghcr.io/brf-tech/runr/queue-consumer:latest'), 'behind the region registry: the prefix stays');

        // what config gives an image stays: the agent's and the authz's own reference
        $this->assertSame('ghcr.io/brf-tech/runr/agent:0.6.0', ReleaseImages::moved('ghcr.io/brf-tech/runr/agent:0.6.0'));
        $this->assertSame('ghcr.io/brf-tech/runr/agent:e2e-runr', ReleaseImages::moved('ghcr.io/brf-tech/runr/agent:e2e-runr'));
        // other images
        foreach (['ghcr.io/brf-tech/runr/agent-proxy:1', 'ghcr.io/brf-tech/runr/exec-python:3.14', 'ghcr.io/brf-tech/runr/gateway-auth:1.0.0',
            'registry.example.com/runr/agent:1', 'ghcr.io/runrsh/runr-agent:latest', 'ghcr.io/brf-tech/runr/reqs/curl:latest'] as $other) {
            $this->assertSame($other, ReleaseImages::moved($other));
        }
    }

    public function test_an_operators_registry_moves_nothing(): void
    {
        config(['knative.agent_image' => 'registry.example.com/runr/agent:7', 'knative.internal_network.authz_image' => 'registry.example.com/runr/agent:7']);

        $this->assertSame('ghcr.io/brf-tech/runr/agent:latest', ReleaseImages::moved('ghcr.io/brf-tech/runr/agent:latest'));
    }
}
