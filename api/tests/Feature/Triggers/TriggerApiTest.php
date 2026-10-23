<?php

namespace Tests\Feature\Triggers;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsTriggerFixtures;
use Tests\TestCase;

/**
 * A project's trigger list through the API (docs/triggers.md) and the single-trigger fields that
 * mirror its primary trigger for older clients.
 */
class TriggerApiTest extends TestCase
{
    use ActsWithRoles;
    use BuildsTriggerFixtures;
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->userWithRole('owner');
        $this->project = $this->triggerProject($this->triggerRegion());
    }

    private function url(string $suffix = ''): string
    {
        return "/api/projects/{$this->project->id}/triggers{$suffix}";
    }

    public function test_the_list_shows_the_primary_trigger_and_the_plan(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.0.type', 'http')
            ->assertJsonPath('data.0.primary', true)
            ->assertJsonPath('data.0.runs_on', 'service')
            ->assertJsonPath('plan.public', true)
            ->assertJsonPath('plan.gateway', false);
    }

    public function test_the_project_resource_carries_the_trigger_list(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$this->project->id}")
            ->assertOk()
            ->assertJsonPath('data.triggers.0.type', 'http')
            ->assertJsonPath('data.config.trigger_type', 'http');
    }

    public function test_adding_a_webhook_keeps_the_secret_write_only(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')->postJson($this->url(), [
            'type' => 'webhook',
            'name' => 'github',
            'config' => ['verify' => 'github', 'retention_days' => 3],
            'secrets' => ['signing_secret' => 'super-secret-value'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'github')
            ->assertJsonPath('data.runs_on', 'gateway')
            ->assertJsonPath('data.secrets', ['signing_secret'])
            ->assertJsonPath('data.has_signing_secret', true)
            ->assertJsonPath('data.retention_days', 3)
            ->assertJsonPath('requires_deploy', true);
        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
        $this->assertStringContainsString('/api/hooks/central/', (string) $response->json('data.url'));
        $this->assertSame('super-secret-value', ProjectTrigger::query()->where('name', 'github')->sole()->secret('signing_secret'));
    }

    public function test_type_rules_are_enforced(): void
    {
        $as = $this->actingAs($this->owner, 'sanctum');

        $as->postJson($this->url(), ['type' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $as->postJson($this->url(), ['type' => 'webhook', 'config' => ['verify' => 'github']])->assertUnprocessable()->assertJsonValidationErrors('secrets.signing_secret');
        $as->postJson($this->url(), ['type' => 'schedule', 'config' => ['cron' => 'every day']])->assertUnprocessable()->assertJsonValidationErrors('config.cron');
        $as->postJson($this->url(), ['type' => 'http'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $as->postJson($this->url(), ['type' => 'workflow'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $as->postJson($this->url(), ['type' => 'topic', 'config' => ['topic' => 'missing']])->assertUnprocessable()->assertJsonValidationErrors('config.topic');
        $as->postJson($this->url(), ['type' => 'platform_event', 'config' => ['events' => ['deployment.exploded']]])->assertUnprocessable()->assertJsonValidationErrors('config.events.0');
        $as->postJson($this->url(), ['type' => 'queue', 'config' => ['stream' => 'runr.topic.x']])->assertUnprocessable()->assertJsonValidationErrors('config.stream');
        $as->postJson($this->url(), ['type' => 'webhook', 'config' => ['verify' => 'none'], 'secrets' => ['password' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('secrets');
    }

    public function test_redis_backed_types_need_the_region_redis(): void
    {
        $project = $this->triggerProject($this->triggerRegion(redis: false, name: 'noredis'), 'http', 'no-redis-fn');

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$project->id}/triggers", ['type' => 'queue', 'config' => ['stream' => 'jobs']])
            ->assertUnprocessable();
    }

    public function test_a_topic_subscription_must_be_in_the_project_region(): void
    {
        $other = $this->triggerRegion(name: 'other');
        Topic::query()->create(['name' => 'far', 'knative_region_id' => $other->id]);
        Topic::query()->create(['name' => 'near', 'knative_region_id' => $this->project->knative_region_id]);
        $as = $this->actingAs($this->owner, 'sanctum');

        $as->postJson($this->url(), ['type' => 'topic', 'config' => ['topic' => 'far']])->assertUnprocessable()->assertJsonValidationErrors('config.topic');
        $as->postJson($this->url(), ['type' => 'topic', 'config' => ['topic' => 'near']])->assertCreated()->assertJsonPath('data.config.start_from', 'latest');
        $as->postJson($this->url(), ['type' => 'topic', 'name' => 'near-2', 'config' => ['topic' => 'near']])->assertUnprocessable()->assertJsonValidationErrors('config.topic');
    }

    public function test_reordering_makes_another_trigger_primary_and_mirrors_it_into_the_config(): void
    {
        $as = $this->actingAs($this->owner, 'sanctum');
        $as->postJson($this->url(), ['type' => 'queue', 'config' => ['stream' => 'jobs']])->assertCreated();

        $as->patchJson($this->url('/queue'), ['position' => 0])->assertOk()->assertJsonPath('data.primary', true);

        $config = $this->project->fresh()->containerConfig;
        $this->assertSame('queue', $config->trigger_type);
        $this->assertSame('jobs', $config->queue_stream);
        $this->assertSame(['queue', 'http'], $this->project->triggers()->pluck('type')->all());
    }

    public function test_the_single_trigger_api_retypes_the_primary_trigger(): void
    {
        $this->addTrigger($this->project, 'webhook', ['verify' => 'none']);

        $this->project->containerConfig->update(['trigger_type' => 'schedule', 'schedule' => '0 4 * * *']);

        $types = $this->project->triggers()->get()->map(fn ($t) => [$t->type, $t->config])->all();
        $this->assertSame([['schedule', ['cron' => '0 4 * * *']], ['webhook', ['verify' => 'none']]], $types);
    }

    public function test_the_last_trigger_cannot_be_removed_but_others_can(): void
    {
        $as = $this->actingAs($this->owner, 'sanctum');
        $as->deleteJson($this->url('/http'))->assertUnprocessable();

        $as->postJson($this->url(), ['type' => 'webhook', 'config' => ['verify' => 'none']])->assertCreated();
        $as->deleteJson($this->url('/http'))->assertOk();

        $this->assertSame('webhook', $this->project->fresh()->containerConfig->trigger_type);
    }

    public function test_replace_keeps_matching_triggers_and_their_secrets(): void
    {
        $as = $this->actingAs($this->owner, 'sanctum');
        $as->postJson($this->url(), ['type' => 'webhook', 'name' => 'gh', 'config' => ['verify' => 'github'], 'secrets' => ['signing_secret' => 'kept-secret']])->assertCreated();
        $key = ProjectTrigger::query()->where('name', 'gh')->sole()->ingress_key;

        $as->putJson($this->url(), ['triggers' => [
            ['type' => 'webhook', 'name' => 'gh', 'config' => ['verify' => 'github']],
            ['type' => 'schedule', 'name' => 'nightly', 'config' => ['cron' => '0 3 * * *']],
        ]])->assertOk()->assertJsonCount(2, 'data');

        $gh = ProjectTrigger::query()->where('name', 'gh')->sole();
        $this->assertSame($key, $gh->ingress_key);
        $this->assertSame('kept-secret', $gh->secret('signing_secret'));
        $this->assertNull(ProjectTrigger::query()->where('name', 'http')->first());
        $this->assertSame('webhook', $this->project->fresh()->containerConfig->trigger_type);
    }

    public function test_a_viewer_reads_but_does_not_change_triggers(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer, 'sanctum')->getJson($this->url())->assertOk();
        $this->actingAs($viewer, 'sanctum')->postJson($this->url(), ['type' => 'webhook', 'config' => ['verify' => 'none']])->assertForbidden();
    }

    public function test_trigger_types_describe_the_registry(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/trigger-types')
            ->assertOk()
            ->assertJsonFragment(['type' => 'webhook', 'stores_events' => true, 'secrets' => ['signing_secret']])
            ->assertJsonFragment(['type' => 'workflow', 'exclusive' => true])
            ->assertJsonPath('platform_events.0', 'deployment.finished');
    }
}
