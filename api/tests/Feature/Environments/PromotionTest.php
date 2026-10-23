<?php

namespace Tests\Feature\Environments;

use App\Events\DeploymentCreated;
use App\Models\Deployment;
use App\Models\EnvironmentPromotion;
use App\Models\ProjectEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\EnvironmentFixtures;
use Tests\TestCase;

/**
 * Promotion (docs/environments.md#promotion): the version an environment runs goes to the next
 * environment — the same version, the same image, the target's own config — after a "what will
 * change" preview; into production (requires approval by default) only once someone else with
 * environments.approve approves it.
 */
class PromotionTest extends TestCase
{
    use ActsWithRoles, EnvironmentFixtures {
        EnvironmentFixtures::caasProject insteadof ActsWithRoles;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The Knative deploy itself is not under test
        Event::fake([DeploymentCreated::class]);
    }

    public function test_the_preview_shows_the_code_and_config_changes_without_values(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false), ['env_vars' => ['GREETING' => 'hello', 'TOKEN' => 'production-secret-value']]);
        $staging = $this->environment($project, 'staging', ['overrides' => ['min_scale' => 0, 'env_vars' => ['GREETING' => 'hi', 'DEBUG' => '1']]]);
        $v1 = $this->caasVersion($project, 1, 'ghcr.io/acme/shop:1.0');
        $v2 = $this->caasVersion($project, 2, 'ghcr.io/acme/shop:1.1');
        $production = $this->liveDeployment($project, $v1);
        $production->forceFill(['config_snapshot' => ['image' => 'ghcr.io/acme/shop:1.0', 'min_scale' => 0, 'env_vars' => ['GREETING' => 'hello'], '_secret_names' => []]])->save();
        $this->liveDeployment($project, $v2, 'active', $staging);

        $response = $this->actingAs($this->userWithRole('owner'), 'sanctum')
            ->getJson("/api/projects/{$project->id}/environments/staging/promote")
            ->assertOk()
            ->assertJsonPath('data.source.environment', 'staging')
            ->assertJsonPath('data.target.environment', 'production')
            ->assertJsonPath('data.code.from.number', 1)
            ->assertJsonPath('data.code.to.number', 2)
            ->assertJsonPath('data.code.to.image', 'ghcr.io/acme/shop:1.1')
            ->assertJsonPath('data.requires_approval', true)
            ->assertJsonPath('data.can_promote', true);

        $config = collect($response->json('data.config'));
        $this->assertSame(['added' => ['TOKEN'], 'removed' => [], 'changed' => []], collect($config->firstWhere('field', 'env_vars'))->only(['added', 'removed', 'changed'])->all());
        $this->assertSame(['from' => 'ghcr.io/acme/shop:1.0', 'to' => 'ghcr.io/acme/shop:1.1'], collect($config->firstWhere('field', 'image'))->only(['from', 'to'])->all());
        $this->assertStringNotContainsString('production-secret-value', $response->getContent(), 'never an env var value');
        $this->assertStringNotContainsString('"hi"', $response->getContent());
    }

    public function test_a_promotion_into_production_waits_for_another_approver(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false));
        $staging = $this->environment($project, 'staging');
        $v1 = $this->caasVersion($project, 1, 'ghcr.io/acme/shop:1.0');
        $v2 = $this->caasVersion($project, 2, 'ghcr.io/acme/shop:1.1');
        $production = $this->liveDeployment($project, $v1);
        $this->liveDeployment($project, $v2, 'active', $staging);
        $owner = $this->userWithRole('owner');
        $developer = $this->userWithRole('developer');

        $promotion = $this->actingAs($developer, 'sanctum')
            ->postJson("/api/projects/{$project->id}/environments/staging/promote", ['message' => 'release 1.1'])
            ->assertAccepted()
            ->assertJsonPath('data.status', EnvironmentPromotion::PENDING_APPROVAL)
            ->assertJsonPath('data.target', 'production')
            ->assertJsonPath('data.version.number', 2)
            ->assertJsonPath('data.image', 'ghcr.io/acme/shop:1.1')
            ->json('data');
        $this->assertSame('active', $production->fresh()->status, 'nothing is deployed before the approval');

        // The requester cannot approve it, a developer has no environments.approve
        $this->actingAs($developer, 'sanctum')->postJson("/api/projects/{$project->id}/promotions/{$promotion['id']}/approve")->assertForbidden();

        $inbox = $this->actingAs($owner, 'sanctum')->getJson('/api/environment-promotions')->assertOk();
        $this->assertSame([$promotion['id']], array_column($inbox->json('data'), 'id'));
        $this->assertTrue($inbox->json('data.0.can_approve'));

        $this->actingAs($owner, 'sanctum')->postJson("/api/projects/{$project->id}/promotions/{$promotion['id']}/approve", ['comment' => 'ok'])
            ->assertOk()
            ->assertJsonPath('data.status', EnvironmentPromotion::DEPLOYING)
            ->assertJsonPath('data.decision_comment', 'ok');

        $row = EnvironmentPromotion::query()->sole();
        $deployment = Deployment::query()->findOrFail($row->deployment_id);
        $this->assertTrue($deployment->isPrimary(), 'into the project\'s own workload');
        $this->assertSame($v2->id, $deployment->version_id, 'the same version, never built again');
        $this->assertFalse($production->fresh()->isLive(), 'it replaces what production ran');

        // The promotion follows its deployment
        $deployment->update(['status' => 'active', 'ready_at' => now()]);
        $this->assertSame(EnvironmentPromotion::DEPLOYED, $row->fresh()->status);

        // Decided once
        $this->actingAs($owner, 'sanctum')->postJson("/api/projects/{$project->id}/promotions/{$promotion['id']}/reject")->assertStatus(409);
    }

    public function test_the_requester_cannot_approve_even_with_the_permission(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false));
        $staging = $this->environment($project, 'staging');
        $this->liveDeployment($project, $this->caasVersion($project, 1), 'active', $staging);
        $owner = $this->userWithRole('owner');

        $id = $this->actingAs($owner, 'sanctum')->postJson("/api/projects/{$project->id}/environments/staging/promote")->assertAccepted()->json('data.id');

        $this->actingAs($owner, 'sanctum')->postJson("/api/projects/{$project->id}/promotions/{$id}/approve")
            ->assertForbidden()
            ->assertJsonPath('code', 'self_approval');
        $this->actingAs($owner, 'sanctum')->postJson("/api/projects/{$project->id}/promotions/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', EnvironmentPromotion::CANCELLED);
    }

    public function test_a_promotion_into_an_environment_without_approval_deploys_at_once(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false));
        $dev = $this->environment($project, 'dev');
        $staging = $this->environment($project, 'staging');
        $v3 = $this->caasVersion($project, 3);
        $this->liveDeployment($project, $v3, 'active', $dev);

        $this->assertSame(['dev', 'staging', 'production'], ProjectEnvironment::pipelineOf($project)->pluck('name')->all());

        $this->actingAs($this->userWithRole('owner'), 'sanctum')
            ->postJson("/api/projects/{$project->id}/environments/dev/promote")
            ->assertAccepted()
            ->assertJsonPath('data.target', 'staging')
            ->assertJsonPath('data.status', EnvironmentPromotion::DEPLOYING);

        $deployment = Deployment::query()->where('environment_id', $staging->id)->sole();
        $this->assertSame($v3->id, $deployment->version_id);
    }

    public function test_a_newer_request_supersedes_one_that_still_waits_and_rejects_are_recorded(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false));
        $staging = $this->environment($project, 'staging');
        $this->liveDeployment($project, $this->caasVersion($project, 1), 'active', $staging);
        $developer = $this->userWithRole('developer');
        $owner = $this->userWithRole('owner');

        $first = $this->actingAs($developer, 'sanctum')->postJson("/api/projects/{$project->id}/environments/staging/promote")->json('data.id');
        $second = $this->actingAs($developer, 'sanctum')->postJson("/api/projects/{$project->id}/environments/staging/promote")->json('data.id');

        $this->assertSame(EnvironmentPromotion::SUPERSEDED, EnvironmentPromotion::query()->find($first)->status);
        $this->actingAs($owner, 'sanctum')->postJson("/api/projects/{$project->id}/promotions/{$second}/reject", ['comment' => 'not today'])
            ->assertOk()
            ->assertJsonPath('data.status', EnvironmentPromotion::REJECTED)
            ->assertJsonPath('data.decision_comment', 'not today');
        $this->assertSame(0, Deployment::query()->whereNull('environment_id')->count());
    }

    public function test_nothing_to_promote_and_the_end_of_the_pipeline_are_refused(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false));
        $this->environment($project, 'staging');
        $owner = $this->userWithRole('owner');

        $this->actingAs($owner, 'sanctum')->postJson("/api/projects/{$project->id}/environments/staging/promote")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'promotion_not_possible');
        $this->actingAs($owner, 'sanctum')->postJson("/api/projects/{$project->id}/environments/production/promote")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_an_image_built_again_after_the_request_is_not_deployed(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false));
        $staging = $this->environment($project, 'staging');
        $version = $this->caasVersion($project, 1, 'ghcr.io/acme/shop:1.0');
        $this->liveDeployment($project, $version, 'active', $staging);
        $developer = $this->userWithRole('developer');
        $owner = $this->userWithRole('owner');

        $id = $this->actingAs($developer, 'sanctum')->postJson("/api/projects/{$project->id}/environments/staging/promote")->json('data.id');
        $version->forceFill(['config_snapshot' => ['image' => 'ghcr.io/acme/shop:1.0-rebuilt']])->save();

        $this->actingAs($owner, 'sanctum')->postJson("/api/projects/{$project->id}/promotions/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', EnvironmentPromotion::FAILED);
        $this->assertSame(0, Deployment::query()->whereNull('environment_id')->count());
    }

    public function test_an_environment_can_skip_the_approval(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false));
        $staging = $this->environment($project, 'staging');
        $this->liveDeployment($project, $this->caasVersion($project, 1), 'active', $staging);
        $owner = $this->userWithRole('owner');

        $this->actingAs($owner, 'sanctum')->patchJson("/api/projects/{$project->id}/environments/production", ['requires_approval' => false])
            ->assertOk()
            ->assertJsonPath('data.requires_approval', false);
        $this->actingAs($this->userWithRole('developer'), 'sanctum')
            ->postJson("/api/projects/{$project->id}/environments/staging/promote")
            ->assertAccepted()
            ->assertJsonPath('data.status', EnvironmentPromotion::DEPLOYING);
    }
}
