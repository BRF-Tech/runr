<?php

namespace Tests\Feature;

use App\Models\ExecutorImage;
use App\Services\FunctionBuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesBuildCluster;
use Tests\TestCase;

/**
 * Dependencies of a Kaniko build are installed for the language version the function runs with:
 * the builder follows the resolved executor image (default version when runtime_version is null),
 * not the raw runtime_version — which built node 22 / python 3.12 dependencies for functions
 * running on node 24 / python 3.14, and "python:2.3-slim" for an ML runtime.
 */
class BuildDependencyImageTest extends TestCase
{
    use FakesBuildCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBuildCluster();
    }

    public function test_python_default_version_builds_deps_in_the_default_executor_image(): void
    {
        $this->submit($this->buildVersion('python', null));

        $this->assertSame(['ghcr.io/brf-tech/exec-python:3.14', 'ghcr.io/brf-tech/exec-python:3.14'], $this->fromLines());
        $this->assertStringContainsString('RUN pip install --no-cache-dir --target=/build/vendor -r /build/code/requirements.txt', $this->dockerfile());
        $this->assertStringContainsString('PYTHONPATH=/app/vendor', $this->dockerfile());
    }

    public function test_python_explicit_version(): void
    {
        $this->submit($this->buildVersion('python', '3.12'));

        $this->assertSame(['ghcr.io/brf-tech/exec-python:3.12', 'ghcr.io/brf-tech/exec-python:3.12'], $this->fromLines());
    }

    public function test_node_default_and_explicit_versions(): void
    {
        $this->submit($this->buildVersion('nodejs', null));
        $this->assertSame(['ghcr.io/brf-tech/exec-nodejs:24.14', 'ghcr.io/brf-tech/exec-nodejs:24.14'], $this->fromLines());
        $this->assertStringContainsString('RUN cd /build/code && npm install --production', $this->dockerfile());

        $this->submit($this->buildVersion('nodejs', '22.22'));
        $this->assertSame(['ghcr.io/brf-tech/exec-nodejs:22.22', 'ghcr.io/brf-tech/exec-nodejs:22.22'], $this->fromLines());
    }

    public function test_ml_runtime_builds_deps_in_its_own_image(): void
    {
        $this->submit($this->buildVersion('pytorch', '2.3'));
        $this->assertSame(['ghcr.io/brf-tech/exec-pytorch:2.3', 'ghcr.io/brf-tech/exec-pytorch:2.3'], $this->fromLines());

        $this->submit($this->buildVersion('pytorch', null));
        $this->assertSame(['ghcr.io/brf-tech/exec-pytorch:2.3', 'ghcr.io/brf-tech/exec-pytorch:2.3'], $this->fromLines());
    }

    public function test_php_composer_resolves_for_the_runtime_php_version(): void
    {
        $this->submit($this->buildVersion('php', null));
        $this->assertSame(['ghcr.io/brf-tech/reqs/composer:latest', 'ghcr.io/brf-tech/exec-php:8.4'], $this->fromLines());
        $this->assertStringContainsString('RUN cd /build/code && composer config platform.php 8.4 && composer install --no-dev --no-interaction', $this->dockerfile());

        $this->submit($this->buildVersion('php', '8.2'));
        $this->assertSame(['ghcr.io/brf-tech/reqs/composer:latest', 'ghcr.io/brf-tech/exec-php:8.2'], $this->fromLines());
        $this->assertStringContainsString('composer config platform.php 8.2 && composer install', $this->dockerfile());
    }

    public function test_install_command_pins_only_a_numeric_php_version(): void
    {
        $this->assertSame('cd /build/code && composer install --no-dev --no-interaction', FunctionBuildService::installCommand('php', null));
        $this->assertSame('cd /build/code && composer install --no-dev --no-interaction', FunctionBuildService::installCommand('php', '8.4-gpu'));
        $this->assertSame('cd /build/code && composer config platform.php 8.3.12 && composer install --no-dev --no-interaction', FunctionBuildService::installCommand('php', '8.3.12'));
    }

    public function test_builder_image_follows_the_executor_record(): void
    {
        $gpu = new ExecutorImage(['runtime' => 'tensorflow', 'version' => '2.16-gpu', 'docker_image' => 'ghcr.io/brf-tech/exec-tensorflow:2.16-gpu']);

        $this->assertSame('ghcr.io/brf-tech/exec-tensorflow:2.16-gpu', FunctionBuildService::builderImage('tensorflow', $gpu));
        $this->assertSame('ghcr.io/brf-tech/reqs/composer:latest', FunctionBuildService::builderImage('php', $gpu));
    }

    public function test_an_unavailable_runtime_version_fails_the_build_submission(): void
    {
        ExecutorImage::where('runtime', 'python')->where('version', '3.12')->update(['eol_at' => now()->subDay()]);

        $this->expectExceptionMessage('Runtime python:3.12 is no longer available.');
        $this->submit($this->buildVersion('python', '3.12'));
    }

    public function test_resolve_returns_the_record_resolve_image_returns(): void
    {
        $this->assertSame('3.14', ExecutorImage::resolve('python')->version);
        $this->assertSame('3.12', ExecutorImage::resolve('python', '3.12')->version);
        $this->assertSame('ghcr.io/brf-tech/exec-nodejs:24.14', ExecutorImage::resolveImage('nodejs'));
        // Unknown version falls back to the default, like resolveImage()
        $this->assertSame('24.14', ExecutorImage::resolve('nodejs', '18.0')->version);
        $this->assertNull(ExecutorImage::resolve('rust'));
    }
}
