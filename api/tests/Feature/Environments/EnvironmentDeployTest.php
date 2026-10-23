<?php

namespace Tests\Feature\Environments;

use App\Events\DeploymentCreated;
use App\Jobs\CheckDeploymentReadyJob;
use App\Models\Deployment;
use App\Models\EnvironmentSecret;
use App\Models\ProjectSecret;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentReservations;
use App\Services\Helpers\RegionCapacity;
use App\Services\KnativeDeployService;
use App\Services\Secrets\OpenBao\OpenBaoAccess;
use App\Services\Secrets\SecretFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\EnvironmentFixtures;
use Tests\Concerns\FakesOpenBao;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * Deploying to an environment (docs/environments.md): its own workload — names derived from
 * <slug>-<name>, its region, the project's config with its overrides, its secrets over the
 * project's — that never touches production's; its pods carry the environment's labels instead of
 * runr/project-id (the binding NetworkPolicies admit them by runr/environment-id).
 */
class EnvironmentDeployTest extends TestCase
{
    use ActsWithRoles, EnvironmentFixtures {
        EnvironmentFixtures::caasProject insteadof ActsWithRoles;
    }
    use FakesOpenBao, FakesRegionSecrets;
    use RefreshDatabase;

    public function test_a_deploy_to_an_environment_leaves_production_running(): void
    {
        Event::fake([DeploymentCreated::class]);
        $project = $this->caasProject('shop', $this->region('eu', false));
        $staging = $this->environment($project, 'staging');
        $version = $this->caasVersion($project, 1);
        $production = $this->liveDeployment($project, $version);
        $olderStaging = $this->liveDeployment($project, $version, 'active', $staging);

        $response = $this->actingAs($this->userWithRole('owner'), 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $version->id, 'environment' => 'staging'])
            ->assertAccepted()
            ->assertJsonPath('environment', 'staging')
            ->assertJsonPath('environment_id', $staging->id);

        $this->assertSame('active', $production->fresh()->status, 'a staging deploy never replaces production');
        $this->assertFalse($olderStaging->fresh()->isLive(), 'it replaces what staging ran');
        $this->assertSame($staging->id, Deployment::query()->find($response->json('id'))->environment_id);
        Event::assertDispatched(DeploymentCreated::class);
    }

    public function test_the_environment_s_workload_has_its_own_names_config_labels_and_secrets(): void
    {
        $region = $this->region('eu');
        $project = $this->caasProject('shop', $region, ['env_vars' => ['GREETING' => 'hello', 'FEATURE_X' => 'on']]);
        // API_KEY: a value RunR still holds; SENTRY_DSN: pass-through, its value only in the project's Secret
        $this->fakeRegionSecrets()->seed($region, 'shop-secret-env', ['SENTRY_DSN' => 'dsn']);
        ProjectSecret::query()->create(['project_id' => $project->id, 'name' => 'API_KEY', 'value' => 'production-key']);
        ProjectSecret::query()->create(['project_id' => $project->id, 'name' => 'SENTRY_DSN', 'source' => ProjectSecret::SOURCE_REGION]);
        $staging = $this->environment($project, 'staging', ['overrides' => [
            'min_scale' => 1, 'scale_to_zero' => false, 'cpu' => '500m',
            'env_vars' => ['GREETING' => 'hi from staging', 'FEATURE_X' => null],
        ]]);
        // staging's own API_KEY: pass-through, its value only in staging's Secret
        $this->regionSecrets->seed($region, 'shop-staging-secret-env', ['API_KEY' => 'staging-key']);
        EnvironmentSecret::query()->create(['project_id' => $project->id, 'environment_id' => $staging->id, 'name' => 'API_KEY', 'fingerprint' => SecretFingerprint::of('staging-key')]);
        $version = $this->caasVersion($project, 1);
        $deployment = $this->liveDeployment($project, $version, 'pending', $staging);
        $this->fakeCluster();

        app(KnativeDeployService::class)->deploy($project, $version, $deployment);

        $applied = $this->applied();
        $this->assertArrayHasKey('Service/shop-staging', $applied, 'the environment\'s own Knative Service');
        $this->assertArrayNotHasKey('Service/shop', $applied, 'production\'s is not touched');
        // the values go straight into the region, never through a manifest (docs/secret-stores.md)
        $this->assertArrayNotHasKey('Secret/shop-staging-secret-env', $applied);

        $service = $applied['Service/shop-staging'];
        $template = $service['spec']['template'];
        $this->assertArrayNotHasKey('runr/project-id', $template['metadata']['labels'], 'binding policies admit pods of the project by it');
        $this->assertSame($staging->id, $template['metadata']['labels']['runr/environment-id']);
        $this->assertSame($project->id, $template['metadata']['labels']['runr/environment-of']);
        $this->assertSame('staging', $service['metadata']['labels']['runr/environment']);
        $this->assertSame('1', $template['metadata']['annotations']['autoscaling.knative.dev/min-scale']);

        $container = $template['spec']['containers'][0];
        $this->assertSame('500m', $container['resources']['limits']['cpu']);
        $env = $this->containerEnv($container);
        $this->assertSame('hi from staging', $env['GREETING']);
        $this->assertArrayNotHasKey('FEATURE_X', $env, 'a null override removes the project\'s variable');
        $this->assertSame('staging', $env['RUNR_ENVIRONMENT']);
        $this->assertSame('secret:shop-staging-secret-env/API_KEY', $env['API_KEY']);
        $this->assertSame('secret:shop-staging-secret-env/SENTRY_DSN', $env['SENTRY_DSN'], 'inherited from the project');
        // the environment's own Secret: its value over the project's, the inherited one copied
        $secret = $this->regionSecrets->values($region, 'shop-staging-secret-env');
        $this->assertSame('staging-key', $secret['API_KEY'] ?? null);
        $this->assertSame('dsn', $secret['SENTRY_DSN'] ?? null);
        $this->assertSame(['SENTRY_DSN' => 'dsn'], $this->regionSecrets->values($region, 'shop-secret-env'), 'production\'s Secret is not touched');

        // The deployment records the config it ran with, names only for secrets
        $deployment->refresh();
        $this->assertSame(Deployment::CONFIG_CURRENT, $deployment->config_source);
        $this->assertSame(['GREETING' => 'hi from staging'], $deployment->config_snapshot['env_vars']);
        $this->assertSame('staging', $deployment->config_snapshot['_environment']);
        $this->assertSame(['API_KEY', 'SENTRY_DSN'], $deployment->config_snapshot['_secret_names']);
        $this->assertSame(500, $deployment->sizing['cpu_m'], 'metering counts the environment\'s size');
        $this->assertSame($region->id, $deployment->sizing['region_id']);
    }

    public function test_inherited_openbao_values_reach_an_environment_in_another_region_through_its_own_secret(): void
    {
        $this->fakeRegionSecrets();
        $this->fakeOpenBao();
        $eu = $this->region('eu');
        $us = $this->region('us');
        $this->openBaoReady($eu);
        $project = $this->caasProject('shop', $eu);
        // The project's values in its region's OpenBao: one staging inherits, one staging overrides
        $path = OpenBaoAccess::projectPath($project);
        $this->openBao($eu)->kv[$path] = ['BAO_KEY' => 'from-bao', 'API_KEY' => 'production-key'];
        ProjectSecret::query()->create(['project_id' => $project->id, 'name' => 'BAO_KEY', 'source' => ProjectSecret::SOURCE_OPENBAO]);
        ProjectSecret::query()->create(['project_id' => $project->id, 'name' => 'API_KEY', 'source' => ProjectSecret::SOURCE_OPENBAO]);
        $staging = $this->environment($project, 'staging', ['knative_region_id' => $us->id]);
        $this->regionSecrets->seed($us, 'shop-staging-secret-env', ['API_KEY' => 'staging-key']);
        EnvironmentSecret::query()->create(['project_id' => $project->id, 'environment_id' => $staging->id, 'name' => 'API_KEY', 'fingerprint' => SecretFingerprint::of('staging-key')]);
        $version = $this->caasVersion($project, 1);
        $deployment = $this->liveDeployment($project, $version, 'pending', $staging);
        $this->fakeCluster();

        app(KnativeDeployService::class)->deploy($project, $version, $deployment);

        $this->assertSame('active', $deployment->fresh()->status, (string) $deployment->fresh()->knative_message);
        $env = $this->containerEnv($this->applied()['Service/shop-staging']['spec']['template']['spec']['containers'][0]);
        $this->assertSame('secret:shop-staging-secret-env/BAO_KEY', $env['BAO_KEY'], 'inherited from the project\'s OpenBao, read from staging\'s own Secret');
        $this->assertSame('secret:shop-staging-secret-env/API_KEY', $env['API_KEY']);
        $this->assertSame(['API_KEY' => 'staging-key', 'BAO_KEY' => 'from-bao'], array_intersect_key(
            (array) $this->regionSecrets->values($us, 'shop-staging-secret-env'), ['API_KEY' => 1, 'BAO_KEY' => 1],
        ));
        // No ExternalSecret of the project's OpenBao identity for staging, and the project's values stay
        $this->assertArrayNotHasKey('ExternalSecret/shop-staging-bao-env', $this->applied());
        $this->assertSame(['BAO_KEY' => 'from-bao', 'API_KEY' => 'production-key'], $this->openBao($eu)->kv[$path]);
        $this->assertSame([], $this->openBao($eu)->policies, 'the project\'s OpenBao policy is not rewritten for staging');
    }

    public function test_an_environment_without_inherited_secrets_gets_only_its_own(): void
    {
        $project = $this->caasProject('shop', $this->region('eu'));
        ProjectSecret::query()->create(['project_id' => $project->id, 'name' => 'DB_PASSWORD', 'value' => 'production']);
        $staging = $this->environment($project, 'staging', ['inherit_secrets' => false]);
        $version = $this->caasVersion($project, 1);
        $deployment = $this->liveDeployment($project, $version, 'pending', $staging);
        $this->fakeCluster();

        app(KnativeDeployService::class)->deploy($project, $version, $deployment);

        $env = $this->containerEnv($this->applied()['Service/shop-staging']['spec']['template']['spec']['containers'][0]);
        $this->assertArrayNotHasKey('DB_PASSWORD', $env);
        $this->assertArrayNotHasKey('Secret/shop-staging-secret-env', $this->applied());
    }

    public function test_a_queue_triggered_environment_reads_its_own_stream(): void
    {
        $project = $this->caasProject('jobs', $this->region('eu', false), ['trigger_type' => 'queue', 'queue_stream' => 'orders']);
        $staging = $this->environment($project, 'staging');
        $view = DeployTarget::environment($project, $staging);

        $this->assertSame('orders-staging', $view->containerConfig->queue_stream);
        $this->assertSame('orders', $project->containerConfig->queue_stream, 'the project keeps its stream');
    }

    public function test_the_readiness_check_and_the_teardown_use_the_environment_s_names(): void
    {
        $project = $this->caasProject('shop', $this->region('eu'));
        $staging = $this->environment($project, 'staging');
        $version = $this->caasVersion($project, 1);
        $deployment = $this->liveDeployment($project, $version, 'pending', $staging);
        $this->fakeCluster();

        (new CheckDeploymentReadyJob($deployment, $project))->handle();

        $this->assertSame('active', $deployment->fresh()->status);
        $this->assertTrue(collect($this->clusterCalls)->contains(fn ($c) => $c['method'] === 'GET' && str_ends_with($c['url'], '/services/shop-staging')));

        $this->clusterCalls = [];
        app(KnativeDeployService::class)->teardown($project, $deployment->fresh());
        $deleted = $this->deleted();
        $this->assertTrue(collect($deleted)->contains(fn ($url) => str_ends_with($url, '/services/shop-staging')));
        $this->assertFalse(collect($deleted)->contains(fn ($url) => str_ends_with($url, '/services/shop')), 'production\'s Knative Service stays');
    }

    public function test_an_environment_reserves_its_size_in_its_region(): void
    {
        $region = $this->region('eu', false);
        $project = $this->caasProject('shop', $region, ['scale_to_zero' => false, 'min_scale' => 1, 'cpu' => '250m', 'memory_mb' => 128]);
        $before = RegionCapacity::usage($region->id);

        $staging = $this->environment($project, 'staging', ['overrides' => ['min_scale' => 2]]);

        $after = RegionCapacity::usage($region->id);
        $demand = EnvironmentReservations::demand($project, $staging);
        $this->assertGreaterThan(0, $demand['hard']['cpu_m']);
        $this->assertSame($before['hard']['cpu_m'] + $demand['hard']['cpu_m'], $after['hard']['cpu_m'], 'the environment holds the region like a project');
        $this->assertSame(2 * EnvironmentReservations::demand($project, $staging->fill(['overrides' => ['min_scale' => 1]]))['hard']['cpu_m'], $demand['hard']['cpu_m']);
    }

    public function test_an_environment_that_does_not_fit_its_region_is_refused(): void
    {
        // A region with room for one always-on pod of the project's size
        $region = $this->region('tiny', false, ['total_cpu_m' => 700, 'total_memory_mb' => 1024]);
        $project = $this->caasProject('shop', $region, ['scale_to_zero' => false, 'min_scale' => 1, 'cpu' => '250m', 'memory_mb' => 128]);

        $this->actingAs($this->userWithRole('owner'), 'sanctum')
            ->postJson("/api/projects/{$project->id}/environments", ['name' => 'staging', 'overrides' => ['min_scale' => 5]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('overrides');
    }
}
