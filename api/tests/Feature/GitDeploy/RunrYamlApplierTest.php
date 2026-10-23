<?php

namespace Tests\Feature\GitDeploy;

use App\Models\ContainerConfig;
use App\Models\Project;
use App\Models\XaasConfig;
use App\Services\GitDeploy\RunrYaml;
use App\Services\GitDeploy\RunrYamlApplier;
use App\Services\InternalNetwork\InternalNetworkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\GitDeployFixtures;
use Tests\TestCase;

/**
 * A pushed runr.yaml applied to the project with the Edit dialog's validation: valid keys
 * change the config, invalid ones and identity keys (type, runtime, region) only warn.
 */
class RunrYamlApplierTest extends TestCase
{
    use ActsWithRoles, GitDeployFixtures, RefreshDatabase;

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

    public function test_scale_schedule_sets_and_turns_off_the_timed_warmer(): void
    {
        $project = $this->faasProject($this->demoRegion());

        $result = $this->apply($project, <<<'YAML'
scale:
  schedule:
    - { cron: "0  9 * * 1-5", min: 3 }
    - { cron: "0 18 * * 1-5", min: 0 }
YAML);

        $rows = [['cron' => '0 9 * * 1-5', 'min_scale' => 3], ['cron' => '0 18 * * 1-5', 'min_scale' => 0]];
        $this->assertSame($rows, $project->faasConfig()->first()->scale_schedule);
        $this->assertSame($rows, $result['applied']['scale_schedule']);

        // The same rows again change nothing
        $this->assertArrayNotHasKey('scale_schedule', $this->apply($project->fresh(['faasConfig']), "scale:\n  schedule:\n    - { cron: \"0 9 * * 1-5\", min: 3 }\n    - { cron: \"0 18 * * 1-5\", min: 0 }\n")['applied']);

        $this->apply($project->fresh(['faasConfig']), "scale:\n  schedule: []\n");
        $this->assertNull($project->faasConfig()->first()->scale_schedule);
    }

    public function test_an_invalid_schedule_only_warns(): void
    {
        $project = $this->faasProject($this->demoRegion());

        $cases = [
            "scale:\n  schedule:\n    - { cron: \"@daily\", min: 1 }\n" => 'runr.yaml scale_schedule:',
            "scale:\n  schedule:\n    - { cron: \"0 9 * * *\", min: 6 }\n" => 'runr.yaml scale_schedule:',
            "scale:\n  max: 2\n  schedule:\n    - { cron: \"0 9 * * *\", min: 3 }\n" => 'runr.yaml scale_schedule:',
        ];
        foreach ($cases as $yaml => $warning) {
            $result = $this->apply($project->fresh(['faasConfig']), $yaml);
            $this->assertStringContainsString($warning, implode("\n", $result['warnings']), $yaml);
            $this->assertNull($project->faasConfig()->first()->scale_schedule, $yaml);
        }

        // A lower max under a stored schedule's peak
        $project->faasConfig()->update(['max_scale' => 5, 'scale_schedule' => json_encode([['cron' => '0 9 * * *', 'min_scale' => 4]])]);
        $result = $this->apply($project->fresh(['faasConfig']), "scale: { max: 3 }\n");
        $this->assertStringContainsString('keeps more containers warm than scale.max', implode("\n", $result['warnings']));
        $this->assertSame(5, (int) $project->faasConfig()->first()->max_scale);
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

    public function test_the_network_section_applies_for_a_pusher_who_may_change_the_network(): void
    {
        $project = $this->faasProject($this->demoRegion());
        Project::create(['name' => 'shop', 'service_type' => 'caas']);
        $developer = $this->userWithRole('developer');
        $yaml = "network:\n  access: internal\n  callers: [shop, billing.acme, orders, nobody]\n";

        $result = app(RunrYamlApplier::class)->apply($project, RunrYaml::parse($yaml)['settings'], (string) $developer->id);

        $this->assertSame('internal', $project->fresh()->accessMode());
        $this->assertSame(['billing.acme', 'shop.platform'], app(InternalNetworkService::class)->callers($project));
        $this->assertSame('internal', $result['applied']['access_mode']);
        $this->assertEqualsCanonicalizing(['shop.platform', 'billing.acme'], $result['applied']['callers'], 'the project itself needs no entry');
        $this->assertSame(['runr.yaml network.callers: '.__('api.network.caller_unknown', ['caller' => 'nobody'])], $result['warnings']);

        // the list replaces the allow list; the same file again changes nothing
        $result = app(RunrYamlApplier::class)->apply($project->fresh(), RunrYaml::parse("network:\n  callers: [shop]\n")['settings'], (string) $developer->id);
        $this->assertSame(['shop.platform'], app(InternalNetworkService::class)->callers($project));
        $this->assertSame(['shop.platform'], $result['applied']['callers']);
        $this->assertSame([], app(RunrYamlApplier::class)->apply($project->fresh(), RunrYaml::parse("network:\n  access: internal\n  callers: [shop]\n")['settings'], (string) $developer->id)['applied']);
    }

    public function test_the_network_section_needs_network_manage(): void
    {
        $project = $this->faasProject($this->demoRegion());
        $viewer = $this->userWithRole('viewer');
        $settings = RunrYaml::parse("network:\n  access: internal\n")['settings'];

        foreach ([(string) $viewer->id, null] as $pusher) {
            $result = app(RunrYamlApplier::class)->apply($project, $settings, $pusher);
            $this->assertStringContainsString('network.manage', implode("\n", $result['warnings']));
        }
        $this->assertSame('public', $project->fresh()->accessMode());

        // ranges that are none
        $owner = $this->userWithRole('owner');
        $result = app(RunrYamlApplier::class)->apply($project, RunrYaml::parse("network:\n  access: allowlist\n  allow_cidrs: [not-an-ip]\n")['settings'], (string) $owner->id);
        $this->assertSame('public', $project->fresh()->accessMode());
        $this->assertStringStartsWith('runr.yaml network: ', $result['warnings'][0]);
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

    public function test_the_deploy_section_sets_the_deploy_strategy_without_touching_the_config(): void
    {
        $region = $this->demoRegion();
        $project = Project::create(['name' => 'web', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128, 'timeout_seconds' => 30, 'trigger_type' => 'http']);

        $result = $this->apply($project->fresh(['containerConfig', 'knativeRegion']), <<<'YAML'
deploy:
  strategy: canary
  canary: { steps: [5, 25, 100], interval_minutes: 2, error_rate_threshold: 1, p95_threshold_ms: 0 }
YAML);

        $stored = $project->containerConfig()->first()->deploy_strategy;
        $this->assertSame('canary', $stored['strategy']);
        $this->assertSame([5, 25, 100], $stored['canary']['steps']);
        $this->assertSame(2, $stored['canary']['interval_minutes']);
        $this->assertNull($stored['canary']['p95_threshold_ms'], 'the invalid key is left out');
        $this->assertSame('canary', $result['applied']['deploy_strategy']['strategy']);
        $this->assertStringContainsString('deploy.canary.p95_threshold_ms', implode("\n", $result['warnings']));
    }

    public function test_a_strategy_for_a_schedule_function_only_warns(): void
    {
        $region = $this->demoRegion();
        $project = Project::create(['name' => 'nightly', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128, 'timeout_seconds' => 30, 'trigger_type' => 'schedule', 'schedule' => '0 3 * * *']);

        $result = $this->apply($project->fresh(['containerConfig', 'knativeRegion']), "deploy: { strategy: blue-green, blue_green: { keep_previous_minutes: 5 } }\n");

        $stored = $project->containerConfig()->first()->deploy_strategy;
        $this->assertSame('direct', $stored['strategy'] ?? 'direct');
        $this->assertSame(5, $stored['blue_green']['keep_previous_minutes'], 'the options are kept for later');
        $this->assertStringContainsString('deploy.strategy', implode("\n", $result['warnings']));
    }
}
