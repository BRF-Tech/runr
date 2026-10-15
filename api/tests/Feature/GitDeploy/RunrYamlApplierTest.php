<?php

namespace Tests\Feature\GitDeploy;

use App\Models\ContainerConfig;
use App\Models\Project;
use App\Models\XaasConfig;
use App\Services\GitDeploy\RunrYaml;
use App\Services\GitDeploy\RunrYamlApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GitDeployFixtures;
use Tests\TestCase;

/**
 * A pushed runr.yaml applied to the project with the Edit dialog's validation: valid keys
 * change the config, invalid ones and identity keys (type, runtime, region) only warn.
 */
class RunrYamlApplierTest extends TestCase
{
    use GitDeployFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGitDeploy();
    }

    protected function tearDown(): void
    {
        $this->tearDownGitDeploy();
        parent::tearDown();
    }

    private function apply(Project $project, string $yaml): array
    {
        $parsed = RunrYaml::parse($yaml);

        return app(RunrYamlApplier::class)->apply($project, $parsed['settings']) + ['parse_warnings' => $parsed['warnings']];
    }

    public function test_a_function_project_takes_the_valid_keys(): void
    {
        $project = $this->faasProject($this->demoRegion());

        $result = $this->apply($project, <<<'YAML'
name: orders
type: faas
runtime: python
runtime_version: "3.12"
description: Order intake
handler: app.handle
resources: { cpu: 500m, memory_mb: 256, timeout_seconds: 45 }
scale: { min: 1, max: 4, to_zero: false }
trigger: { type: schedule, schedule: "*/10 * * * *" }
env: { LOG_LEVEL: info }
YAML);

        $config = $project->faasConfig()->first();
        $this->assertSame('app.handle', $config->handler);
        $this->assertSame('500m', $config->cpu);
        $this->assertSame(256, (int) $config->memory_mb);
        $this->assertSame(45, (int) $config->timeout_seconds);
        $this->assertSame([1, 4, false], [(int) $config->min_scale, (int) $config->max_scale, (bool) $config->scale_to_zero]);
        $this->assertSame(['schedule', '*/10 * * * *'], [$config->trigger_type, $config->schedule]);
        $this->assertSame(['GREETING' => 'hello', 'LOG_LEVEL' => 'info'], $config->env_vars);
        $this->assertSame('Order intake', $project->fresh()->description);

        // 3.12 is no executor image of this install: kept, with a warning
        $this->assertSame('3.14', $project->fresh()->runtime_version);
        $this->assertStringContainsString('runtime_version "3.12"', implode("\n", $result['warnings']));
        $this->assertArrayHasKey('cpu', $result['applied']);
        // The deploy history names env vars, never their values
        $this->assertSame(['LOG_LEVEL'], $result['applied']['env_vars']);
    }

    public function test_invalid_values_and_identity_keys_only_warn(): void
    {
        $project = $this->faasProject($this->demoRegion());

        $result = $this->apply($project, <<<'YAML'
type: caas
runtime: nodejs
region: us-9
resources: { cpu: lots, memory_mb: 16 }
trigger: { type: schedule, schedule: "every day" }
scale: { min: 5, max: 2 }
env: { PORT: "1" }
image: nginx
YAML);

        $warnings = implode("\n", $result['warnings']);
        foreach (['type "caas"', 'runtime "nodejs"', 'region "us-9"', 'runr.yaml cpu:', 'runr.yaml memory_mb:', 'runr.yaml schedule:', 'scale.min is above scale.max', 'runr.yaml env_vars:', 'image only applies to container'] as $expected) {
            $this->assertStringContainsString($expected, $warnings);
        }

        $config = $project->faasConfig()->first();
        $this->assertSame('250m', $config->cpu);
        $this->assertSame('http', $config->trigger_type);
        $this->assertSame(['GREETING' => 'hello'], $config->env_vars);
    }

    public function test_an_xaas_project_takes_file_and_function_name(): void
    {
        $region = $this->demoRegion();
        $project = Project::create(['name' => 'script', 'service_type' => 'xaas', 'runtime' => 'python', 'runtime_version' => '3.14']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        XaasConfig::create(['project_id' => $project->id, 'filename' => 'main.py', 'handler' => 'handler', 'memory_mb' => 128, 'cpu' => '250m', 'timeout_seconds' => 30, 'trigger_type' => 'http']);

        $this->apply($project->fresh(['xaasConfig', 'knativeRegion']), "file: job.py\nhandler: run\n");

        $config = $project->xaasConfig()->first();
        $this->assertSame(['job.py', 'run'], [$config->filename, $config->handler]);
    }

    public function test_a_container_project_takes_image_command_and_ports(): void
    {
        $region = $this->demoRegion();
        $project = Project::create(['name' => 'web', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128, 'timeout_seconds' => 30, 'trigger_type' => 'http']);

        $result = $this->apply($project->fresh(['containerConfig', 'knativeRegion']), "image: ghcr.io/acme/web:1.4.2\ncommand: [/bin/server]\nargs: [--port, \"8080\"]\nports: [8080]\nhandler: x\n");

        $config = $project->containerConfig()->first();
        $this->assertSame('ghcr.io/acme/web:1.4.2', $config->image);
        $this->assertSame(['/bin/server'], $config->command);
        $this->assertSame(['--port', '8080'], $config->args);
        $this->assertSame([8080], array_map('intval', $config->ports));
        $this->assertStringContainsString('handler only applies to code projects', implode("\n", $result['warnings']));
    }
}
