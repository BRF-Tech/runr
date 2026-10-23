<?php

namespace Tests\Feature\Projects;

use App\Events\DeploymentCreated;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Version;
use App\Services\Environments\DeployTarget;
use App\Services\KnativeDeployService;
use App\Services\Projects\ConfigRestore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\EnvironmentFixtures;
use Tests\TestCase;

/**
 * A rollback restores the code and the config the version ran with (docs/versioning.md#rollback):
 * every deployment records its effective config (config_snapshot); a rollback deploys the older
 * version with it — scale, env vars, trigger, timeout, a CaaS project's command — unless
 * "keep the current configuration". A CaaS project's image is its version's code: an older
 * version always runs its own image, whatever the project is configured with now.
 */
class RollbackRestoresConfigTest extends TestCase
{
    use ActsWithRoles, EnvironmentFixtures {
        EnvironmentFixtures::caasProject insteadof ActsWithRoles;
    }
    use RefreshDatabase;

    /** v1 ran with image 1.0, GREETING=v1 and one replica; the project is now at 1.1 with GREETING=v2, three replicas. */
    private function rolledForward(): array
    {
        $region = $this->region('eu');
        $project = $this->caasProject('shop', $region, ['image' => 'ghcr.io/acme/shop:1.0', 'env_vars' => ['GREETING' => 'v1'], 'scale_to_zero' => false, 'min_scale' => 1, 'max_scale' => 2]);
        $v1 = $this->caasVersion($project, 1, 'ghcr.io/acme/shop:1.0');
        $first = $this->liveDeployment($project, $v1, 'pending');
        $this->fakeCluster();
        app(KnativeDeployService::class)->deploy($project, $v1, $first);

        $project->containerConfig->update(['image' => 'ghcr.io/acme/shop:1.1', 'env_vars' => ['GREETING' => 'v2'], 'min_scale' => 3, 'max_scale' => 5]);
        $v2 = $this->caasVersion($project->fresh(), 2, 'ghcr.io/acme/shop:1.1');
        $first->update(['status' => 'shut_down']);
        $this->liveDeployment($project, $v2, 'active');

        return [$project->fresh(), $v1, $v2, $first->fresh()];
    }

    public function test_every_deployment_records_the_config_it_ran_with(): void
    {
        [, , , $first] = $this->rolledForward();

        $this->assertSame(Deployment::CONFIG_CURRENT, $first->config_source);
        $this->assertSame('ghcr.io/acme/shop:1.0', $first->config_snapshot['image']);
        $this->assertSame(['GREETING' => 'v1'], $first->config_snapshot['env_vars']);
        $this->assertSame(1, (int) $first->config_snapshot['min_scale']);
        $this->assertSame('production', $first->config_snapshot['_environment']);
    }

    public function test_a_rollback_restores_the_image_and_the_config_of_the_deployment(): void
    {
        Event::fake([DeploymentCreated::class]);
        [$project, $v1, , $first] = $this->rolledForward();

        $response = $this->actingAs($this->userWithRole('owner'), 'sanctum')
            ->postJson("/api/projects/{$project->id}/deployments/{$first->id}/rollback")
            ->assertAccepted()
            ->assertJsonPath('config_source', Deployment::CONFIG_RESTORED)
            ->assertJsonPath('config.image', 'ghcr.io/acme/shop:1.0');

        $rollback = Deployment::query()->findOrFail($response->json('id'));
        $this->assertSame($v1->id, $rollback->version_id);

        $this->fakeCluster();
        app(KnativeDeployService::class)->deploy($project, $v1, $rollback);
        $service = $this->applied()['Service/shop'];
        $container = $service['spec']['template']['spec']['containers'][0];
        $this->assertSame('ghcr.io/acme/shop:1.0', $container['image'], 'the image of v1, not the configured 1.1');
        $this->assertSame('v1', $this->containerEnv($container)['GREETING']);
        $this->assertSame('1', $service['spec']['template']['metadata']['annotations']['autoscaling.knative.dev/min-scale']);
        $this->assertSame('2', $service['spec']['template']['metadata']['annotations']['autoscaling.knative.dev/max-scale']);

        // The project's own config is not changed by the rollback
        $this->assertSame('ghcr.io/acme/shop:1.1', $project->containerConfig()->first()->image);
        $this->assertSame(Deployment::CONFIG_RESTORED, $rollback->fresh()->config_source);
    }

    public function test_keeping_the_current_config_still_runs_the_older_image(): void
    {
        Event::fake([DeploymentCreated::class]);
        [$project, $v1, , $first] = $this->rolledForward();

        $response = $this->actingAs($this->userWithRole('owner'), 'sanctum')
            ->postJson("/api/projects/{$project->id}/deployments/{$first->id}/rollback", ['keep_current_config' => true])
            ->assertAccepted()
            ->assertJsonPath('config_source', null);

        $rollback = Deployment::query()->findOrFail($response->json('id'));
        $this->fakeCluster();
        app(KnativeDeployService::class)->deploy($project, $v1, $rollback);
        $service = $this->applied()['Service/shop'];
        $container = $service['spec']['template']['spec']['containers'][0];
        $this->assertSame('ghcr.io/acme/shop:1.0', $container['image'], 'the image is the version\'s code');
        $this->assertSame('v2', $this->containerEnv($container)['GREETING'], 'the config of now');
        $this->assertSame('3', $service['spec']['template']['metadata']['annotations']['autoscaling.knative.dev/min-scale']);
        $this->assertSame(Deployment::CONFIG_CURRENT, $rollback->fresh()->config_source);
    }

    public function test_the_deploy_endpoint_restores_the_newest_recorded_config_of_the_version(): void
    {
        Event::fake([DeploymentCreated::class]);
        [$project, $v1] = $this->rolledForward();

        $response = $this->actingAs($this->userWithRole('owner'), 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v1->id, 'restore_config' => true])
            ->assertAccepted()
            ->assertJsonPath('config_source', Deployment::CONFIG_RESTORED);

        $view = DeployTarget::project($project, Deployment::query()->findOrFail($response->json('id')));
        $this->assertSame(['GREETING' => 'v1'], $view->containerConfig->env_vars);
        $this->assertSame(1, (int) $view->containerConfig->min_scale);
    }

    public function test_a_version_deployed_before_snapshots_restores_the_config_of_its_time(): void
    {
        $project = $this->caasProject('legacy', $this->region('eu', false), ['image' => 'ghcr.io/acme/legacy:2', 'env_vars' => ['MODE' => 'new']]);
        $v1 = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready',
            'config_snapshot' => ['image' => 'ghcr.io/acme/legacy:1', 'env_vars' => ['MODE' => 'old'], 'min_scale' => 0]]);
        Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'ready',
            'config_snapshot' => ['image' => 'ghcr.io/acme/legacy:2', 'env_vars' => ['MODE' => 'new'], 'min_scale' => 0]]);

        $snapshot = app(ConfigRestore::class)->snapshotFor($project, null, $v1);

        $this->assertSame(['MODE' => 'old'], $snapshot['env_vars']);
        $this->assertSame('ghcr.io/acme/legacy:1', $snapshot['image']);
    }

    public function test_a_caas_deploy_of_an_older_version_runs_that_version_s_image(): void
    {
        $project = $this->caasProject('web', $this->region('eu'), ['image' => 'ghcr.io/acme/web:2']);
        $v1 = $this->caasVersion($project, 1, 'ghcr.io/acme/web:1');
        $deployment = $this->liveDeployment($project, $v1, 'pending');
        $this->fakeCluster();

        app(KnativeDeployService::class)->deploy($project, $v1, $deployment);

        $this->assertSame('ghcr.io/acme/web:1', $this->applied()['Service/web']['spec']['template']['spec']['containers'][0]['image']);
        $this->assertInstanceOf(Project::class, DeployTarget::project($project, $deployment));
    }
}
