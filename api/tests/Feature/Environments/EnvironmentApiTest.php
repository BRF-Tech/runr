<?php

namespace Tests\Feature\Environments;

use App\Models\Deployment;
use App\Models\EnvironmentSecret;
use App\Models\Git\ProjectGitSettings;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectSecret;
use App\Services\Secrets\SecretFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\EnvironmentFixtures;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * Environments of a project through the API (docs/environments.md): created with a name, a
 * region and overrides; the project's own workload is the primary environment "production", last
 * in the pipeline; names, branches and workload names are checked; secrets are per environment
 * and pass-through (their values live in the environment's region and follow it);
 * deleting one removes what it ran. Managing them needs environments.manage, env vars env.edit.
 */
class EnvironmentApiTest extends TestCase
{
    use ActsWithRoles, EnvironmentFixtures {
        EnvironmentFixtures::caasProject insteadof ActsWithRoles;
    }
    use FakesRegionSecrets, RefreshDatabase;

    public function test_an_environment_is_created_before_production_in_the_pipeline(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false));
        $owner = $this->userWithRole('owner');

        $response = $this->actingAs($owner, 'sanctum')->postJson("/api/projects/{$project->id}/environments", [
            'name' => 'staging',
            'git_branch' => 'develop',
            'overrides' => ['min_scale' => 1, 'env_vars' => ['GREETING' => 'hi from staging', 'DEBUG' => '1']],
        ])->assertCreated();

        $this->assertSame('staging', $response->json('data.name'));
        $this->assertSame('shop-staging', $response->json('data.workload_name'));
        $this->assertFalse($response->json('data.requires_approval'));
        $this->assertSame('develop', $response->json('data.git_branch'));
        $this->assertSame(['GREETING' => 'hi from staging', 'DEBUG' => '1'], $response->json('data.overrides.env_vars'));

        $list = $this->actingAs($owner, 'sanctum')->getJson("/api/projects/{$project->id}/environments")->assertOk();
        $this->assertSame(['staging', 'production'], array_column($list->json('data'), 'name'), 'production (the project itself) is last');
        $this->assertTrue($list->json('data.1.is_primary'));
        $this->assertTrue($list->json('data.1.requires_approval'), 'promotions into production need approval by default');
        $this->assertSame('shop', $list->json('data.1.workload_name'));
        $this->assertTrue($list->json('abilities.approve'));
    }

    public function test_names_branches_and_workload_names_are_checked(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false));
        $this->caasProject('shop-qa', $this->region('us', false));
        ProjectGitSettings::for($project)->update(['deploy_branch' => 'main']);
        $owner = $this->userWithRole('owner');
        $create = fn (array $data) => $this->actingAs($owner, 'sanctum')->postJson("/api/projects/{$project->id}/environments", $data);

        $create(['name' => 'Staging!'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $create(['name' => 'production'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $create(['name' => 'pr-12'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $create(['name' => 'trigger'])->assertUnprocessable()->assertJsonValidationErrors('name');
        // shop-qa is another project's Knative Service in the same namespace
        $create(['name' => 'qa'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $create(['name' => 'dev', 'git_branch' => 'main'])->assertUnprocessable()->assertJsonValidationErrors('git_branch');

        $create(['name' => 'dev', 'git_branch' => 'develop'])->assertCreated();
        $create(['name' => 'dev'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $create(['name' => 'test', 'git_branch' => 'develop'])->assertUnprocessable()->assertJsonValidationErrors('git_branch');
        $create(['name' => 'test', 'knative_region_id' => '01JNOSUCHREGION00000000000'])->assertUnprocessable()->assertJsonValidationErrors('knative_region_id');
    }

    public function test_an_environment_can_run_in_another_region(): void
    {
        $eu = $this->region('eu', false);
        $us = $this->region('us', false);
        $project = $this->caasProject('shop', $eu);

        $response = $this->actingAs($this->userWithRole('owner'), 'sanctum')
            ->postJson("/api/projects/{$project->id}/environments", ['name' => 'us', 'knative_region_id' => $us->id])
            ->assertCreated();

        $this->assertSame($us->id, $response->json('data.knative_region_id'));
        $this->assertSame('us', $response->json('data.region.name'));
        $this->assertTrue($response->json('data.own_region'));
    }

    public function test_managing_needs_environments_manage_and_env_vars_need_env_edit(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false));
        $this->userWithRole('owner');

        $viewer = $this->userWithRole('viewer');
        $this->actingAs($viewer, 'sanctum')->getJson("/api/projects/{$project->id}/environments")->assertOk();
        $this->actingAs($viewer, 'sanctum')->postJson("/api/projects/{$project->id}/environments", ['name' => 'dev'])->assertForbidden();

        $this->customRole('env-manager', ['environments.manage']);
        $manager = $this->userWithRole('env-manager');
        $this->actingAs($manager, 'sanctum')->postJson("/api/projects/{$project->id}/environments", [
            'name' => 'dev', 'overrides' => ['env_vars' => ['A' => 'b']],
        ])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->postJson("/api/projects/{$project->id}/environments", [
            'name' => 'dev', 'overrides' => ['min_scale' => 0],
        ])->assertCreated();

        // The developer role creates environments and promotes, but does not approve
        $developer = $this->userWithRole('developer');
        $this->actingAs($developer, 'sanctum')->getJson("/api/projects/{$project->id}/environments")
            ->assertJsonPath('abilities.manage', true)
            ->assertJsonPath('abilities.promote', true)
            ->assertJsonPath('abilities.approve', false);
    }

    public function test_env_var_values_are_shown_with_env_view_only(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false));
        $this->environment($project, 'staging', ['overrides' => ['env_vars' => ['TOKEN' => 'staging-value']]]);
        $this->userWithRole('owner');

        $this->customRole('looker', ['projects.view']);
        $looker = $this->userWithRole('looker');
        $this->actingAs($looker, 'sanctum')->getJson("/api/projects/{$project->id}/environments/staging")
            ->assertOk()
            ->assertJsonPath('data.overrides.env_vars', null)
            ->assertJsonPath('data.overrides.env_vars_hidden', true);
    }

    public function test_secrets_are_per_environment_over_the_projects_and_live_in_its_region(): void
    {
        $this->fakeRegionSecrets();
        $region = $this->region('eu');
        $project = $this->caasProject('shop', $region);
        ProjectSecret::query()->create(['project_id' => $project->id, 'name' => 'API_KEY', 'value' => 'production-key']);
        ProjectSecret::query()->create(['project_id' => $project->id, 'name' => 'SENTRY_DSN', 'value' => 'dsn']);
        $this->environment($project, 'staging');
        $owner = $this->userWithRole('owner');
        $url = "/api/projects/{$project->id}/environments/staging/secrets";

        $this->actingAs($owner, 'sanctum')->putJson("{$url}/API_KEY", ['value' => 'staging-key'])
            ->assertCreated()
            ->assertJsonMissingPath('data.value')
            ->assertJsonPath('data.stored_in', 'region')
            ->assertJsonPath('data.version', 1);

        // Pass-through: the value is in the environment's own Secret in its region; RunR's row has
        // no value, only a keyed fingerprint
        $this->assertSame(['API_KEY' => 'staging-key'], $this->regionSecrets->values($region, 'shop-staging-secret-env'));
        $this->assertNull($this->regionSecrets->values($region, 'shop-secret-env'), 'the project\'s Secret is not touched');
        $this->assertFalse(Schema::hasColumn('environment_secrets', 'value'));
        $row = EnvironmentSecret::query()->sole();
        $this->assertTrue(SecretFingerprint::matches('staging-key', $row->fingerprint));
        $this->assertStringNotContainsString('staging-key', (string) json_encode($row->getAttributes()));

        $this->actingAs($owner, 'sanctum')->putJson("{$url}/API_KEY", ['value' => 'rotated'])
            ->assertOk()
            ->assertJsonPath('data.version', 2);
        $this->assertSame(['API_KEY' => 'rotated'], $this->regionSecrets->values($region, 'shop-staging-secret-env'));

        $list = $this->actingAs($owner, 'sanctum')->getJson($url)->assertOk();
        $this->assertSame([['API_KEY', 'environment'], ['SENTRY_DSN', 'project']], array_map(fn ($s) => [$s['name'], $s['scope']], $list->json('data')));
        $this->assertStringNotContainsString('rotated', $list->getContent());

        // Nothing of staging runs: the value leaves the region with the variable
        $this->actingAs($owner, 'sanctum')->deleteJson("{$url}/API_KEY")->assertOk();
        $this->assertSame(0, EnvironmentSecret::query()->count());
        $this->assertSame([], $this->regionSecrets->values($region, 'shop-staging-secret-env'));
        $this->actingAs($owner, 'sanctum')->deleteJson("{$url}/API_KEY")->assertNotFound();
    }

    public function test_an_environment_secret_needs_a_region_cluster_that_answers(): void
    {
        $this->fakeRegionSecrets();
        $owner = $this->userWithRole('owner');

        $demo = $this->caasProject('shop', $this->region('demo', false));
        $this->environment($demo, 'staging');
        $this->actingAs($owner, 'sanctum')->putJson("/api/projects/{$demo->id}/environments/staging/secrets/API_KEY", ['value' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('value');

        $project = $this->caasProject('cart', $this->region('eu'));
        $this->environment($project, 'staging');
        $this->regionSecrets->unreachable = true;
        $this->actingAs($owner, 'sanctum')->putJson("/api/projects/{$project->id}/environments/staging/secrets/API_KEY", ['value' => 'x'])
            ->assertStatus(503)
            ->assertJsonPath('reason', 'region_unreachable');
        $this->assertSame(0, EnvironmentSecret::query()->count(), 'nothing changed');
    }

    public function test_an_environment_s_own_values_follow_it_to_another_region(): void
    {
        $this->fakeRegionSecrets();
        $eu = $this->region('eu');
        $us = $this->region('us');
        $project = $this->caasProject('shop', $eu);
        $this->environment($project, 'staging');
        $owner = $this->userWithRole('owner');
        $this->actingAs($owner, 'sanctum')->putJson("/api/projects/{$project->id}/environments/staging/secrets/API_KEY", ['value' => 'staging-key'])->assertCreated();

        $this->actingAs($owner, 'sanctum')->patchJson("/api/projects/{$project->id}/environments/staging", ['knative_region_id' => $us->id])->assertOk();

        $this->assertSame(['API_KEY' => 'staging-key'], $this->regionSecrets->values($us, 'shop-staging-secret-env'));
        $this->assertNull($this->regionSecrets->values($eu, 'shop-staging-secret-env'), 'nothing of it stays in the region it left');
    }

    public function test_an_idle_environment_s_own_values_follow_its_project_to_another_region(): void
    {
        $this->fakeRegionSecrets();
        $eu = $this->region('eu');
        $us = $this->region('us');
        $project = $this->caasProject('shop', $eu);
        $idle = $this->environment($project, 'dev');
        $running = $this->environment($project, 'staging');
        $this->liveDeployment($project, $this->caasVersion($project, 1), 'active', $running);
        $owner = $this->userWithRole('owner');
        $this->actingAs($owner, 'sanctum')->putJson("/api/projects/{$project->id}/environments/dev/secrets/API_KEY", ['value' => 'dev-key'])->assertCreated();
        $this->actingAs($owner, 'sanctum')->putJson("/api/projects/{$project->id}/environments/staging/secrets/API_KEY", ['value' => 'staging-key'])->assertCreated();

        $this->actingAs($owner, 'sanctum')->putJson("/api/projects/{$project->id}", ['knative_region_id' => $us->id])->assertOk();

        $this->assertSame($us->id, $idle->fresh()->regionId());
        $this->assertSame(['API_KEY' => 'dev-key'], $this->regionSecrets->values($us, 'shop-dev-secret-env'));
        $this->assertNull($this->regionSecrets->values($eu, 'shop-dev-secret-env'));
        $this->assertSame($eu->id, $running->fresh()->regionId(), 'what runs stays where it runs');
        $this->assertSame(['API_KEY' => 'staging-key'], $this->regionSecrets->values($eu, 'shop-staging-secret-env'));
        $this->assertNull($this->regionSecrets->values($us, 'shop-staging-secret-env'));
    }

    public function test_deleting_an_environment_removes_its_deployments_and_secrets(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false));
        $staging = $this->environment($project, 'staging');
        $version = $this->caasVersion($project, 1);
        $production = $this->liveDeployment($project, $version);
        $this->liveDeployment($project, $version, 'active', $staging);
        EnvironmentSecret::query()->create(['project_id' => $project->id, 'environment_id' => $staging->id, 'name' => 'API_KEY', 'fingerprint' => SecretFingerprint::of('x')]);
        $owner = $this->userWithRole('owner');

        $this->actingAs($owner, 'sanctum')->deleteJson("/api/projects/{$project->id}/environments/production")->assertUnprocessable();
        $this->actingAs($owner, 'sanctum')->deleteJson("/api/projects/{$project->id}/environments/staging")->assertOk();

        $this->assertNull(ProjectEnvironment::query()->find($staging->id));
        $this->assertSame([$production->id], Deployment::query()->pluck('id')->all(), 'production keeps its deployment');
        $this->assertSame(0, EnvironmentSecret::query()->count());
    }

    public function test_a_running_environment_keeps_its_region_when_the_project_moves(): void
    {
        $eu = $this->region('eu', false);
        $us = $this->region('us', false);
        $project = $this->caasProject('shop', $eu);
        $running = $this->environment($project, 'staging');
        $idle = $this->environment($project, 'dev');
        $this->liveDeployment($project, $this->caasVersion($project, 1), 'active', $running);

        $project->update(['knative_region_id' => $us->id]);

        $this->assertSame($eu->id, $running->fresh()->knative_region_id, 'what runs stays where it runs');
        $this->assertNull($idle->fresh()->knative_region_id, 'an idle environment follows the project');
        $this->assertSame($us->id, $idle->fresh()->regionId());
    }

    public function test_the_project_relation_keeps_meaning_the_project_s_own_deployments(): void
    {
        $project = $this->caasProject('shop', $this->region('eu', false));
        $staging = $this->environment($project, 'staging');
        $version = $this->caasVersion($project, 1);
        $production = $this->liveDeployment($project, $version);
        $other = $this->liveDeployment($project, $version, 'active', $staging);

        /** @var Project $project */
        $project = $project->fresh();
        $this->assertSame([$production->id], $project->deployments()->pluck('id')->all());
        $this->assertSame($production->id, $project->activeDeployment?->id);
        $this->assertSame($production->id, $project->latestDeployment?->id);
        $this->assertEqualsCanonicalizing([$production->id, $other->id], $project->allDeployments()->pluck('id')->all());
        $this->assertSame([$other->id], $staging->deployments()->pluck('id')->all());

        $owner = $this->userWithRole('owner');
        $this->actingAs($owner, 'sanctum')->getJson("/api/projects/{$project->id}/deployments")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.environment', 'production');
        $this->actingAs($owner, 'sanctum')->getJson("/api/projects/{$project->id}/deployments?environment=staging")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $other->id)
            ->assertJsonPath('data.0.environment', 'staging');
        $this->actingAs($owner, 'sanctum')->getJson("/api/projects/{$project->id}/deployments?environment=all")->assertJsonCount(2, 'data');
        $this->actingAs($owner, 'sanctum')->getJson("/api/projects/{$project->id}/deployments?environment=nope")->assertUnprocessable();
    }
}
